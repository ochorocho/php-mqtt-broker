<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Handler;

use PhpMqtt\Broker\Auth\AuthenticatorInterface;
use PhpMqtt\Broker\Connection\Connection;
use PhpMqtt\Broker\Connection\ConnectionManager;
use PhpMqtt\Broker\Exception\ProtocolViolationException;
use PhpMqtt\Broker\Message\RetainedMessageStore;
use PhpMqtt\Broker\Protocol\Packet\ConnackPacket;
use PhpMqtt\Broker\Protocol\Packet\ConnectPacket;
use PhpMqtt\Broker\Protocol\Packet\DisconnectPacket;
use PhpMqtt\Broker\Protocol\Packet\PacketInterface;
use PhpMqtt\Broker\Protocol\Packet\PacketType;
use PhpMqtt\Broker\Protocol\Packet\PingrespPacket;
use PhpMqtt\Broker\Protocol\Packet\PubackPacket;
use PhpMqtt\Broker\Protocol\Packet\PubcompPacket;
use PhpMqtt\Broker\Protocol\Packet\PublishPacket;
use PhpMqtt\Broker\Protocol\Packet\PubrecPacket;
use PhpMqtt\Broker\Protocol\Packet\PubrelPacket;
use PhpMqtt\Broker\Protocol\Packet\SubackPacket;
use PhpMqtt\Broker\Protocol\Packet\SubscribePacket;
use PhpMqtt\Broker\Protocol\Packet\UnsubackPacket;
use PhpMqtt\Broker\Protocol\Packet\UnsubscribePacket;
use PhpMqtt\Broker\Protocol\PacketEncoder;
use PhpMqtt\Broker\Protocol\Property\PropertyCollection;
use PhpMqtt\Broker\Protocol\Property\PropertyId;
use PhpMqtt\Broker\Protocol\ProtocolVersion;
use PhpMqtt\Broker\Session\SessionManager;
use PhpMqtt\Broker\Subscription\SubscriptionManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\EventLoop\LoopInterface;

final class PacketHandler
{
    private const int SERVER_RECEIVE_MAXIMUM = 20;
    private const int SERVER_TOPIC_ALIAS_MAXIMUM = 10;
    private const int SERVER_KEEP_ALIVE = 60;
    private const int SERVER_MAXIMUM_PACKET_SIZE = 1048576;

    private readonly RetainedMessageStore $retainedMessages;
    private readonly SessionManager $sessionManager;

    /** @var array<string, array<int, PublishPacket>> clientId => [packetId => PublishPacket] for QoS 2 incoming */
    private array $pendingIncomingQoS2 = [];

    /** @var array<string, int> clientId => next packet ID */
    private array $nextPacketId = [];

    /** @var array<string, array<int, PublishPacket>> clientId => [packetId => PublishPacket] pending outgoing QoS 1/2 */
    private array $pendingOutgoing = [];

    /** @var array<string, array<int, true>> clientId => [packetId => true] QoS 2 outgoing PUBREC received */
    private array $pendingQoS2Release = [];

    /** @var array<string, \React\EventLoop\TimerInterface> clientId => will delay timer */
    private array $willDelayTimers = [];

    /** @var array<string, int> shared subscription group key => round-robin counter */
    private array $sharedSubCounters = [];

    public function __construct(
        private readonly ConnectionManager $connectionManager,
        private readonly SubscriptionManager $subscriptionManager,
        private readonly AuthenticatorInterface $authenticator,
        private readonly LoopInterface $loop,
        private readonly PacketEncoder $packetEncoder,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->retainedMessages = new RetainedMessageStore();
        $this->sessionManager = new SessionManager();
    }

    public function getRetainedMessages(): RetainedMessageStore
    {
        return $this->retainedMessages;
    }

    public function getSessionManager(): SessionManager
    {
        return $this->sessionManager;
    }

    public function handle(Connection $connection, PacketInterface $packet): void
    {
        // First packet must be CONNECT
        if (!$connection->isConnected() && !($packet instanceof ConnectPacket)) {
            throw new ProtocolViolationException('First packet must be CONNECT');
        }

        // Only one CONNECT allowed
        if ($connection->isConnected() && $packet instanceof ConnectPacket) {
            throw new ProtocolViolationException('Second CONNECT packet received');
        }

        match ($packet->getType()) {
            PacketType::CONNECT => $this->handleConnect($connection, $packet),
            PacketType::PUBLISH => $this->handlePublish($connection, $packet),
            PacketType::PUBACK => $this->handlePuback($connection, $packet),
            PacketType::PUBREC => $this->handlePubrec($connection, $packet),
            PacketType::PUBREL => $this->handlePubrel($connection, $packet),
            PacketType::PUBCOMP => $this->handlePubcomp($connection, $packet),
            PacketType::SUBSCRIBE => $this->handleSubscribe($connection, $packet),
            PacketType::UNSUBSCRIBE => $this->handleUnsubscribe($connection, $packet),
            PacketType::PINGREQ => $this->handlePingreq($connection),
            PacketType::DISCONNECT => $this->handleDisconnectPacket($connection, $packet),
            default => throw new ProtocolViolationException('Unexpected packet type: ' . $packet->getType()->name),
        };
    }

    public function handleDisconnect(Connection $connection, bool $clean): void
    {
        $clientId = $connection->getClientId();
        if ($clientId === null) {
            return;
        }

        // Guard against double disconnect handling (e.g., keepalive timer + TCP close)
        if (!$connection->isConnected()) {
            return;
        }
        $connection->setConnected(false);

        // Publish will message if abnormal disconnect
        if (!$clean && $connection->hasWill()) {
            $willDelayInterval = 0;
            $willProps = $connection->getWillProperties();
            if ($willProps !== null) {
                $delay = $willProps->get(PropertyId::WillDelayInterval);
                if ($delay !== null) {
                    $willDelayInterval = (int) $delay;
                }
            }

            $sessionExpiry = $connection->getSessionExpiryInterval();

            // If willDelayInterval > 0 and session persists, defer will publication
            if ($willDelayInterval > 0 && $sessionExpiry > 0) {
                $effectiveDelay = min($willDelayInterval, $sessionExpiry);
                $this->willDelayTimers[$clientId] = $this->loop->addTimer($effectiveDelay, function () use ($connection, $clientId): void {
                    $this->publishWillMessage($connection);
                    $connection->clearWill();
                    unset($this->willDelayTimers[$clientId]);
                });
            } else {
                $this->publishWillMessage($connection);
                $connection->clearWill();
            }
        }

        $connection->cancelKeepAliveTimer();

        // Session handling: determine whether to persist or destroy the session
        $shouldSaveSession = $this->shouldPersistSession($connection);

        if (!$shouldSaveSession) {
            $this->subscriptionManager->removeClient($clientId);
            $this->sessionManager->destroy($clientId);
            $this->cleanupClientState($clientId);
        } else {
            // Save session state
            $subscriptions = array_values($this->subscriptionManager->getClientSubscriptions($clientId));
            $session = $this->sessionManager->getOrCreate($clientId);
            $session->subscriptions = $subscriptions;
            // MQTT 3.1.1 sessions with cleanSession=false persist indefinitely (no time-based expiry).
            // Use PHP_INT_MAX to prevent SessionManager::get() from expiring them.
            if ($connection->getProtocolVersion() === ProtocolVersion::V50) {
                $session->sessionExpiryInterval = $connection->getSessionExpiryInterval();
            } else {
                $session->sessionExpiryInterval = PHP_INT_MAX;
            }
            $session->disconnectedAt = microtime(true);
            $session->protocolVersion = $connection->getProtocolVersion();

            // Save pending outgoing messages (QoS 1/2 not yet acknowledged)
            // so they can be redelivered on reconnect
            if (isset($this->pendingOutgoing[$clientId])) {
                foreach ($this->pendingOutgoing[$clientId] as $pendingPacket) {
                    $session->pendingMessages[] = $pendingPacket;
                }
            }

            // Clean up in-memory state for the disconnected client
            $this->cleanupClientState($clientId);
        }
    }

    private function handleConnect(Connection $connection, PacketInterface $packet): void
    {
        assert($packet instanceof ConnectPacket);

        // Validate protocol
        if ($packet->protocolName !== 'MQTT') {
            $connection->close();
            return;
        }

        $version = ProtocolVersion::tryFrom($packet->protocolLevel);
        if ($version === null) {
            // Unacceptable protocol version
            $connection->send(new ConnackPacket(sessionPresent: false, returnCode: 0x01));
            $connection->close();
            return;
        }

        $connection->setProtocolVersion($version);

        // Generate client ID if empty
        $clientId = $packet->clientId;
        if ($clientId === '') {
            if ($version === ProtocolVersion::V50) {
                // MQTT 5.0: always accept empty client ID, server assigns one
                $clientId = 'auto-' . bin2hex(random_bytes(8));
                $connection->setAssignedClientId(true);
            } else {
                // MQTT 3.1.1: empty client ID requires clean session
                if (!$packet->cleanSession) {
                    $connection->send(new ConnackPacket(sessionPresent: false, returnCode: 0x02));
                    $connection->close();
                    return;
                }
                $clientId = 'auto-' . bin2hex(random_bytes(8));
            }
        }

        // Authenticate
        if (!$this->authenticator->authenticate($clientId, $packet->username, $packet->password)) {
            $connection->send(new ConnackPacket(sessionPresent: false, returnCode: 0x05));
            $connection->close();
            return;
        }

        // Take over existing connection with same client ID
        $existing = $this->connectionManager->getByClientId($clientId);
        if ($existing !== null) {
            $this->handleDisconnect($existing, false);
            $existing->close();
            $this->connectionManager->remove($existing);
        }

        // Cancel any existing will delay timer for this client ID
        if (isset($this->willDelayTimers[$clientId])) {
            $this->loop->cancelTimer($this->willDelayTimers[$clientId]);
            unset($this->willDelayTimers[$clientId]);
        }

        $connection->setClientId($clientId);
        $connection->setConnected(true);
        $connection->setCleanSession($packet->cleanSession);

        // MQTT 5.0: extract CONNECT properties
        if ($version === ProtocolVersion::V50 && $packet->properties !== null) {
            $sei = $packet->properties->get(PropertyId::SessionExpiryInterval);
            if ($sei !== null) {
                $connection->setSessionExpiryInterval((int) $sei);
            }

            $recvMax = $packet->properties->get(PropertyId::ReceiveMaximum);
            if ($recvMax !== null) {
                $connection->setReceiveMaximum((int) $recvMax);
            }

            $maxPacketSize = $packet->properties->get(PropertyId::MaximumPacketSize);
            if ($maxPacketSize !== null) {
                $connection->setClientMaximumPacketSize((int) $maxPacketSize);
            }

            $topicAliasMax = $packet->properties->get(PropertyId::TopicAliasMaximum);
            if ($topicAliasMax !== null) {
                $connection->setClientTopicAliasMaximum((int) $topicAliasMax);
            }
        }

        // Clear topic aliases on (re)connect
        $connection->clearTopicAliases();

        // Set keepalive: for v5.0, cap at SERVER_KEEP_ALIVE if client's value exceeds it
        $effectiveKeepAlive = $packet->keepAlive;
        $overrideKeepAlive = false;
        if ($version === ProtocolVersion::V50 && $packet->keepAlive > self::SERVER_KEEP_ALIVE) {
            $effectiveKeepAlive = self::SERVER_KEEP_ALIVE;
            $overrideKeepAlive = true;
        }
        $connection->setKeepAlive($effectiveKeepAlive);

        $this->connectionManager->register($clientId, $connection);

        // Will message
        if ($packet->hasWill && $packet->willTopic !== null) {
            $connection->setWill(
                $packet->willTopic,
                $packet->willPayload ?? '',
                $packet->willQoS,
                $packet->willRetain,
                $packet->willProperties,
            );
        }

        // Session handling
        $sessionPresent = false;
        if ($packet->cleanSession) {
            $this->sessionManager->destroy($clientId);
            $this->subscriptionManager->removeClient($clientId);
            $this->cleanupClientState($clientId);
        } else {
            $session = $this->sessionManager->get($clientId);
            if ($session !== null) {
                $sessionPresent = true;
                // Restore subscriptions
                $this->subscriptionManager->restoreClientSubscriptions($clientId, $session->subscriptions);
            }
        }

        // Pre-create session with protocol version so offline message delivery uses correct format
        $preSession = $this->sessionManager->getOrCreate($clientId);
        $preSession->protocolVersion = $version;

        // Build CONNACK properties for MQTT 5.0
        $connackProps = null;
        if ($version === ProtocolVersion::V50) {
            $connackProps = new PropertyCollection();
            $connackProps->set(PropertyId::ReceiveMaximum, self::SERVER_RECEIVE_MAXIMUM);
            $connackProps->set(PropertyId::TopicAliasMaximum, self::SERVER_TOPIC_ALIAS_MAXIMUM);
            $connackProps->set(PropertyId::MaximumPacketSize, self::SERVER_MAXIMUM_PACKET_SIZE);
            if ($overrideKeepAlive) {
                $connackProps->set(PropertyId::ServerKeepAlive, self::SERVER_KEEP_ALIVE);
            }
            $connackProps->set(PropertyId::RetainAvailable, 1);
            $connackProps->set(PropertyId::WildcardSubscriptionAvailable, 1);
            $connackProps->set(PropertyId::SubscriptionIdentifierAvailable, 1);
            $connackProps->set(PropertyId::SharedSubscriptionAvailable, 1);
            if ($connection->isAssignedClientId()) {
                $connackProps->set(PropertyId::AssignedClientIdentifier, $clientId);
            }
        }

        $connection->send(new ConnackPacket(
            sessionPresent: $sessionPresent,
            returnCode: 0x00,
            protocolVersion: $version,
            properties: $connackProps,
        ));

        // Start keepalive timer
        if ($effectiveKeepAlive > 0) {
            $connection->startKeepAliveTimer(function (Connection $conn): void {
                $this->logger->debug('Keep alive timeout for {client}', ['client' => $conn->getClientId()]);
                $this->handleDisconnect($conn, false);
                $conn->close();
            });
        }

        // Deliver pending messages from session
        if ($sessionPresent) {
            $session = $this->sessionManager->get($clientId);
            if ($session !== null) {
                foreach ($session->pendingMessages as $msg) {
                    // Check message expiry for MQTT 5.0
                    if ($version === ProtocolVersion::V50 && $msg->properties !== null) {
                        $expiryInterval = $msg->properties->get(PropertyId::MessageExpiryInterval);
                        if ($expiryInterval !== null && $session->disconnectedAt > 0.0) {
                            $elapsed = (int) (microtime(true) - $session->disconnectedAt);
                            $remaining = (int) $expiryInterval - $elapsed;
                            if ($remaining <= 0) {
                                continue; // Message expired, skip
                            }
                            // Update the expiry interval on the message
                            $msg->properties->remove(PropertyId::MessageExpiryInterval);
                            $msg->properties->set(PropertyId::MessageExpiryInterval, $remaining);
                        }
                    }

                    // Always create new packet with correct protocol version
                    $packetId = $msg->qos > 0
                        ? ($msg->packetId ?? $this->allocatePacketId($clientId))
                        : null;
                    $deliverMsg = new PublishPacket(
                        topicName: $msg->topicName,
                        payload: $msg->payload,
                        qos: $msg->qos,
                        dup: $msg->qos > 0,
                        retain: $msg->retain,
                        packetId: $packetId,
                        protocolVersion: $connection->getProtocolVersion(),
                        properties: $msg->properties,
                    );
                    if ($deliverMsg->qos > 0 && $deliverMsg->packetId !== null) {
                        $this->pendingOutgoing[$clientId][$deliverMsg->packetId] = $deliverMsg;
                        $connection->incrementUnackedOutgoing();
                    }
                    $connection->send($deliverMsg);
                }
                $session->pendingMessages = [];
            }
        }

        $this->logger->info('Client connected: {clientId}', ['clientId' => $clientId]);
    }

    private function handlePublish(Connection $connection, PacketInterface $packet): void
    {
        assert($packet instanceof PublishPacket);
        $clientId = $connection->getClientId();
        if ($clientId === null) {
            return;
        }

        // MQTT 5.0: topic alias resolution
        if ($connection->getProtocolVersion() === ProtocolVersion::V50 && $packet->properties !== null) {
            $topicAlias = $packet->properties->get(PropertyId::TopicAlias);
            if ($topicAlias !== null) {
                $alias = (int) $topicAlias;
                if ($alias === 0 || $alias > self::SERVER_TOPIC_ALIAS_MAXIMUM) {
                    // Topic Alias invalid — send DISCONNECT 0x94
                    $connection->send(new DisconnectPacket(
                        protocolVersion: ProtocolVersion::V50,
                        reasonCode: 0x94,
                    ));
                    $connection->close();
                    return;
                }
                if ($packet->topicName !== '') {
                    // Establishing alias
                    $connection->setIncomingTopicAlias($alias, $packet->topicName);
                    $resolvedTopic = $packet->topicName;
                } else {
                    // Using existing alias
                    $resolvedTopic = $connection->resolveIncomingTopicAlias($alias);
                    if ($resolvedTopic === null) {
                        // Alias not yet established — protocol error
                        $connection->send(new DisconnectPacket(
                            protocolVersion: ProtocolVersion::V50,
                            reasonCode: 0x94,
                        ));
                        $connection->close();
                        return;
                    }
                }
                // Create a new packet with the resolved topic
                $packet = new PublishPacket(
                    topicName: $resolvedTopic,
                    payload: $packet->payload,
                    qos: $packet->qos,
                    dup: $packet->dup,
                    retain: $packet->retain,
                    packetId: $packet->packetId,
                    protocolVersion: $packet->protocolVersion,
                    properties: $packet->properties,
                );
            }
        }

        // MQTT 5.0: server receive maximum enforcement for QoS 1/2
        if ($packet->qos > 0) {
            $incomingCount = count($this->pendingIncomingQoS2[$clientId] ?? []);
            if ($incomingCount >= self::SERVER_RECEIVE_MAXIMUM) {
                $connection->setConnected(false);
                if ($connection->getProtocolVersion() === ProtocolVersion::V50) {
                    $connection->send(new DisconnectPacket(
                        protocolVersion: ProtocolVersion::V50,
                        reasonCode: 0x93,
                    ));
                }
                // Defer close to allow client to read the DISCONNECT packet
                $this->loop->addTimer(0.5, function () use ($connection): void {
                    $connection->close();
                });
                return;
            }
        }

        // Handle retained messages
        if ($packet->retain) {
            if ($packet->payload === '') {
                $this->retainedMessages->remove($packet->topicName);
            } else {
                $this->retainedMessages->store($packet->topicName, $packet);
            }
        }

        // QoS 1: Send PUBACK to publisher
        if ($packet->qos === 1 && $packet->packetId !== null) {
            $connection->send(new PubackPacket(
                packetId: $packet->packetId,
                protocolVersion: $connection->getProtocolVersion(),
            ));
        }

        // QoS 2: Send PUBREC to publisher, defer delivery until PUBREL
        if ($packet->qos === 2 && $packet->packetId !== null) {
            $this->pendingIncomingQoS2[$clientId][$packet->packetId] = $packet;
            $connection->send(new PubrecPacket(
                packetId: $packet->packetId,
                protocolVersion: $connection->getProtocolVersion(),
            ));
            return; // Don't deliver yet — wait for PUBREL
        }

        // Route message to subscribers (QoS 0 and QoS 1)
        $this->routeMessage($packet, $clientId);
    }

    private function handlePuback(Connection $connection, PacketInterface $packet): void
    {
        assert($packet instanceof PubackPacket);
        $clientId = $connection->getClientId();
        if ($clientId === null) {
            return;
        }

        unset($this->pendingOutgoing[$clientId][$packet->packetId]);
        $connection->decrementUnackedOutgoing();
        $this->drainPendingMessages($connection);
    }

    private function handlePubrec(Connection $connection, PacketInterface $packet): void
    {
        assert($packet instanceof PubrecPacket);
        $clientId = $connection->getClientId();
        if ($clientId === null) {
            return;
        }

        // Mark as received, send PUBREL
        unset($this->pendingOutgoing[$clientId][$packet->packetId]);
        $this->pendingQoS2Release[$clientId][$packet->packetId] = true;

        $connection->send(new PubrelPacket(
            packetId: $packet->packetId,
            protocolVersion: $connection->getProtocolVersion(),
        ));
    }

    private function handlePubrel(Connection $connection, PacketInterface $packet): void
    {
        assert($packet instanceof PubrelPacket);
        $clientId = $connection->getClientId();
        if ($clientId === null) {
            return;
        }

        // Complete QoS 2 incoming flow: retrieve the stored message and route it
        $storedPacket = $this->pendingIncomingQoS2[$clientId][$packet->packetId] ?? null;
        unset($this->pendingIncomingQoS2[$clientId][$packet->packetId]);

        $connection->send(new PubcompPacket(
            packetId: $packet->packetId,
            protocolVersion: $connection->getProtocolVersion(),
        ));

        // Route the stored QoS 2 message now that the publisher has confirmed delivery
        if ($storedPacket instanceof PublishPacket) {
            $this->routeMessage($storedPacket, $clientId);
        }
    }

    private function handlePubcomp(Connection $connection, PacketInterface $packet): void
    {
        assert($packet instanceof PubcompPacket);
        $clientId = $connection->getClientId();
        if ($clientId === null) {
            return;
        }

        unset($this->pendingQoS2Release[$clientId][$packet->packetId]);
        $connection->decrementUnackedOutgoing();
        $this->drainPendingMessages($connection);
    }

    private function handleSubscribe(Connection $connection, PacketInterface $packet): void
    {
        assert($packet instanceof SubscribePacket);
        $clientId = $connection->getClientId();
        if ($clientId === null) {
            return;
        }

        $version = $connection->getProtocolVersion();

        // Extract subscription identifier from SUBSCRIBE properties (MQTT 5.0)
        $subscriptionIdentifier = 0;
        if ($version === ProtocolVersion::V50 && $packet->properties !== null) {
            $subId = $packet->properties->get(PropertyId::SubscriptionIdentifier);
            if ($subId !== null) {
                $subscriptionIdentifier = is_array($subId) ? (int) $subId[0] : (int) $subId;
            }
        }

        $returnCodes = [];

        foreach ($packet->subscriptions as $sub) {
            $topic = $sub['topic'];
            $qos = $sub['qos'];
            $noLocal = $sub['noLocal'] ?? false;
            $retainAsPublished = $sub['retainAsPublished'] ?? false;
            $retainHandling = $sub['retainHandling'] ?? 0;

            if (!$this->authenticator->canSubscribe($clientId, $topic)) {
                $returnCodes[] = 0x80; // Failure
                continue;
            }

            // Check if subscription already exists before subscribing (for retainHandling)
            $existingSubscription = $this->subscriptionManager->hasSubscription($clientId, $topic);

            $this->subscriptionManager->subscribe(
                $clientId,
                $topic,
                $qos,
                $noLocal,
                $retainAsPublished,
                $retainHandling,
                $subscriptionIdentifier,
            );
            $returnCodes[] = $qos; // Granted QoS

            // Determine the actual topic filter for retained message matching
            // (strip $share/group/ prefix for shared subscriptions)
            $retainedMatchTopic = $topic;
            if (str_starts_with($topic, '$share/')) {
                $parts = explode('/', $topic, 3);
                if (count($parts) >= 3) {
                    $retainedMatchTopic = $parts[2];
                }
            }

            // Determine if retained messages should be sent
            $shouldSendRetained = true;
            if ($version === ProtocolVersion::V50) {
                if ($retainHandling === 2) {
                    $shouldSendRetained = false;
                } elseif ($retainHandling === 1 && $existingSubscription) {
                    $shouldSendRetained = false;
                }
            }

            if (!$shouldSendRetained) {
                continue;
            }

            // Deliver retained messages for this subscription
            $retained = $this->retainedMessages->getMatching($retainedMatchTopic);
            foreach ($retained as $retainedPacket) {
                $deliverQoS = min($retainedPacket->qos, $qos);
                $packetId = $deliverQoS > 0 ? $this->allocatePacketId($clientId) : null;

                // Build properties for MQTT 5.0 retained message delivery
                $retainedProps = null;
                if ($version === ProtocolVersion::V50) {
                    $retainedProps = new PropertyCollection();

                    // Forward properties from the retained packet
                    if ($retainedPacket->properties !== null) {
                        $this->forwardProperties($retainedPacket->properties, $retainedProps);
                    }

                    // Add subscription identifier
                    if ($subscriptionIdentifier > 0) {
                        $retainedProps->set(PropertyId::SubscriptionIdentifier, $subscriptionIdentifier);
                    }
                }

                $deliverPacket = new PublishPacket(
                    topicName: $retainedPacket->topicName,
                    payload: $retainedPacket->payload,
                    qos: $deliverQoS,
                    retain: true,
                    packetId: $packetId,
                    protocolVersion: $version,
                    properties: $retainedProps,
                );
                $connection->send($deliverPacket);
                if ($deliverQoS > 0 && $deliverPacket->packetId !== null) {
                    $this->pendingOutgoing[$clientId][$deliverPacket->packetId] = $deliverPacket;
                }
            }
        }

        $connection->send(new SubackPacket(
            packetId: $packet->packetId,
            returnCodes: $returnCodes,
            protocolVersion: $version,
        ));
    }

    private function handleUnsubscribe(Connection $connection, PacketInterface $packet): void
    {
        assert($packet instanceof UnsubscribePacket);
        $clientId = $connection->getClientId();
        if ($clientId === null) {
            return;
        }

        $version = $connection->getProtocolVersion();

        if ($version === ProtocolVersion::V50) {
            $reasonCodes = [];
            foreach ($packet->topicFilters as $filter) {
                if ($this->subscriptionManager->hasSubscription($clientId, $filter)) {
                    $this->subscriptionManager->unsubscribe($clientId, $filter);
                    $reasonCodes[] = 0x00; // Success
                } else {
                    $reasonCodes[] = 0x11; // No subscription existed
                }
            }

            $connection->send(new UnsubackPacket(
                packetId: $packet->packetId,
                protocolVersion: $version,
                reasonCodes: $reasonCodes,
            ));
        } else {
            foreach ($packet->topicFilters as $filter) {
                $this->subscriptionManager->unsubscribe($clientId, $filter);
            }

            $connection->send(new UnsubackPacket(
                packetId: $packet->packetId,
                protocolVersion: $version,
            ));
        }
    }

    private function handlePingreq(Connection $connection): void
    {
        $connection->send(new PingrespPacket(
            protocolVersion: $connection->getProtocolVersion(),
        ));
    }

    private function handleDisconnectPacket(Connection $connection, PacketInterface $packet): void
    {
        assert($packet instanceof DisconnectPacket);

        // MQTT 5.0: check for SessionExpiryInterval override in DISCONNECT properties
        if ($connection->getProtocolVersion() === ProtocolVersion::V50 && $packet->properties !== null) {
            $newSEI = $packet->properties->get(PropertyId::SessionExpiryInterval);
            if ($newSEI !== null) {
                $connection->setSessionExpiryInterval((int) $newSEI);
            }
        }

        // MQTT 5.0 reason code 0x04 = Disconnect with Will Message
        if ($connection->getProtocolVersion() === ProtocolVersion::V50 && $packet->reasonCode === 0x04) {
            // Will should still be published despite clean disconnect
            $this->handleDisconnect($connection, false);
        } else {
            // Normal disconnect — clear will
            $connection->clearWill();
            $this->handleDisconnect($connection, true);
        }

        $connection->close();
    }

    private function publishWillMessage(Connection $connection): void
    {
        $willTopic = $connection->getWillTopic();
        $willPayload = $connection->getWillPayload();

        if ($willTopic === null) {
            return;
        }

        // Build properties from will properties for MQTT 5.0
        $willProps = $connection->getWillProperties();
        $publishProps = null;
        if ($connection->getProtocolVersion() === ProtocolVersion::V50 && $willProps !== null) {
            $publishProps = new PropertyCollection();
            $this->forwardProperties($willProps, $publishProps);
        }

        $willPacket = new PublishPacket(
            topicName: $willTopic,
            payload: $willPayload ?? '',
            qos: $connection->getWillQoS(),
            retain: $connection->isWillRetain(),
            packetId: $connection->getWillQoS() > 0 ? 1 : null,
            protocolVersion: $connection->getProtocolVersion(),
            properties: $publishProps,
        );

        // Handle retained will messages
        if ($willPacket->retain) {
            if ($willPacket->payload === '') {
                $this->retainedMessages->remove($willPacket->topicName);
            } else {
                $this->retainedMessages->store($willPacket->topicName, $willPacket);
            }
        }

        $this->routeMessage($willPacket);
    }

    private function routeMessage(PublishPacket $packet, ?string $publisherClientId = null): void
    {
        $subscriptions = $this->subscriptionManager->getMatchingSubscriptions($packet->topicName);

        // Separate shared and non-shared subscriptions
        $normalSubs = [];
        /** @var array<string, list<\PhpMqtt\Broker\Subscription\Subscription>> */
        $sharedGroups = [];

        foreach ($subscriptions as $sub) {
            if (str_starts_with($sub->topicFilter, '$share/')) {
                $parts = explode('/', $sub->topicFilter, 3);
                $groupName = $parts[1] ?? '';
                $sharedGroups[$groupName][] = $sub;
            } else {
                $normalSubs[] = $sub;
            }
        }

        // Process normal subscriptions: group by client, take max QoS, collect subscription IDs
        /** @var array<string, array{qos: int, subIds: list<int>, retainAsPublished: bool}> */
        $perClient = [];
        foreach ($normalSubs as $sub) {
            // noLocal filtering: skip delivery to the publishing client
            if ($sub->noLocal && $sub->clientId === $publisherClientId) {
                continue;
            }

            $effectiveQoS = min($packet->qos, $sub->qos);
            if (!isset($perClient[$sub->clientId]) || $effectiveQoS > $perClient[$sub->clientId]['qos']) {
                $perClient[$sub->clientId] = [
                    'qos' => $effectiveQoS,
                    'subIds' => [],
                    'retainAsPublished' => $sub->retainAsPublished,
                ];
            }
            if ($sub->subscriptionIdentifier > 0) {
                $perClient[$sub->clientId]['subIds'][] = $sub->subscriptionIdentifier;
            }
            if ($sub->retainAsPublished) {
                $perClient[$sub->clientId]['retainAsPublished'] = true;
            }
        }

        foreach ($perClient as $clientId => $info) {
            $this->deliverToClient(
                (string) $clientId,
                $packet,
                $info['qos'],
                $info['subIds'],
                $info['retainAsPublished'],
            );
        }

        // Process shared subscriptions: pick one subscriber per group (round-robin)
        foreach ($sharedGroups as $groupName => $groupSubs) {
            if ($groupSubs === []) {
                continue;
            }

            $key = $groupName . ':' . $groupSubs[0]->topicFilter;
            if (!isset($this->sharedSubCounters[$key])) {
                $this->sharedSubCounters[$key] = 0;
            }
            $index = $this->sharedSubCounters[$key] % count($groupSubs);
            $this->sharedSubCounters[$key]++;
            $selectedSub = $groupSubs[$index];

            $effectiveQoS = min($packet->qos, $selectedSub->qos);
            $subIds = $selectedSub->subscriptionIdentifier > 0 ? [$selectedSub->subscriptionIdentifier] : [];

            $this->deliverToClient(
                $selectedSub->clientId,
                $packet,
                $effectiveQoS,
                $subIds,
                $selectedSub->retainAsPublished,
            );
        }
    }

    /**
     * @param list<int> $subscriptionIds
     */
    private function deliverToClient(
        string $clientId,
        PublishPacket $originalPacket,
        int $qos,
        array $subscriptionIds = [],
        bool $retainAsPublished = false,
    ): void {
        $connection = $this->connectionManager->getByClientId($clientId);
        if ($connection !== null) {
            $version = $connection->getProtocolVersion();
        } else {
            // Client is offline — use session's protocol version for correct encoding
            $session = $this->sessionManager->get($clientId);
            $version = $session !== null ? $session->protocolVersion : ProtocolVersion::V311;
        }

        $packetId = $qos > 0 ? $this->allocatePacketId($clientId) : null;

        // Determine topic name and outgoing topic alias for MQTT 5.0
        $topicName = $originalPacket->topicName;
        $aliasInfo = null;
        if ($version === ProtocolVersion::V50 && $connection !== null) {
            $aliasInfo = $connection->getOrCreateOutgoingTopicAlias($originalPacket->topicName);
            if ($aliasInfo !== null && !$aliasInfo['isNew']) {
                $topicName = ''; // Reuse alias, omit topic name
            }
            // if aliasInfo['isNew'] is true, keep full topicName (establishing alias)
        }

        // Build properties for MQTT 5.0
        $properties = null;
        if ($version === ProtocolVersion::V50) {
            $properties = new PropertyCollection();

            // Forward properties from original packet
            if ($originalPacket->properties !== null) {
                $this->forwardProperties($originalPacket->properties, $properties);
            }

            // Add topic alias
            if ($aliasInfo !== null) {
                $properties->set(PropertyId::TopicAlias, $aliasInfo['alias']);
            }

            // Add subscription identifiers
            foreach ($subscriptionIds as $subId) {
                $properties->set(PropertyId::SubscriptionIdentifier, $subId);
            }
        }

        // Retain flag: retainAsPublished preserves original, otherwise set to false
        $retainFlag = $retainAsPublished ? $originalPacket->retain : false;

        $deliverPacket = new PublishPacket(
            topicName: $topicName,
            payload: $originalPacket->payload,
            qos: $qos,
            retain: $retainFlag,
            packetId: $packetId,
            protocolVersion: $version,
            properties: $properties,
        );

        // Check client's maximum packet size (MQTT 5.0)
        if ($version === ProtocolVersion::V50 && $connection !== null && $connection->getClientMaximumPacketSize() > 0) {
            $encoded = $this->packetEncoder->encode($deliverPacket);
            if (strlen($encoded) > $connection->getClientMaximumPacketSize()) {
                return; // Silently drop — must not send packets exceeding client's max
            }
        }

        if ($connection !== null && $connection->isConnected()) {
            // Flow control: check ReceiveMaximum for QoS > 0
            if ($qos > 0 && $connection->getUnackedOutgoing() >= $connection->getReceiveMaximum()) {
                // Queue instead of sending now — will be sent when ack is received
                $session = $this->sessionManager->get($clientId);
                if ($session === null) {
                    $session = $this->sessionManager->getOrCreate($clientId);
                }
                $session->pendingMessages[] = $deliverPacket;
                return;
            }

            $connection->send($deliverPacket);
            if ($qos > 0 && $packetId !== null) {
                $this->pendingOutgoing[$clientId][$packetId] = $deliverPacket;
                $connection->incrementUnackedOutgoing();
            }
        } else {
            // Queue for offline persistent session
            $session = $this->sessionManager->get($clientId);
            if ($session !== null) {
                $session->pendingMessages[] = $deliverPacket;
            }
        }
    }

    /**
     * Drain queued messages when flow control window opens.
     */
    private function drainPendingMessages(Connection $connection): void
    {
        $clientId = $connection->getClientId();
        if ($clientId === null) {
            return;
        }

        $session = $this->sessionManager->get($clientId);
        if ($session === null || $session->pendingMessages === []) {
            return;
        }

        while ($session->pendingMessages !== [] && ($connection->getUnackedOutgoing() < $connection->getReceiveMaximum())) {
            $msg = array_shift($session->pendingMessages);
            if ($msg->qos > 0) {
                $packetId = $this->allocatePacketId($clientId);
                $msg = new PublishPacket(
                    topicName: $msg->topicName,
                    payload: $msg->payload,
                    qos: $msg->qos,
                    dup: false,
                    retain: $msg->retain,
                    packetId: $packetId,
                    protocolVersion: $msg->protocolVersion,
                    properties: $msg->properties,
                );
                $this->pendingOutgoing[$clientId][$packetId] = $msg;
                $connection->incrementUnackedOutgoing();
            }
            $connection->send($msg);
        }
    }

    /**
     * Determine whether the session should be persisted on disconnect.
     *
     * For MQTT 3.1.1: persist if cleanSession is false.
     * For MQTT 5.0: cleanStart only controls whether to START clean; the session
     * is always persisted if SessionExpiryInterval > 0, regardless of cleanStart.
     */
    private function shouldPersistSession(Connection $connection): bool
    {
        if ($connection->getProtocolVersion() === ProtocolVersion::V50) {
            // In MQTT 5.0, session persistence is driven by SessionExpiryInterval
            if ($connection->getSessionExpiryInterval() > 0) {
                return true;
            }
            // SEI=0 means session expires immediately, but we still respect cleanSession=false
            // for non-v5.0 compatible behavior
            return !$connection->isCleanSession();
        }

        // MQTT 3.1.1: persist if cleanSession is false
        return !$connection->isCleanSession();
    }

    /**
     * Forward applicable properties from a source collection to a destination collection.
     *
     * Properties forwarded: PayloadFormatIndicator, ContentType, ResponseTopic,
     * CorrelationData, UserProperty, MessageExpiryInterval.
     */
    private function forwardProperties(PropertyCollection $source, PropertyCollection $dest): void
    {
        $forwardIds = [
            PropertyId::PayloadFormatIndicator,
            PropertyId::ContentType,
            PropertyId::ResponseTopic,
            PropertyId::CorrelationData,
            PropertyId::UserProperty,
            PropertyId::MessageExpiryInterval,
        ];

        foreach ($forwardIds as $propId) {
            $val = $source->get($propId);
            if ($val !== null) {
                if ($propId->isMultiValue() && is_array($val)) {
                    foreach ($val as $item) {
                        $dest->set($propId, $item);
                    }
                } else {
                    $dest->set($propId, $val);
                }
            }
        }
    }

    private function allocatePacketId(string $clientId): int
    {
        if (!isset($this->nextPacketId[$clientId])) {
            $this->nextPacketId[$clientId] = 1;
        }

        $id = $this->nextPacketId[$clientId];
        $this->nextPacketId[$clientId] = ($id >= 65535) ? 1 : $id + 1;

        // Skip IDs that are in use
        $maxAttempts = 65535;
        while (
            $maxAttempts > 0
            && (isset($this->pendingOutgoing[$clientId][$id]) || isset($this->pendingQoS2Release[$clientId][$id]))
        ) {
            $id = $this->nextPacketId[$clientId];
            $this->nextPacketId[$clientId] = ($id >= 65535) ? 1 : $id + 1;
            $maxAttempts--;
        }

        return $id;
    }

    private function cleanupClientState(string $clientId): void
    {
        unset(
            $this->pendingIncomingQoS2[$clientId],
            $this->pendingOutgoing[$clientId],
            $this->pendingQoS2Release[$clientId],
            $this->nextPacketId[$clientId],
        );
    }
}
