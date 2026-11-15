<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Server;

use Evenement\EventEmitter;
use React\Socket\ConnectionInterface;
use React\Stream\WritableStreamInterface;

/**
 * A ReactPHP connection whose peer never reads: end() waits for a flush that does not
 * come, so only close() ends it. That is the stuck socket the broker must not keep.
 */
final class FakeReactConnection extends EventEmitter implements ConnectionInterface
{
    public bool $ended = false;
    public bool $closed = false;

    /** @var list<string> */
    public array $writes = [];

    public function __construct(
        public bool $writeResult = true,
        public bool $flushesOnEnd = false,
    ) {
    }

    public function getRemoteAddress(): ?string
    {
        return 'tcp://127.0.0.1:1';
    }

    public function getLocalAddress(): ?string
    {
        return 'tcp://127.0.0.1:1883';
    }

    public function isReadable(): bool
    {
        return !$this->closed;
    }

    public function pause(): void
    {
    }

    public function resume(): void
    {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function pipe(WritableStreamInterface $dest, array $options = []): WritableStreamInterface
    {
        return $dest;
    }

    public function isWritable(): bool
    {
        return !$this->ended && !$this->closed;
    }

    public function write($data): bool
    {
        $this->writes[] = (string) $data;

        return $this->writeResult;
    }

    public function end($data = null): void
    {
        $this->ended = true;
        if ($this->flushesOnEnd) {
            $this->close();
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->emit('close');
    }
}
