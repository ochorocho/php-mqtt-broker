<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Server;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface as ReactConnection;
use React\Socket\SocketServer;

final class ReactPhpServer implements ServerInterface
{
    private ?SocketServer $socket = null;
    private readonly LoopInterface $loop;

    public function __construct(?LoopInterface $loop = null)
    {
        $this->loop = $loop ?? Loop::get();
    }

    public function listen(string $uri, callable $onConnection): void
    {
        $this->socket = new SocketServer($uri, [], $this->loop);

        $this->socket->on('connection', function (ReactConnection $conn) use ($onConnection): void {
            $onConnection(new ReactPhpConnectionStream($conn));
        });
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
}
