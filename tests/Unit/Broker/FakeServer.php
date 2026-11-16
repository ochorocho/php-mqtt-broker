<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Broker;

use PhpMqtt\Broker\Server\ServerInterface;
use React\EventLoop\LoopInterface;

/**
 * A server that never opens a port: connect() hands the broker a ScriptedStream.
 */
final class FakeServer implements ServerInterface
{
    /** @var (callable(\PhpMqtt\Broker\Server\ConnectionStream): void)|null */
    private $onConnection = null;

    public bool $stopped = false;

    public function __construct(
        private readonly FakeLoop $loop = new FakeLoop(),
    ) {
    }

    public function listen(string $uri, callable $onConnection, array $context = []): void
    {
        $this->onConnection = $onConnection;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function getLoop(): LoopInterface
    {
        return $this->loop;
    }

    public function fakeLoop(): FakeLoop
    {
        return $this->loop;
    }

    public function connect(string $remoteAddress = 'tcp://127.0.0.1:50000'): ScriptedStream
    {
        if ($this->onConnection === null) {
            throw new \LogicException('Broker::start() has not been called');
        }

        $stream = new ScriptedStream($remoteAddress);
        ($this->onConnection)($stream);

        return $stream;
    }
}
