<?php

declare(strict_types=1);

namespace PhpMqtt\Broker;

use PhpMqtt\Broker\Protocol\PacketStream;

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
     * @param ?string $tlsKeyPassphrase Passphrase for an encrypted private key.
     * @param bool $tlsRequireClientCert Require and verify a client certificate
     *                                   (mutual TLS). Needs $tlsClientCaPath.
     * @param ?string $tlsClientCaPath CA bundle used to verify client certificates.
     * @param ?string $tlsCiphers OpenSSL cipher list. Null uses the PHP default.
     * @param int $tlsMinVersion Minimum protocol, as an OpenSSL crypto-method
     *                           constant. Defaults to TLS 1.2 or better; PHP's own
     *                           default would still negotiate TLS 1.0 on some builds.
     */
    public function __construct(
        public readonly string $host = '0.0.0.0',
        public readonly int $port = 1883,
        public readonly int $maxConnections = 10000,
        public readonly ?string $tlsCertPath = null,
        public readonly ?string $tlsKeyPath = null,
        public readonly int $maxPacketSize = PacketStream::DEFAULT_MAX_PACKET_SIZE,
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
        public readonly ?string $tlsKeyPassphrase = null,
        public readonly bool $tlsRequireClientCert = false,
        public readonly ?string $tlsClientCaPath = null,
        public readonly ?string $tlsCiphers = null,
        public readonly int $tlsMinVersion = STREAM_CRYPTO_METHOD_TLSv1_2_SERVER,
    ) {
    }

    public function isTlsEnabled(): bool
    {
        return $this->tlsCertPath !== null;
    }

    public function getListenUri(): string
    {
        if ($this->isTlsEnabled()) {
            return sprintf('tls://%s:%d', $this->host, $this->port);
        }

        return sprintf('%s:%d', $this->host, $this->port);
    }

    /**
     * Build the stream context for the listening socket.
     *
     * The cert and key used to be accepted here and never passed to the socket, so a
     * tls:// listener bound with no certificate and failed every handshake while the
     * operator believed TLS was on.
     *
     * @return array{tcp?: array<string, mixed>, tls?: array<string, mixed>}
     */
    public function getSocketContext(): array
    {
        if (!$this->isTlsEnabled()) {
            return [];
        }

        $tls = [
            'local_cert' => $this->tlsCertPath,
            'crypto_method' => $this->cryptoMethods(),
            // The server picks from its own cipher order rather than the client's.
            'honor_cipher_order' => true,
            'single_ecdh_use' => true,
            'disable_compression' => true,
        ];

        if ($this->tlsKeyPath !== null) {
            $tls['local_pk'] = $this->tlsKeyPath;
        }

        if ($this->tlsKeyPassphrase !== null) {
            $tls['passphrase'] = $this->tlsKeyPassphrase;
        }

        if ($this->tlsCiphers !== null) {
            $tls['ciphers'] = $this->tlsCiphers;
        }

        if ($this->tlsRequireClientCert) {
            $tls['verify_peer'] = true;
            $tls['verify_peer_name'] = false; // Clients are identified by cert, not hostname.
            $tls['allow_self_signed'] = false;

            if ($this->tlsClientCaPath !== null) {
                $tls['cafile'] = $this->tlsClientCaPath;
            }
        }

        return ['tls' => $tls];
    }

    /**
     * Turn the minimum version into the bitmask of every version at or above it, so
     * setting TLS 1.2 does not also forbid TLS 1.3.
     */
    private function cryptoMethods(): int
    {
        $methods = STREAM_CRYPTO_METHOD_TLSv1_2_SERVER;

        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_SERVER')) {
            $methods |= STREAM_CRYPTO_METHOD_TLSv1_3_SERVER;
        }

        // Only widen below TLS 1.2 when explicitly asked for.
        if ($this->tlsMinVersion & STREAM_CRYPTO_METHOD_TLSv1_1_SERVER) {
            $methods |= STREAM_CRYPTO_METHOD_TLSv1_1_SERVER;
        }

        if ($this->tlsMinVersion & STREAM_CRYPTO_METHOD_TLSv1_0_SERVER) {
            $methods |= STREAM_CRYPTO_METHOD_TLSv1_0_SERVER;
        }

        return $methods;
    }

    /**
     * @throws \RuntimeException when TLS is requested but unusable.
     */
    public function validateTls(): void
    {
        if (!$this->isTlsEnabled()) {
            return;
        }

        // Bind-time failure is the right outcome: a tls:// listener without a usable
        // certificate accepts nothing, and silently serving no one is worse than
        // refusing to start.
        if (!is_readable((string) $this->tlsCertPath)) {
            throw new \RuntimeException(sprintf(
                'TLS certificate is not readable: %s',
                $this->tlsCertPath,
            ));
        }

        if ($this->tlsKeyPath !== null && !is_readable($this->tlsKeyPath)) {
            throw new \RuntimeException(sprintf(
                'TLS private key is not readable: %s',
                $this->tlsKeyPath,
            ));
        }

        if ($this->tlsRequireClientCert) {
            if ($this->tlsClientCaPath === null) {
                throw new \RuntimeException(
                    'tlsRequireClientCert needs tlsClientCaPath to verify client certificates',
                );
            }

            if (!is_readable($this->tlsClientCaPath)) {
                throw new \RuntimeException(sprintf(
                    'TLS client CA bundle is not readable: %s',
                    $this->tlsClientCaPath,
                ));
            }
        }
    }
}
