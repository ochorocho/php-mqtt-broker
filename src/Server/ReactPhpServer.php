<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Server;

use PhpMqtt\Broker\Log\LogSanitizer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Socket\Connection as ReactSocketConnection;
use React\Socket\ConnectionInterface as ReactConnection;
use React\Socket\SecureServer;
use React\Socket\ServerInterface as ReactServer;
use React\Socket\SocketServer;
use React\Socket\TcpServer;

final class ReactPhpServer implements ServerInterface
{
    private ?ReactServer $socket = null;
    private readonly LoopInterface $loop;

    /** @var \SplObjectStorage<ReactConnection, TimerInterface> sockets still in their TLS handshake */
    private \SplObjectStorage $pendingHandshakes;

    /**
     * @param float $handshakeTimeout Seconds a TLS client has to complete its handshake.
     *              The broker only sees a connection once the handshake is done, so
     *              neither its CONNECT deadline nor maxConnections covers a socket that
     *              connects and says nothing: without this it is held forever.
     * @param int $maxPendingHandshakes TLS sockets allowed to be mid-handshake at once.
     */
    public function __construct(
        ?LoopInterface $loop = null,
        private readonly float $handshakeTimeout = 10.0,
        private readonly int $maxPendingHandshakes = 1000,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->loop = $loop ?? Loop::get();
        $this->pendingHandshakes = new \SplObjectStorage();
    }

    public function listen(string $uri, callable $onConnection, array $context = []): void
    {
        if (!str_starts_with($uri, 'tls://')) {
            $this->socket = new SocketServer($uri, $context, $this->loop);
            $this->socket->on('connection', function (ReactConnection $conn) use ($onConnection): void {
                $onConnection(new ReactPhpConnectionStream($conn, $this->loop));
            });

            return;
        }

        // Built by hand rather than through SocketServer, to see each socket before
        // its handshake starts.
        $tcp = new TcpServer(substr($uri, strlen('tls://')), $this->loop, $context['tcp'] ?? []);
        $secure = new SecureServer($tcp, $this->loop, $context['tls'] ?? []);

        $tcp->on('connection', function (ReactConnection $raw): void {
            $this->guardHandshake($raw);
        });

        $secure->on('connection', function (ReactConnection $conn) use ($onConnection): void {
            $this->handshakeSettled($conn);
            $onConnection(new ReactPhpConnectionStream($conn, $this->loop));
        });

        // Debug, not warning: these are client-triggered and arrive as fast as a client
        // cares to fail them.
        $secure->on('error', function (\Throwable $error): void {
            $this->logger->debug('TLS: {error}', ['error' => LogSanitizer::forLog($error->getMessage(), 256)]);
        });

        $this->socket = $secure;
    }

    public function stop(): void
    {
        $this->socket?->close();
        $this->socket = null;
    }

    public function getLoop(): LoopInterface
    {
        return $this->loop;
    }

    /** The bound address, e.g. to learn the port after listening on port 0. */
    public function getAddress(): ?string
    {
        return $this->socket?->getAddress();
    }

    private function guardHandshake(ReactConnection $raw): void
    {
        if ($this->pendingHandshakes->count() >= $this->maxPendingHandshakes) {
            $this->logger->debug('TLS: too many pending handshakes, refusing {address}', [
                'address' => LogSanitizer::address($raw->getRemoteAddress() ?? 'unknown'),
            ]);
            self::abortHandshake($raw);

            return;
        }

        $this->pendingHandshakes[$raw] = $this->loop->addTimer($this->handshakeTimeout, function () use ($raw): void {
            $this->pendingHandshakes->offsetUnset($raw);
            $this->logger->debug('TLS: handshake timeout from {address}', [
                'address' => LogSanitizer::address($raw->getRemoteAddress() ?? 'unknown'),
            ]);
            self::abortHandshake($raw);
        });

        // A failed handshake closes the socket; stop tracking it then too.
        $raw->on('close', function () use ($raw): void {
            $this->handshakeSettled($raw);
        });
    }

    private function handshakeSettled(ReactConnection $connection): void
    {
        if ($this->pendingHandshakes->offsetExists($connection)) {
            $this->loop->cancelTimer($this->pendingHandshakes[$connection]);
            $this->pendingHandshakes->offsetUnset($connection);
        }
    }

    /**
     * End a handshake that is still in progress.
     *
     * Not close(): ReactPHP's handshake watches the raw socket with a listener of its
     * own, removed only when the handshake settles. Closing the socket underneath it
     * leaves the event loop selecting on a dead resource. Shutting it down instead
     * makes the handshake fail on EOF, and ReactPHP cleans up as for any failure.
     */
    private static function abortHandshake(ReactConnection $raw): void
    {
        if ($raw instanceof ReactSocketConnection && is_resource($raw->stream)) {
            @stream_socket_shutdown($raw->stream, STREAM_SHUT_RDWR);
        }
    }
}
