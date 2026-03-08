<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Server;

interface ConnectionStream
{
    public function write(string $data): void;

    public function close(): void;

    /**
     * @param callable(string): void $handler
     */
    public function onData(callable $handler): void;

    /**
     * @param callable(): void $handler
     */
    public function onClose(callable $handler): void;

    public function getRemoteAddress(): string;
}
