<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Server;

use PhpMqtt\Broker\Server\ConnectionStream;
use PhpMqtt\Broker\Server\ReactPhpServer;
use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;

/**
 * A real TLS listener on loopback. The broker only sees a connection once its
 * handshake completes, so the handshake itself needs a deadline of its own: without
 * one a socket that connects and says nothing holds a file descriptor forever.
 */
final class ReactPhpServerTest extends TestCase
{
    private static string $certificate;

    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'localhost'], $key);
        self::assertNotFalse($csr);
        $certificate = openssl_csr_sign($csr, null, $key, 1);
        self::assertNotFalse($certificate);

        openssl_x509_export($certificate, $certificatePem);
        openssl_pkey_export($key, $keyPem);

        self::$certificate = sys_get_temp_dir() . '/mqtt-broker-test-' . bin2hex(random_bytes(4)) . '.pem';
        file_put_contents(self::$certificate, $certificatePem . $keyPem);
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$certificate);
    }

    public function testAnIdleTlsSocketIsClosedAfterTheHandshakeTimeout(): void
    {
        $loop = new StreamSelectLoop();
        $server = new ReactPhpServer($loop, handshakeTimeout: 0.2);
        $connected = 0;
        $server->listen('tls://127.0.0.1:0', function () use (&$connected): void {
            $connected++;
        }, $this->tlsContext());

        $client = $this->rawClient($server);
        $this->runFor($loop, 0.6);

        self::assertSame('', (string) fread($client, 1));
        self::assertTrue(feof($client), 'The server must have closed the idle socket');
        self::assertSame(0, $connected);
    }

    public function testACompletedHandshakeIsDeliveredAndOutlivesTheTimeout(): void
    {
        $loop = new StreamSelectLoop();
        $server = new ReactPhpServer($loop, handshakeTimeout: 0.3);
        $streams = [];
        $server->listen('tls://127.0.0.1:0', function (ConnectionStream $stream) use (&$streams): void {
            $streams[] = $stream;
        }, $this->tlsContext());

        $clientClosed = false;
        (new Connector(['tls' => ['verify_peer' => false, 'verify_peer_name' => false]], $loop))
            ->connect('tls://' . $this->addressOf($server))
            ->then(static function (ConnectionInterface $connection) use (&$clientClosed): void {
                $connection->on('close', static function () use (&$clientClosed): void {
                    $clientClosed = true;
                });
            });

        $this->runFor($loop, 0.8);

        self::assertCount(1, $streams);
        self::assertFalse($clientClosed, 'An established connection must not be cut by the handshake deadline');
    }

    public function testPendingHandshakesAreCapped(): void
    {
        $loop = new StreamSelectLoop();
        $server = new ReactPhpServer($loop, handshakeTimeout: 5.0, maxPendingHandshakes: 1);
        $server->listen('tls://127.0.0.1:0', static function (): void {
        }, $this->tlsContext());

        $first = $this->rawClient($server);
        $this->runFor($loop, 0.1);
        $second = $this->rawClient($server);
        $this->runFor($loop, 0.3);

        fread($second, 1);
        self::assertTrue(feof($second), 'A handshake beyond the cap is refused at once');
        fread($first, 1);
        self::assertFalse(feof($first), 'The first is still within its deadline');
    }

    /**
     * @return array{tls: array<string, mixed>}
     */
    private function tlsContext(): array
    {
        return ['tls' => ['local_cert' => self::$certificate]];
    }

    private function addressOf(ReactPhpServer $server): string
    {
        $address = $server->getAddress();
        self::assertNotNull($address);

        return (string) preg_replace('#^[a-z]+://#', '', $address);
    }

    /**
     * @return resource
     */
    private function rawClient(ReactPhpServer $server)
    {
        $client = stream_socket_client('tcp://' . $this->addressOf($server), $errno, $error, 2.0);
        self::assertIsResource($client, $error);
        stream_set_blocking($client, false);

        return $client;
    }

    private function runFor(StreamSelectLoop $loop, float $seconds): void
    {
        $loop->addTimer($seconds, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
    }
}
