<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Broker;

use PhpMqtt\Broker\Server\ConnectionStream;

/**
 * A ConnectionStream the test drives: push() delivers bytes as if they arrived on
 * the socket, and close() fires the close handlers the way a real socket would.
 */
final class ScriptedStream implements ConnectionStream
{
    /** @var list<string> */
    public array $writes = [];

    public bool $closed = false;

    /** What write() reports: false is ReactPHP saying its buffer is over the soft limit. */
    public bool $writeResult = true;

    /** @var list<callable(string): void> */
    private array $dataHandlers = [];

    /** @var list<callable(): void> */
    private array $closeHandlers = [];

    public function __construct(
        private readonly string $remoteAddress = 'tcp://127.0.0.1:50000',
    ) {
    }

    public function push(string $bytes): void
    {
        foreach ($this->dataHandlers as $handler) {
            $handler($bytes);
        }
    }

    public function write(string $data): bool
    {
        $this->writes[] = $data;

        return $this->writeResult;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        foreach ($this->closeHandlers as $handler) {
            $handler();
        }
    }

    public function onData(callable $handler): void
    {
        $this->dataHandlers[] = $handler;
    }

    public function onClose(callable $handler): void
    {
        $this->closeHandlers[] = $handler;
    }

    public function getRemoteAddress(): string
    {
        return $this->remoteAddress;
    }
}
