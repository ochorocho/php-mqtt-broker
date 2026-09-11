<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Handler;

use PhpMqtt\Broker\Server\ConnectionStream;

/**
 * A ConnectionStream that keeps what was written instead of touching a socket.
 */
final class RecordingStream implements ConnectionStream
{
    /** @var list<string> */
    public array $writes = [];

    public bool $closed = false;

    public function write(string $data): void
    {
        $this->writes[] = $data;
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
        return '127.0.0.1:1883';
    }
}
