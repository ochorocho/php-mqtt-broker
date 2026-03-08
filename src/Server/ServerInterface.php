<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Server;

interface ServerInterface
{
    /**
     * @param callable(ConnectionStream): void $onConnection
     */
    public function listen(string $uri, callable $onConnection): void;

    public function stop(): void;

    public function getLoop(): \React\EventLoop\LoopInterface;
}
