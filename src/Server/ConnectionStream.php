<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Server;

interface ConnectionStream
{
    /**
     * @return bool False when the transport is buffering past its soft limit, i.e. the
     *              peer is not keeping up. The data is still queued; the caller decides
     *              how much backlog to tolerate.
     */
    public function write(string $data): bool;

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
