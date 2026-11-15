<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Server;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Socket\ConnectionInterface as ReactConnection;

final class ReactPhpConnectionStream implements ConnectionStream
{
    /** Seconds a graceful close may wait for the write buffer to flush. */
    public const float FORCE_CLOSE_AFTER = 5.0;

    private bool $closing = false;
    private bool $closed = false;
    private ?TimerInterface $forceCloseTimer = null;

    public function __construct(
        private readonly ReactConnection $connection,
        private readonly LoopInterface $loop,
    ) {
        $this->connection->on('close', function (): void {
            $this->closed = true;
            if ($this->forceCloseTimer !== null) {
                $this->loop->cancelTimer($this->forceCloseTimer);
                $this->forceCloseTimer = null;
            }
        });
    }

    public function write(string $data): bool
    {
        return $this->connection->write($data);
    }

    /**
     * End the connection, flushing what is buffered, and shut it hard if that does not
     * finish in time.
     *
     * end() alone waits for the buffer to drain, which a peer that has stopped reading
     * never allows: the socket, its buffer and its connection slot stayed for the life
     * of the process.
     */
    public function close(): void
    {
        if ($this->closing) {
            return;
        }
        $this->closing = true;

        $this->connection->end();

        // end() closes at once when nothing is buffered; otherwise give it a deadline.
        if (!$this->closed) {
            $this->forceCloseTimer = $this->loop->addTimer(self::FORCE_CLOSE_AFTER, function (): void {
                $this->forceCloseTimer = null;
                $this->connection->close();
            });
        }
    }

    public function onData(callable $handler): void
    {
        $this->connection->on('data', $handler);
    }

    public function onClose(callable $handler): void
    {
        $this->connection->on('close', $handler);
    }

    public function getRemoteAddress(): string
    {
        return $this->connection->getRemoteAddress() ?? 'unknown';
    }
}
