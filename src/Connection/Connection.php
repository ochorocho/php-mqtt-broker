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

    // Will message
    private bool $hasWill = false;
    private ?string $willTopic = null;
    private ?string $willPayload = null;
    private int $willQoS = 0;
    private bool $willRetain = false;
    private ?PropertyCollection $willProperties = null;

    // MQTT 5.0 state
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
    private ?TimerInterface $willDelayTimer = null;
    private bool $assignedClientId = false;

    public function __construct(
        private readonly ConnectionStream $stream,
        private readonly PacketEncoder $encoder,
        private readonly LoopInterface $loop,
    ) {
        $this->packetStream = new PacketStream();
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

    public function sendRaw(string $data): void
    {
        $this->stream->write($data);
    }

    public function close(): void
    {
        $this->cancelKeepAliveTimer();
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
                $onTimeout($this);
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

    // Will message accessors

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

    // MQTT 5.0 accessors

    public function getLoop(): LoopInterface
    {
        return $this->loop;
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

    public function getWillDelayTimer(): ?TimerInterface
    {
        return $this->willDelayTimer;
    }

    public function setWillDelayTimer(?TimerInterface $timer): void
    {
        $this->willDelayTimer = $timer;
    }

    public function cancelWillDelayTimer(): void
    {
        if ($this->willDelayTimer !== null) {
            $this->loop->cancelTimer($this->willDelayTimer);
            $this->willDelayTimer = null;
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
