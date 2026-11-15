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
     * @param int $maxRetainedBytes Total retained bytes: topic names, payloads and
     *                              properties.
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
     * @param ?string $retainedStatePath File the retained messages are kept in
     *                                   across restarts. Null keeps them in memory
     *                                   only, where a restart drops them.
     * @param int $maxFiltersPerSubscribe Topic filters acted on per SUBSCRIBE; the rest
     *                                    are refused. One packet used to be able to
     *                                    name hundreds of thousands.
     * @param int $maxOutboundBufferBytes Backlog tolerated for a client that is not
     *                                    reading before it is disconnected.
     * @param int $maxConnectPacketSize Largest packet accepted before CONNECT succeeds.
     *                                  An unauthenticated peer has proven nothing, so it
     *                                  does not get the full $maxPacketSize.
     * @param int $maxInflightMessages QoS 1/2 messages sent to a client and not yet
     *                                 acknowledged. Applied to 3.1.1 clients, which have
     *                                 no Receive Maximum, and caps a 5.0 client's own.
     * @param int $maxAuthFailuresPerHost Failed logins tolerated from one remote address
     *                                    within $authFailureWindow before further attempts
     *                                    are refused without being checked. 0 disables.
     * @param float $authFailureWindow Seconds a failed login counts against its host.
     * @param bool $tlsIdentityAsUsername Use the common name of the verified client
     *                                    certificate as the username, whatever CONNECT
     *                                    says. Needs $tlsRequireClientCert.
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
        public readonly ?string $retainedStatePath = null,
        public readonly int $maxFiltersPerSubscribe = 128,
        public readonly int $maxOutboundBufferBytes = 8 * 1024 * 1024,
        public readonly int $maxConnectPacketSize = 64 * 1024,
        public readonly int $maxInflightMessages = 64,
        public readonly int $maxAuthFailuresPerHost = 10,
        public readonly float $authFailureWindow = 60.0,
        public readonly bool $tlsIdentityAsUsername = false,
    ) {
    }

    /**
     * @throws \RuntimeException when a state file is configured but cannot be used.
     */
    public function validateRetainedState(): void
    {
        if ($this->retainedStatePath === null) {
            return;
        }

        // Refusing to start is the right outcome here as well: a broker told to
        // keep its retained messages and quietly unable to would forget them on
        // the next restart, which is the failure the option exists to end.
        $directory = dirname($this->retainedStatePath);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new \RuntimeException(sprintf(
                'Retained state directory is not writable: %s',
                $directory,
            ));
        }

        if (file_exists($this->retainedStatePath) && !is_readable($this->retainedStatePath)) {
            throw new \RuntimeException(sprintf(
                'Retained state file is not readable: %s',
                $this->retainedStatePath,
            ));
        }
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
            // Kept on the stream after the handshake, where the broker reads its name.
            $tls['capture_peer_cert'] = true;
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
        // Both settings are about client certificates, which only a TLS listener sees.
        // Without one they were simply not enforced.
        if ($this->tlsIdentityAsUsername && !$this->tlsRequireClientCert) {
            throw new \RuntimeException('tlsIdentityAsUsername needs tlsRequireClientCert: an unverified certificate proves nothing');
        }

        if (!$this->isTlsEnabled()) {
            if ($this->tlsRequireClientCert) {
                throw new \RuntimeException('tlsRequireClientCert needs tlsCertPath: without a certificate the listener is plaintext');
            }

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
