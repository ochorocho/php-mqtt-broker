<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Server;

interface ServerInterface
{
    /**
     * @param callable(ConnectionStream): void $onConnection
     * @param array<string, mixed> $context Stream context, e.g. TLS certificate options.
     */
    public function listen(string $uri, callable $onConnection, array $context = []): void;

    public function stop(): void;

    public function getLoop(): \React\EventLoop\LoopInterface;
}
