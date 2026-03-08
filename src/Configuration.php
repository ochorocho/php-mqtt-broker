<?php

declare(strict_types=1);

namespace PhpMqtt\Broker;

final class Configuration
{
    public function __construct(
        public readonly string $host = '0.0.0.0',
        public readonly int $port = 1883,
        public readonly int $maxConnections = 10000,
        public readonly ?string $tlsCertPath = null,
        public readonly ?string $tlsKeyPath = null,
    ) {
    }

    public function getListenUri(): string
    {
        if ($this->tlsCertPath !== null) {
            return sprintf('tls://%s:%d', $this->host, $this->port);
        }

        return sprintf('%s:%d', $this->host, $this->port);
    }
}
