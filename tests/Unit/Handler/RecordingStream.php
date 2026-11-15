<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Handler;

use PhpMqtt\Broker\Server\ConnectionStream;
use PhpMqtt\Broker\Server\PeerCertificateStream;

/**
 * A ConnectionStream that keeps what was written instead of touching a socket.
 */
final class RecordingStream implements ConnectionStream, PeerCertificateStream
{
    /** @var list<string> */
    public array $writes = [];

    public bool $closed = false;

    public function __construct(
        private readonly string $remoteAddress = '127.0.0.1:1883',
        private readonly ?string $peerCommonName = null,
    ) {
    }

    public function getPeerCertificateCommonName(): ?string
    {
        return $this->peerCommonName;
    }

    public function write(string $data): bool
    {
        $this->writes[] = $data;

        return true;
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
        return $this->remoteAddress;
    }
}
