<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Server;

use React\Socket\ConnectionInterface as ReactConnection;

final class ReactPhpConnectionStream implements ConnectionStream
{
    public function __construct(
        private readonly ReactConnection $connection,
    ) {
    }

    public function write(string $data): void
    {
        $this->connection->write($data);
    }

    public function close(): void
    {
        $this->connection->end();
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
