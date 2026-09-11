<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Connection;

use PhpMqtt\Broker\Protocol\Packet\PacketInterface;
use PhpMqtt\Broker\Protocol\PacketEncoder;
use PhpMqtt\Broker\Protocol\PacketStream;
use PhpMqtt\Broker\Protocol\Property\PropertyCollection;
use PhpMqtt\Broker\Protocol\ProtocolVersion;
use PhpMqtt\Broker\Server\ConnectionStream;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

final class Connection
{
    private readonly PacketStream $packetStream;
    private ?string $clientId = null;
    private ProtocolVersion $protocolVersion = ProtocolVersion::V311;
    private bool $connected = false;
    private ?TimerInterface $keepAliveTimer = null;
    private float $lastActivity;
    private int $keepAlive = 0;
    private bool $cleanSession = true;

    private bool $hasWill = false;
    private ?string $willTopic = null;
    private ?string $willPayload = null;
    private int $willQoS = 0;
    private bool $willRetain = false;
    private ?PropertyCollection $willProperties = null;

    private int $sessionExpiryInterval = 0;
    private int $receiveMaximum = 65535;
    private int $clientMaximumPacketSize = 0;
    private int $clientTopicAliasMaximum = 0;

    /** @var array<int, string> alias => topicName (client-to-server) */
    private array $incomingTopicAliases = [];
    /** @var array<string, int> topicName => alias (server-to-client) */
    private array $outgoingTopicAliases = [];
    private int $nextOutgoingTopicAlias = 1;
    private int $unackedOutgoing = 0;
    private bool $assignedClientId = false;
    private ?TimerInterface $connectTimer = null;
    private bool $disconnectHandled = false;

    public function __construct(
        private readonly ConnectionStream $stream,
        private readonly PacketEncoder $encoder,
        private readonly LoopInterface $loop,
        int $maxPacketSize = PacketStream::DEFAULT_MAX_PACKET_SIZE,
    ) {
        $this->packetStream = new PacketStream($maxPacketSize);
        $this->lastActivity = microtime(true);
    }

    public function getStream(): ConnectionStream
    {
        return $this->stream;
    }

    public function getPacketStream(): PacketStream
    {
        return $this->packetStream;
    }

    public function send(PacketInterface $packet): void
    {
        $this->stream->write($this->encoder->encode($packet));
    }

    public function close(): void
    {
        $this->cancelKeepAliveTimer();
        $this->cancelConnectTimer();
        $this->stream->close();
    }

    public function getClientId(): ?string
    {
        return $this->clientId;
    }

    public function setClientId(string $clientId): void
    {
        $this->clientId = $clientId;
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return $this->protocolVersion;
    }

    public function setProtocolVersion(ProtocolVersion $version): void
    {
        $this->protocolVersion = $version;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function setConnected(bool $connected): void
    {
        $this->connected = $connected;
    }

    /**
     * Whether disconnect bookkeeping has already run for this connection.
     *
     * This is deliberately separate from isConnected(). A connection can stop being
     * "connected" — refusing further packets — while its will, session and per-client
     * state still need to be dealt with once the socket actually closes. Guarding the
     * teardown on isConnected() meant those paths skipped cleanup entirely.
     */
    public function isDisconnectHandled(): bool
    {
        return $this->disconnectHandled;
    }

    public function markDisconnectHandled(): void
    {
        $this->disconnectHandled = true;
    }

    public function getKeepAlive(): int
    {
        return $this->keepAlive;
    }

    public function setKeepAlive(int $keepAlive): void
    {
        $this->keepAlive = $keepAlive;
    }

    public function isCleanSession(): bool
    {
        return $this->cleanSession;
    }

    public function setCleanSession(bool $cleanSession): void
    {
        $this->cleanSession = $cleanSession;
    }

    public function getRemoteAddress(): string
    {
        return $this->stream->getRemoteAddress();
    }

    public function updateActivity(): void
    {
        $this->lastActivity = microtime(true);
    }

    /**
     * Arm the deadline by which this connection must send CONNECT.
     *
     * Until CONNECT arrives the peer is unauthenticated but still consumes a
     * connection slot, so without a deadline an idle socket can be held forever.
     */
    public function startConnectTimer(float $timeout, callable $onTimeout): void
    {
        $this->connectTimer = $this->loop->addTimer($timeout, function () use ($onTimeout): void {
            $this->connectTimer = null;
            try {
                $onTimeout($this);
            } catch (\Throwable) {
                $this->stream->close();
            }
        });
    }

    public function cancelConnectTimer(): void
    {
        if ($this->connectTimer !== null) {
            $this->loop->cancelTimer($this->connectTimer);
            $this->connectTimer = null;
        }
    }

    public function startKeepAliveTimer(callable $onTimeout): void
    {
        $this->cancelKeepAliveTimer();

        if ($this->keepAlive === 0) {
            return;
        }

        // MQTT spec: server disconnects if no packet within 1.5x keepalive
        $timeout = $this->keepAlive * 1.5;

        $this->keepAliveTimer = $this->loop->addPeriodicTimer($timeout / 3, function () use ($onTimeout, $timeout): void {
            $elapsed = microtime(true) - $this->lastActivity;
            if ($elapsed >= $timeout) {
                // Timer callbacks run outside every request-path try/catch; an exception
                // here would escape the event loop and stop the whole broker.
                try {
                    $onTimeout($this);
                } catch (\Throwable) {
                    $this->cancelKeepAliveTimer();
                    $this->stream->close();
                }
            }
        });
    }

    public function cancelKeepAliveTimer(): void
    {
        if ($this->keepAliveTimer !== null) {
            $this->loop->cancelTimer($this->keepAliveTimer);
            $this->keepAliveTimer = null;
        }
    }

    public function hasWill(): bool
    {
        return $this->hasWill;
    }

    public function setWill(string $topic, string $payload, int $qos, bool $retain, ?PropertyCollection $properties = null): void
    {
        $this->hasWill = true;
        $this->willTopic = $topic;
        $this->willPayload = $payload;
        $this->willQoS = $qos;
        $this->willRetain = $retain;
        $this->willProperties = $properties;
    }

    public function clearWill(): void
    {
        $this->hasWill = false;
        $this->willTopic = null;
        $this->willPayload = null;
        $this->willQoS = 0;
        $this->willRetain = false;
        $this->willProperties = null;
    }

    public function getWillTopic(): ?string
    {
        return $this->willTopic;
    }

    public function getWillPayload(): ?string
    {
        return $this->willPayload;
    }

    public function getWillQoS(): int
    {
        return $this->willQoS;
    }

    public function isWillRetain(): bool
    {
        return $this->willRetain;
    }

    public function getWillProperties(): ?PropertyCollection
    {
        return $this->willProperties;
    }

    public function getSessionExpiryInterval(): int
    {
        return $this->sessionExpiryInterval;
    }

    public function setSessionExpiryInterval(int $interval): void
    {
        $this->sessionExpiryInterval = $interval;
    }

    public function getReceiveMaximum(): int
    {
        return $this->receiveMaximum;
    }

    public function setReceiveMaximum(int $max): void
    {
        $this->receiveMaximum = $max;
    }

    public function getClientMaximumPacketSize(): int
    {
        return $this->clientMaximumPacketSize;
    }

    public function setClientMaximumPacketSize(int $size): void
    {
        $this->clientMaximumPacketSize = $size;
    }

    public function getClientTopicAliasMaximum(): int
    {
        return $this->clientTopicAliasMaximum;
    }

    public function setClientTopicAliasMaximum(int $max): void
    {
        $this->clientTopicAliasMaximum = $max;
    }

    public function setIncomingTopicAlias(int $alias, string $topicName): void
    {
        $this->incomingTopicAliases[$alias] = $topicName;
    }

    public function resolveIncomingTopicAlias(int $alias): ?string
    {
        return $this->incomingTopicAliases[$alias] ?? null;
    }

    public function clearTopicAliases(): void
    {
        $this->incomingTopicAliases = [];
        $this->outgoingTopicAliases = [];
        $this->nextOutgoingTopicAlias = 1;
    }

    /**
     * @return array{alias: int, isNew: bool}|null
     */
    public function getOrCreateOutgoingTopicAlias(string $topicName): ?array
    {
        if ($this->clientTopicAliasMaximum <= 0) {
            return null;
        }

        if (isset($this->outgoingTopicAliases[$topicName])) {
            return ['alias' => $this->outgoingTopicAliases[$topicName], 'isNew' => false];
        }

        if ($this->nextOutgoingTopicAlias > $this->clientTopicAliasMaximum) {
            return null;
        }

        $alias = $this->nextOutgoingTopicAlias++;
        $this->outgoingTopicAliases[$topicName] = $alias;
        return ['alias' => $alias, 'isNew' => true];
    }

    public function getUnackedOutgoing(): int
    {
        return $this->unackedOutgoing;
    }

    public function incrementUnackedOutgoing(): void
    {
        $this->unackedOutgoing++;
    }

    public function decrementUnackedOutgoing(): void
    {
        if ($this->unackedOutgoing > 0) {
            $this->unackedOutgoing--;
        }
    }

    public function isAssignedClientId(): bool
    {
        return $this->assignedClientId;
    }

    public function setAssignedClientId(bool $assigned): void
    {
        $this->assignedClientId = $assigned;
    }
}
