<?php

declare(strict_types=1);

namespace PhpMqtt\Broker;

final class Configuration
{
    /**
     * @param int $maxPacketSize Largest accepted inbound packet, in bytes. Also the
     *                           cap on a connection's receive buffer, so a client
     *                           cannot stream unbounded data without completing a packet.
     * @param float $connectTimeout Seconds a connection may stay open before sending
     *                              CONNECT. Bounds what an unauthenticated peer can hold.
     * @param int $minKeepAlive Lower bound applied when a client requests keep alive 0,
     *                          which would otherwise never be reaped.
     * @param int $maxSessions Persistent sessions retained; the oldest idle session is
     *                         evicted beyond this.
     * @param int $maxSessionExpiry Ceiling for a session's expiry interval, in seconds.
     *                              MQTT 3.1.1 sessions have no expiry of their own.
     * @param int $maxPendingMessagesPerSession Offline queue depth per session.
     * @param int $maxRetainedMessages Distinct retained topics.
     * @param int $maxRetainedBytes Total retained payload bytes.
     * @param int $maxSubscriptionsPerClient Active subscriptions per client.
     * @param int $maxTopicLevels Levels ('/'-separated) in a topic or filter.
     * @param int $maxClientIdLength Client identifier length in bytes.
     */
    public function __construct(
        public readonly string $host = '0.0.0.0',
        public readonly int $port = 1883,
        public readonly int $maxConnections = 10000,
        public readonly ?string $tlsCertPath = null,
        public readonly ?string $tlsKeyPath = null,
        public readonly int $maxPacketSize = 1048576,
        public readonly float $connectTimeout = 10.0,
        public readonly int $minKeepAlive = 300,
        public readonly int $maxSessions = 10000,
        public readonly int $maxSessionExpiry = 86400,
        public readonly int $maxPendingMessagesPerSession = 1000,
        public readonly int $maxRetainedMessages = 10000,
        public readonly int $maxRetainedBytes = 67108864,
        public readonly int $maxSubscriptionsPerClient = 1000,
        public readonly int $maxTopicLevels = 32,
        public readonly int $maxClientIdLength = 256,
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
