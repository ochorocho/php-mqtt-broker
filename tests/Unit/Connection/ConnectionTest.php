<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Connection;

use PhpMqtt\Broker\Connection\Connection;
use PhpMqtt\Broker\Protocol\Packet\PingrespPacket;
use PhpMqtt\Broker\Protocol\PacketEncoder;
use PhpMqtt\Broker\Server\ConnectionStream;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;

final class ConnectionTest extends TestCase
{
    private function connection(): Connection
    {
        $stream = new class implements ConnectionStream {
            public function write(string $data): bool
            {
                return true;
            }

            public function close(): void
            {
            }

            public function onData(callable $handler): void
            {
            }

            public function onClose(callable $handler): void
            {
            }

            public function getRemoteAddress(): string
            {
                return '127.0.0.1:1';
            }
        };

        return new Connection($stream, new PacketEncoder(), Loop::get());
    }

    public function testDisconnectHandledIsSeparateFromConnected(): void
    {
        $connection = $this->connection();
        $connection->setConnected(true);

        self::assertFalse($connection->isDisconnectHandled());

        // A connection can stop accepting packets — the receive-maximum path does
        // exactly this before deferring the close — without teardown having run. If
        // these two were the same flag, the later close would skip cleanup entirely.
        $connection->setConnected(false);

        self::assertFalse(
            $connection->isDisconnectHandled(),
            'Marking a connection not-connected must not count as having torn it down',
        );
    }

    public function testDisconnectHandledLatches(): void
    {
        $connection = $this->connection();

        $connection->markDisconnectHandled();
        self::assertTrue($connection->isDisconnectHandled());

        // Idempotent: the keepalive timer and the socket close can both fire.
        $connection->markDisconnectHandled();
        self::assertTrue($connection->isDisconnectHandled());
    }

    public function testNewConnectionStartsDisconnectedAndUntornDown(): void
    {
        $connection = $this->connection();

        self::assertFalse($connection->isConnected());
        self::assertFalse($connection->isDisconnectHandled());
    }

    public function testACongestedConnectionIsClosedPastTheOutboundLimit(): void
    {
        $stream = new CongestedStream();
        $overflowed = false;
        $connection = new Connection(
            stream: $stream,
            encoder: new PacketEncoder(),
            loop: Loop::get(),
            maxOutboundBufferBytes: 100,
            onOverflow: static function () use (&$overflowed): void {
                $overflowed = true;
            },
        );

        // A peer that stops reading leaves every write in the broker's buffer. QoS 0
        // has no flow control of its own, so without a cap that buffer has no end.
        for ($i = 0; $i < 60; $i++) {
            $connection->send(new PingrespPacket());
        }

        self::assertTrue($stream->closed);
        self::assertTrue($overflowed);
        self::assertTrue($connection->isClosed());
        self::assertLessThanOrEqual(51, count($stream->writes), 'Nothing is written once closed');
    }

    public function testAWriteThatDrainsResetsTheCount(): void
    {
        $stream = new CongestedStream();
        $connection = new Connection(
            stream: $stream,
            encoder: new PacketEncoder(),
            loop: Loop::get(),
            maxOutboundBufferBytes: 100,
        );

        for ($i = 0; $i < 200; $i++) {
            // Every tenth write finds the buffer drained below ReactPHP's soft limit.
            $stream->result = $i % 10 === 9;
            $connection->send(new PingrespPacket());
        }

        self::assertFalse($stream->closed);
    }

    public function testRaisingThePacketLimitAfterConnect(): void
    {
        $connection = new Connection(
            stream: new CongestedStream(),
            encoder: new PacketEncoder(),
            loop: Loop::get(),
            maxPacketSize: 16,
        );

        $connection->getPacketStream()->setMaxPacketSize(1024);
        $connection->getPacketStream()->append("\x30\x64" . str_repeat('x', 100));

        self::assertTrue($connection->getPacketStream()->hasCompletePacket());
    }
}

/**
 * A stream that reports backpressure on every write unless told otherwise.
 */
final class CongestedStream implements ConnectionStream
{
    public bool $result = false;
    public bool $closed = false;

    /** @var list<string> */
    public array $writes = [];

    public function write(string $data): bool
    {
        $this->writes[] = $data;

        return $this->result;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function onData(callable $handler): void
    {
    }

    public function onClose(callable $handler): void
    {
    }

    public function getRemoteAddress(): string
    {
        return '127.0.0.1:1';
    }
}
