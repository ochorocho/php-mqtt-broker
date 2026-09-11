<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Handler;

use PhpMqtt\Broker\Auth\AuthenticatorInterface;
use PhpMqtt\Broker\Configuration;
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
use PhpMqtt\Broker\Protocol\ConnectReasonCode;
use PhpMqtt\Broker\Protocol\DisconnectReasonCode;
use PhpMqtt\Broker\Protocol\ProtocolVersion;
use PhpMqtt\Broker\Protocol\PubackReasonCode;
use PhpMqtt\Broker\Protocol\SubackReasonCode;
use PhpMqtt\Broker\Protocol\TopicFilter;
use PhpMqtt\Broker\Session\SessionManager;
use PhpMqtt\Broker\Subscription\SubscriptionManager;
use PhpMqtt\Broker\Event\MessagePublished;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\EventLoop\LoopInterface;

final class PacketHandler
{
    private const int SERVER_RECEIVE_MAXIMUM = 20;
    private const int SERVER_TOPIC_ALIAS_MAXIMUM = 10;
    private const int SERVER_KEEP_ALIVE = 60;
    private const int MAX_SHARED_SUB_COUNTERS = 10000;

    private readonly RetainedMessageStore $retainedMessages;
    private readonly SessionManager $sessionManager;

    private readonly InFlightMessageTracker $inFlight;

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
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly Configuration $config = new Configuration(),
    ) {
        $this->retainedMessages = new RetainedMessageStore(
            maxMessages: $this->config->maxRetainedMessages,
            maxBytes: $this->config->maxRetainedBytes,
        );
        $this->sessionManager = new SessionManager(maxSessions: $this->config->maxSessions);
        $this->inFlight = new InFlightMessageTracker();
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
        if (!$connection->isConnected() && !($packet instanceof ConnectPacket)) {
            throw new ProtocolViolationException('First packet must be CONNECT');
        }

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

        // Guard against double disconnect handling (e.g., keepalive timer + TCP close).
        // This tracks whether teardown has run, not whether the connection is still
        // accepting packets: a path that marks a connection not-connected and defers the
        // close must still get its session persisted and its per-client state released.
        if ($connection->isDisconnectHandled()) {
            return;
        }
        $connection->markDisconnectHandled();
        $connection->setConnected(false);

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
                    // Runs long after the client is gone and outside any request-path
                    // guard; publishWillMessage encodes packets and can throw.
                    try {
                        $this->publishWillMessage($connection);
                    } catch (\Throwable $e) {
                        $this->logger->error('Delayed will publication failed for {clientId}: {error}', [
                            'clientId' => $clientId,
                            'error' => $e->getMessage(),
                            'exception' => $e,
                        ]);
                    }
                    $connection->clearWill();
                    unset($this->willDelayTimers[$clientId]);
                });
            } else {
                $this->publishWillMessage($connection);
                $connection->clearWill();
            }
        }

        $connection->cancelKeepAliveTimer();

        $shouldSaveSession = $this->shouldPersistSession($connection);

        if (!$shouldSaveSession) {
            $this->subscriptionManager->removeClient($clientId);
            $this->sessionManager->destroy($clientId);
            $this->inFlight->forget($clientId);
        } else {
            $subscriptions = array_values($this->subscriptionManager->getClientSubscriptions($clientId));
            $session = $this->sessionManager->getOrCreate($clientId);
            $session->subscriptions = $subscriptions;

            // Subscriptions deliberately stay in the routing table while the client is
            // offline: that is what lets messages be queued into the session for
            // delivery on reconnect. They are re-authorized by handleConnect instead.
            // MQTT 3.1.1 has no session expiry of its own, so such sessions were pinned
            // to PHP_INT_MAX and became immortal — an unbounded leak. Bound every session
            // by the configured ceiling instead.
            if ($connection->getProtocolVersion() === ProtocolVersion::V50) {
                $session->sessionExpiryInterval = min(
                    $connection->getSessionExpiryInterval(),
                    $this->config->maxSessionExpiry,
                );
            } else {
                $session->sessionExpiryInterval = $this->config->maxSessionExpiry;
            }
            $session->disconnectedAt = microtime(true);
            $session->protocolVersion = $connection->getProtocolVersion();

            // Save pending outgoing messages (QoS 1/2 not yet acknowledged)
            // so they can be redelivered on reconnect
            foreach ($this->inFlight->unacknowledged($clientId) as $pendingPacket) {
                $this->queuePendingMessage($session, $pendingPacket);
            }

            $this->inFlight->forget($clientId);
        }
    }

    private function handleConnect(Connection $connection, PacketInterface $packet): void
    {
        if (!$packet instanceof ConnectPacket) {
            throw new ProtocolViolationException('Expected CONNECT packet');
        }

        // CONNECT has arrived; the pre-auth deadline no longer applies.
        $connection->cancelConnectTimer();

        if ($packet->protocolName !== 'MQTT') {
            $connection->close();
            return;
        }

        $version = ProtocolVersion::tryFrom($packet->protocolLevel);
        if ($version === null) {
            $connection->send(new ConnackPacket(
                sessionPresent: false,
                returnCode: ConnectReasonCode::UnacceptableProtocolVersion->value,
            ));
            $connection->close();
            return;
        }

        $connection->setProtocolVersion($version);

        // A client ID becomes a key in the session store, the subscription table and
        // several per-client maps, so an oversized one is cheap memory amplification.
        if (strlen($packet->clientId) > $this->config->maxClientIdLength) {
            $connection->send(new ConnackPacket(
                sessionPresent: false,
                returnCode: $version === ProtocolVersion::V50
                    ? ConnectReasonCode::ClientIdentifierNotValid->value
                    : ConnectReasonCode::IdentifierRejected->value,
                protocolVersion: $version,
            ));
            $connection->close();
            return;
        }

        $clientId = $packet->clientId;
        if ($clientId === '') {
            if ($version === ProtocolVersion::V50) {
                // MQTT 5.0: always accept empty client ID, server assigns one
                $clientId = 'auto-' . bin2hex(random_bytes(8));
                $connection->setAssignedClientId(true);
            } else {
                // MQTT 3.1.1: empty client ID requires clean session
                if (!$packet->cleanSession) {
                    $connection->send(new ConnackPacket(
                        sessionPresent: false,
                        returnCode: ConnectReasonCode::IdentifierRejected->value,
                    ));
                    $connection->close();
                    return;
                }
                $clientId = 'auto-' . bin2hex(random_bytes(8));
            }
        }

        if (!$this->authorize(
            fn(): bool => $this->authenticator->authenticate($clientId, $packet->username, $packet->password),
            'authenticate',
            $clientId,
        )) {
            $connection->send(new ConnackPacket(
                sessionPresent: false,
                returnCode: $version === ProtocolVersion::V50
                    ? ConnectReasonCode::BadUserNameOrPassword->value
                    : ConnectReasonCode::NotAuthorizedV311->value,
                protocolVersion: $version,
            ));
            $connection->close();
            return;
        }

        // Bind the client ID to the authenticated principal before any takeover.
        // Without this, valid credentials allow evicting another client and, for
        // persistent sessions, inheriting its subscriptions and queued messages.
        if (!$this->authorize(
            fn(): bool => $this->authenticator->canUseClientId($clientId, $packet->username),
            'canUseClientId',
            $clientId,
        )) {
            $connection->send(new ConnackPacket(
                sessionPresent: false,
                returnCode: $version === ProtocolVersion::V50
                    ? ConnectReasonCode::NotAuthorized->value
                    : ConnectReasonCode::NotAuthorizedV311->value,
                protocolVersion: $version,
            ));
            $connection->close();
            return;
        }

        // Reject a will the client is not allowed to publish, rather than accepting
        // it at CONNECT and silently dropping it at disconnect time.
        if ($packet->hasWill && $packet->willTopic !== null
            && !$this->authorize(
                fn(): bool => $this->authenticator->canPublish($clientId, $packet->willTopic ?? ''),
                'canPublish(will)',
                $clientId,
                $packet->willTopic,
            )
        ) {
            $connection->send(new ConnackPacket(
                sessionPresent: false,
                returnCode: $version === ProtocolVersion::V50
                    ? ConnectReasonCode::NotAuthorized->value
                    : ConnectReasonCode::NotAuthorizedV311->value,
                protocolVersion: $version,
            ));
            $connection->close();
            return;
        }

        $existing = $this->connectionManager->getByClientId($clientId);
        if ($existing !== null) {
            $this->handleDisconnect($existing, false);
            $existing->close();
            $this->connectionManager->remove($existing);
        }

        if (isset($this->willDelayTimers[$clientId])) {
            $this->loop->cancelTimer($this->willDelayTimers[$clientId]);
            unset($this->willDelayTimers[$clientId]);
        }

        $connection->setClientId($clientId);
        $connection->setConnected(true);
        $connection->setCleanSession($packet->cleanSession);

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

        $connection->clearTopicAliases();

        // Set keepalive: for v5.0, cap at SERVER_KEEP_ALIVE if client's value exceeds it
        $effectiveKeepAlive = $packet->keepAlive;
        $overrideKeepAlive = false;
        if ($version === ProtocolVersion::V50 && $packet->keepAlive > self::SERVER_KEEP_ALIVE) {
            $effectiveKeepAlive = self::SERVER_KEEP_ALIVE;
            $overrideKeepAlive = true;
        }

        // Keep alive 0 disables the timer entirely, leaving a connection that is never
        // reaped. Apply a floor so every connection stays subject to a liveness check.
        if ($effectiveKeepAlive === 0) {
            $effectiveKeepAlive = $this->config->minKeepAlive;
            $overrideKeepAlive = $version === ProtocolVersion::V50;
        }
        $connection->setKeepAlive($effectiveKeepAlive);

        $this->connectionManager->register($clientId, $connection);

        if ($packet->hasWill && $packet->willTopic !== null) {
            $connection->setWill(
                $packet->willTopic,
                $packet->willPayload ?? '',
                $packet->willQoS,
                $packet->willRetain,
                $packet->willProperties,
            );
        }

        $sessionPresent = false;
        if ($packet->cleanSession) {
            $this->sessionManager->destroy($clientId);
            $this->subscriptionManager->removeClient($clientId);
            $this->inFlight->forget($clientId);
        } else {
            $session = $this->sessionManager->get($clientId);
            if ($session !== null) {
                $sessionPresent = true;
                // Re-authorize restored subscriptions: a grant made before the client's
                // access was revoked must not survive a reconnect. The subscriptions are
                // still live in the routing table (they stay there while offline so
                // messages can be queued), so clear them first and re-add only the ones
                // the authenticator still permits.
                $session->subscriptions = array_values(array_filter(
                    $session->subscriptions,
                    fn($sub): bool => $this->authorize(
                        fn(): bool => $this->authenticator->canSubscribe($clientId, $sub->topicFilter),
                        'canSubscribe(restore)',
                        $clientId,
                        $sub->topicFilter,
                    ),
                ));
                $this->subscriptionManager->removeClient($clientId);
                $this->subscriptionManager->restoreClientSubscriptions($clientId, $session->subscriptions);
            }
        }

        // Pre-create session with protocol version so offline message delivery uses correct format
        $preSession = $this->sessionManager->getOrCreate($clientId);
        $preSession->protocolVersion = $version;

        $connackProps = null;
        if ($version === ProtocolVersion::V50) {
            $connackProps = new PropertyCollection();
            $connackProps->set(PropertyId::ReceiveMaximum, self::SERVER_RECEIVE_MAXIMUM);
            $connackProps->set(PropertyId::TopicAliasMaximum, self::SERVER_TOPIC_ALIAS_MAXIMUM);
            $connackProps->set(PropertyId::MaximumPacketSize, $this->config->maxPacketSize);
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
            returnCode: ConnectReasonCode::Success->value,
            protocolVersion: $version,
            properties: $connackProps,
        ));

        if ($effectiveKeepAlive > 0) {
            $connection->startKeepAliveTimer(function (Connection $conn): void {
                $this->logger->debug('Keep alive timeout for {client}', ['client' => $conn->getClientId()]);
                $this->handleDisconnect($conn, false);
                $conn->close();
            });
        }

        if ($sessionPresent) {
            $session = $this->sessionManager->get($clientId);
            if ($session !== null) {
                foreach ($session->pendingMessages as $msg) {
                    if ($version === ProtocolVersion::V50 && $msg->properties !== null) {
                        $expiryInterval = $msg->properties->get(PropertyId::MessageExpiryInterval);
                        if ($expiryInterval !== null && $session->disconnectedAt > 0.0) {
                            $elapsed = (int) (microtime(true) - $session->disconnectedAt);
                            $remaining = (int) $expiryInterval - $elapsed;
                            if ($remaining <= 0) {
                                continue; // Message expired, skip
                            }
                            $msg->properties->remove(PropertyId::MessageExpiryInterval);
                            $msg->properties->set(PropertyId::MessageExpiryInterval, $remaining);
                        }
                    }

                    $packetId = $msg->qos > 0
                        ? ($msg->packetId ?? $this->inFlight->allocatePacketId($clientId))
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
                    $this->inFlight->sendTracked($connection, $deliverMsg, $clientId);
                }
                $session->pendingMessages = [];
            }
        }

        $this->logger->info('Client connected: {clientId}', ['clientId' => $clientId]);
    }

    private function handlePublish(Connection $connection, PacketInterface $packet): void
    {
        if (!$packet instanceof PublishPacket) {
            throw new ProtocolViolationException('Expected PUBLISH packet');
        }
        $clientId = $connection->getClientId();
        if ($clientId === null) {
            return;
        }

        if ($connection->getProtocolVersion() === ProtocolVersion::V50 && $packet->properties !== null) {
            $topicAlias = $packet->properties->get(PropertyId::TopicAlias);
            if ($topicAlias !== null) {
                $alias = (int) $topicAlias;
                if ($alias === 0 || $alias > self::SERVER_TOPIC_ALIAS_MAXIMUM) {
                    $connection->send(new DisconnectPacket(
                        protocolVersion: ProtocolVersion::V50,
                        reasonCode: DisconnectReasonCode::TopicAliasInvalid->value,
                    ));
                    $connection->close();
                    return;
                }
                if ($packet->topicName !== '') {
                    $connection->setIncomingTopicAlias($alias, $packet->topicName);
                    $resolvedTopic = $packet->topicName;
                } else {
                    $resolvedTopic = $connection->resolveIncomingTopicAlias($alias);
                    if ($resolvedTopic === null) {
                        // Alias not yet established — protocol error
                        $connection->send(new DisconnectPacket(
                            protocolVersion: ProtocolVersion::V50,
                            reasonCode: DisconnectReasonCode::TopicAliasInvalid->value,
                        ));
                        $connection->close();
                        return;
                    }
                }
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

        if ($packet->qos > 0) {
            $incomingCount = $this->inFlight->countIncoming($clientId);
            if ($incomingCount >= self::SERVER_RECEIVE_MAXIMUM) {
                $connection->setConnected(false);
                if ($connection->getProtocolVersion() === ProtocolVersion::V50) {
                    $connection->send(new DisconnectPacket(
                        protocolVersion: ProtocolVersion::V50,
                        reasonCode: DisconnectReasonCode::ReceiveMaximumExceeded->value,
                    ));
                }
                // Defer close to allow client to read the DISCONNECT packet
                $this->loop->addTimer(0.5, function () use ($connection): void {
                    try {
                        $connection->close();
                    } catch (\Throwable) {
                        // Nothing useful left to do; never let it reach the event loop.
                    }
                });
                return;
            }
        }

        // Authorize the publish before it reaches the retained store or any subscriber.
        // An empty retained payload deletes the retained message for a topic, so this
        // also gates retained-message destruction.
        if (!$this->authorize(
            fn(): bool => $this->authenticator->canPublish($clientId, $packet->topicName),
            'canPublish',
            $clientId,
            $packet->topicName,
        )) {
            $this->rejectPublish($connection, $packet);
            return;
        }

        if ($packet->retain) {
            if ($packet->payload === '') {
                $this->retainedMessages->remove($packet->topicName);
            } else {
                $this->retainedMessages->store($packet->topicName, $packet);
            }
        }

        if ($packet->qos === 1 && $packet->packetId !== null) {
            $connection->send(new PubackPacket(
                packetId: $packet->packetId,
                protocolVersion: $connection->getProtocolVersion(),
            ));
        }

        // QoS 2: Send PUBREC to publisher, defer delivery until PUBREL
        if ($packet->qos === 2 && $packet->packetId !== null) {
            $this->inFlight->holdIncoming($clientId, $packet);
            $connection->send(new PubrecPacket(
                packetId: $packet->packetId,
                protocolVersion: $connection->getProtocolVersion(),
            ));
            return; // Don't deliver yet — wait for PUBREL
        }

        $this->dispatchMessagePublished($packet, $clientId);

        $this->routeMessage($packet, $clientId);
    }

    private function handlePuback(Connection $connection, PacketInterface $packet): void
    {
        if (!$packet instanceof PubackPacket) {
            throw new ProtocolViolationException('Expected PUBACK packet');
        }
        $clientId = $connection->getClientId();
        if ($clientId === null) {
            return;
        }

        $this->inFlight->acknowledgeOutgoing($clientId, $packet->packetId);
        $connection->decrementUnackedOutgoing();
        $this->drainPendingMessages($connection);
    }

    private function handlePubrec(Connection $connection, PacketInterface $packet): void
    {
        if (!$packet instanceof PubrecPacket) {
            throw new ProtocolViolationException('Expected PUBREC packet');
        }
        $clientId = $connection->getClientId();
        if ($clientId === null) {
            return;
        }

        $this->inFlight->awaitPubcomp($clientId, $packet->packetId);

        $connection->send(new PubrelPacket(
            packetId: $packet->packetId,
            protocolVersion: $connection->getProtocolVersion(),
        ));
    }

    private function handlePubrel(Connection $connection, PacketInterface $packet): void
    {
        if (!$packet instanceof PubrelPacket) {
            throw new ProtocolViolationException('Expected PUBREL packet');
        }
        $clientId = $connection->getClientId();
        if ($clientId === null) {
            return;
        }

        $storedPacket = $this->inFlight->takeIncoming($clientId, $packet->packetId);

        $connection->send(new PubcompPacket(
            packetId: $packet->packetId,
            protocolVersion: $connection->getProtocolVersion(),
        ));

        // Route the stored QoS 2 message now that the publisher has confirmed delivery
        if ($storedPacket instanceof PublishPacket) {
            $this->dispatchMessagePublished($storedPacket, $clientId);
            $this->routeMessage($storedPacket, $clientId);
        }
    }

    private function dispatchMessagePublished(PublishPacket $packet, string $clientId): void
    {
        $this->eventDispatcher?->dispatch(new MessagePublished(
            topic: $packet->topicName,
            payload: $packet->payload,
            qos: $packet->qos,
            retain: $packet->retain,
            clientId: $clientId,
        ));
    }

    private function handlePubcomp(Connection $connection, PacketInterface $packet): void
    {
        if (!$packet instanceof PubcompPacket) {
            throw new ProtocolViolationException('Expected PUBCOMP packet');
        }
        $clientId = $connection->getClientId();
        if ($clientId === null) {
            return;
        }

        $this->inFlight->completeOutgoing($clientId, $packet->packetId);
        $connection->decrementUnackedOutgoing();
        $this->drainPendingMessages($connection);
    }

    private function handleSubscribe(Connection $connection, PacketInterface $packet): void
    {
        if (!$packet instanceof SubscribePacket) {
            throw new ProtocolViolationException('Expected SUBSCRIBE packet');
        }
        $clientId = $connection->getClientId();
        if ($clientId === null) {
            return;
        }

        $version = $connection->getProtocolVersion();

        $subscriptionIdentifier = 0;
        if ($version === ProtocolVersion::V50 && $packet->properties !== null) {
            $subId = $packet->properties->get(PropertyId::SubscriptionIdentifier);
            if ($subId !== null) {
                // More than one identifier on a SUBSCRIBE is a protocol error, and the
                // value must be at least 1 (MQTT-3.8.2.1.2).
                if (is_array($subId) && count($subId) > 1) {
                    throw new ProtocolViolationException('SUBSCRIBE must carry at most one Subscription Identifier');
                }

                $subscriptionIdentifier = is_array($subId) ? (int) $subId[0] : (int) $subId;

                if ($subscriptionIdentifier === 0) {
                    throw new ProtocolViolationException('Subscription Identifier must not be zero');
                }
            }
        }

        $returnCodes = [];

        foreach ($packet->subscriptions as $sub) {
            $topic = $sub['topic'];
            $qos = $sub['qos'];
            $noLocal = $sub['noLocal'] ?? false;
            $retainAsPublished = $sub['retainAsPublished'] ?? false;
            $retainHandling = $sub['retainHandling'] ?? 0;

            // A malformed filter used to be accepted and granted, leaving the client
            // believing it had subscribed to something the broker stored as a literal.
            if (!TopicFilter::isValidFilter($topic) || !TopicFilter::isValidSharedFilter($topic)) {
                $returnCodes[] = SubackReasonCode::UnspecifiedError->value;
                continue;
            }

            // Bound trie growth: deep filters and unlimited subscriptions per client are
            // both cheap ways to allocate nodes that used to be kept for the process life.
            if (substr_count($topic, '/') + 1 > $this->config->maxTopicLevels) {
                $returnCodes[] = SubackReasonCode::UnspecifiedError->value;
                continue;
            }

            if (!$this->subscriptionManager->hasSubscription($clientId, $topic)
                && $this->subscriptionManager->countClientSubscriptions($clientId)
                    >= $this->config->maxSubscriptionsPerClient
            ) {
                $returnCodes[] = SubackReasonCode::UnspecifiedError->value;
                continue;
            }

            if (!$this->authorize(
                fn(): bool => $this->authenticator->canSubscribe($clientId, $topic),
                'canSubscribe',
                $clientId,
                $topic,
            )) {
                $returnCodes[] = SubackReasonCode::UnspecifiedError->value;
                continue;
            }

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

            if ($this->shouldSendRetained($version, $retainHandling, $existingSubscription)) {
                $this->deliverRetainedMessages(
                    $connection,
                    $clientId,
                    TopicFilter::stripSharedPrefix($topic),
                    $qos,
                    $subscriptionIdentifier,
                );
            }
        }

        $connection->send(new SubackPacket(
            packetId: $packet->packetId,
            returnCodes: $returnCodes,
            protocolVersion: $version,
        ));
    }

    /**
     * Retain Handling lets a 5.0 client ask for the retained messages to be withheld,
     * either always or when it was already subscribed (MQTT-3.8.3.1). 3.1.1 always
     * sends them.
     */
    private function shouldSendRetained(ProtocolVersion $version, int $retainHandling, bool $alreadySubscribed): bool
    {
        if ($version !== ProtocolVersion::V50) {
            return true;
        }

        return match ($retainHandling) {
            2 => false,
            1 => !$alreadySubscribed,
            default => true,
        };
    }

    /**
     * Send the retained messages matching a new subscription.
     *
     * This path is subject to the same limits as ordinary delivery: it used to bypass
     * both the client's maximum packet size and the flow-control window.
     */
    private function deliverRetainedMessages(
        Connection $connection,
        string $clientId,
        string $topicFilter,
        int $subscribedQoS,
        int $subscriptionIdentifier,
    ): void {
        $version = $connection->getProtocolVersion();

        foreach ($this->retainedMessages->getMatching($topicFilter) as $retainedPacket) {
            $qos = min($retainedPacket->qos, $subscribedQoS);

            $properties = null;
            if ($version === ProtocolVersion::V50) {
                $properties = new PropertyCollection();

                if ($retainedPacket->properties !== null) {
                    $this->forwardProperties($retainedPacket->properties, $properties);
                }

                if ($subscriptionIdentifier > 0) {
                    $properties->set(PropertyId::SubscriptionIdentifier, $subscriptionIdentifier);
                }
            }

            $deliverPacket = new PublishPacket(
                topicName: $retainedPacket->topicName,
                payload: $retainedPacket->payload,
                qos: $qos,
                retain: true,
                packetId: $qos > 0 ? $this->inFlight->allocatePacketId($clientId) : null,
                protocolVersion: $version,
                properties: $properties,
            );

            if ($this->exceedsClientPacketSize($connection, $deliverPacket)) {
                continue;
            }

            // Respect the flow-control window; queue instead of sending past it.
            if ($qos > 0 && $connection->getUnackedOutgoing() >= $connection->getReceiveMaximum()) {
                $this->queuePendingMessage($this->sessionManager->getOrCreate($clientId), $deliverPacket);
                continue;
            }

            $this->inFlight->sendTracked($connection, $deliverPacket, $clientId);
        }
    }

    /**
     * A 5.0 client may declare a maximum packet size it will accept (MQTT-3.1.2-24).
     *
     * Only for retained delivery, where the connection is live and its version is the
     * one to trust. deliverToClient has its own check for a reason; see there.
     */
    private function exceedsClientPacketSize(Connection $connection, PublishPacket $packet): bool
    {
        if ($connection->getProtocolVersion() !== ProtocolVersion::V50) {
            return false;
        }

        $maximum = $connection->getClientMaximumPacketSize();

        return $maximum > 0 && strlen($this->packetEncoder->encode($packet)) > $maximum;
    }

    private function handleUnsubscribe(Connection $connection, PacketInterface $packet): void
    {
        if (!$packet instanceof UnsubscribePacket) {
            throw new ProtocolViolationException('Expected UNSUBSCRIBE packet');
        }
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
                    $reasonCodes[] = SubackReasonCode::GrantedQos0->value;
                } else {
                    $reasonCodes[] = SubackReasonCode::NoSubscriptionExisted->value;
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
        if (!$packet instanceof DisconnectPacket) {
            throw new ProtocolViolationException('Expected DISCONNECT packet');
        }

        if ($connection->getProtocolVersion() === ProtocolVersion::V50 && $packet->properties !== null) {
            $newSEI = $packet->properties->get(PropertyId::SessionExpiryInterval);
            if ($newSEI !== null) {
                $connection->setSessionExpiryInterval((int) $newSEI);
            }
        }

        // The client is asking for its will to be published despite going away cleanly.
        if ($connection->getProtocolVersion() === ProtocolVersion::V50 && $packet->reasonCode === DisconnectReasonCode::DisconnectWithWillMessage->value) {
            // Will should still be published despite clean disconnect
            $this->handleDisconnect($connection, false);
        } else {
            // Normal disconnect — clear will
            $connection->clearWill();
            $this->handleDisconnect($connection, true);
        }

        $connection->close();
    }

    /**
     * Append to a session's offline queue, dropping the oldest message when full.
     *
     * The queue is otherwise unbounded: a subscriber with a persistent session can go
     * offline while a publisher floods its topic, and every message is held forever.
     */
    private function queuePendingMessage(\PhpMqtt\Broker\Session\Session $session, PublishPacket $packet): void
    {
        $limit = $this->config->maxPendingMessagesPerSession;

        if (count($session->pendingMessages) >= $limit) {
            array_shift($session->pendingMessages);
            $this->logger->warning('Offline queue full for {clientId}, dropped oldest message', [
                'clientId' => $session->clientId,
                'limit' => $limit,
            ]);
        }

        $session->pendingMessages[] = $packet;
    }

    /**
     * Run an authorization check, failing closed if the authenticator throws.
     *
     * Implementations typically hit a database or HTTP service, so a transient error
     * is expected. Such an error must neither grant access nor escape into the event
     * loop, where it would stop the broker for every connected client.
     *
     * @param callable(): bool $check
     */
    private function authorize(callable $check, string $action, string $clientId, string $topic = ''): bool
    {
        try {
            return $check();
        } catch (\Throwable $e) {
            $this->logger->error('Authenticator failed during {action} for {clientId}, denying: {error}', [
                'action' => $action,
                'clientId' => $clientId,
                'topic' => $topic,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return false;
        }
    }

    /**
     * Acknowledge a denied PUBLISH without routing it.
     *
     * MQTT 5.0 has a "Not authorized" reason code; 3.1.1 has no way to signal refusal,
     * so the packet is acknowledged normally and silently dropped, per spec guidance.
     */
    private function rejectPublish(Connection $connection, PublishPacket $packet): void
    {
        $version = $connection->getProtocolVersion();
        $isV5 = $version === ProtocolVersion::V50;

        $this->logger->warning('Publish denied for {clientId} on {topic}', [
            'clientId' => $connection->getClientId(),
            'topic' => $packet->topicName,
        ]);

        if ($packet->qos === 1 && $packet->packetId !== null) {
            $connection->send(new PubackPacket(
                packetId: $packet->packetId,
                protocolVersion: $version,
                reasonCode: $isV5 ? PubackReasonCode::NotAuthorized->value : PubackReasonCode::Success->value,
            ));
        }

        if ($packet->qos === 2 && $packet->packetId !== null) {
            $connection->send(new PubrecPacket(
                packetId: $packet->packetId,
                protocolVersion: $version,
                reasonCode: $isV5 ? PubackReasonCode::NotAuthorized->value : PubackReasonCode::Success->value,
            ));
        }
    }

    private function publishWillMessage(Connection $connection): void
    {
        $willTopic = $connection->getWillTopic();
        $willPayload = $connection->getWillPayload();

        if ($willTopic === null) {
            return;
        }

        // Re-check at publish time: authorization may have been revoked while the
        // connection was open, or between a delayed will being armed and firing.
        $clientId = $connection->getClientId();
        if ($clientId !== null && !$this->authorize(
            fn(): bool => $this->authenticator->canPublish($clientId, $willTopic),
            'canPublish(will)',
            $clientId,
            $willTopic,
        )) {
            $this->logger->warning('Will message denied for {clientId} on {topic}', [
                'clientId' => $clientId,
                'topic' => $willTopic,
            ]);
            return;
        }

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

        $normalSubs = [];
        /** @var array<string, array{group: string, subs: list<\PhpMqtt\Broker\Subscription\Subscription>}> */
        $sharedGroups = [];

        foreach ($subscriptions as $sub) {
            $group = TopicFilter::sharedGroup($sub->topicFilter);
            if ($group !== null) {
                // Same reasoning as $perClient below: a numeric group name would come
                // back out of the key as an integer, so keep the name in the entry.
                $sharedGroups[$group]['group'] = $group;
                $sharedGroups[$group]['subs'][] = $sub;
            } else {
                $normalSubs[] = $sub;
            }
        }

        // The client ID is carried in the entry rather than read back out of the key:
        // PHP turns a numeric-string key into an integer, so a client ID like "123"
        // came back as int(123) and only survived because of a cast at the call site.
        /** @var array<string, array{clientId: string, qos: int, subIds: list<int>, retainAsPublished: bool}> */
        $perClient = [];
        foreach ($normalSubs as $sub) {
            if ($sub->noLocal && $sub->clientId === $publisherClientId) {
                continue;
            }

            $effectiveQoS = min($packet->qos, $sub->qos);
            if (!isset($perClient[$sub->clientId])) {
                $perClient[$sub->clientId] = [
                    'clientId' => $sub->clientId,
                    'qos' => $effectiveQoS,
                    'subIds' => [],
                    'retainAsPublished' => $sub->retainAsPublished,
                ];
            } elseif ($effectiveQoS > $perClient[$sub->clientId]['qos']) {
                // Raise the QoS without discarding identifiers already collected: every
                // matching subscription's identifier must be sent (MQTT-3.3.4-3).
                $perClient[$sub->clientId]['qos'] = $effectiveQoS;
            }
            if ($sub->subscriptionIdentifier > 0) {
                $perClient[$sub->clientId]['subIds'][] = $sub->subscriptionIdentifier;
            }
            if ($sub->retainAsPublished) {
                $perClient[$sub->clientId]['retainAsPublished'] = true;
            }
        }

        foreach ($perClient as $info) {
            $this->deliverToClient(
                $info['clientId'],
                $packet,
                $info['qos'],
                $info['subIds'],
                $info['retainAsPublished'],
            );
        }

        foreach ($sharedGroups as $group) {
            $groupName = $group['group'];

            // noLocal applies here too: the publisher must not receive its own message
            // through a shared subscription either (MQTT-3.8.3-3).
            $groupSubs = array_values(array_filter(
                $group['subs'],
                static fn($sub): bool => !($sub->noLocal && $sub->clientId === $publisherClientId),
            ));

            if ($groupSubs === []) {
                continue;
            }

            $key = $groupName . ':' . $groupSubs[0]->topicFilter;
            if (!isset($this->sharedSubCounters[$key])) {
                // Keys are attacker-chosen ($share/<group>/<filter>) and are not tied to
                // any client, so they cannot be cleaned up per disconnect. Bound the map
                // itself; resetting round-robin position is harmless.
                if (count($this->sharedSubCounters) >= self::MAX_SHARED_SUB_COUNTERS) {
                    $this->sharedSubCounters = [];
                }
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
            $session = $this->sessionManager->get($clientId);
            $version = $session !== null ? $session->protocolVersion : ProtocolVersion::V311;
        }

        $packetId = $qos > 0 ? $this->inFlight->allocatePacketId($clientId) : null;

        $topicName = $originalPacket->topicName;
        $aliasInfo = null;
        if ($version === ProtocolVersion::V50 && $connection !== null) {
            $aliasInfo = $connection->getOrCreateOutgoingTopicAlias($originalPacket->topicName);

            // A new alias must carry the full topic name so the client can record it;
            // an established one is sent as the alias alone.
            if ($aliasInfo !== null && !$aliasInfo['isNew']) {
                $topicName = '';
            }
        }

        $properties = null;
        if ($version === ProtocolVersion::V50) {
            $properties = new PropertyCollection();

            if ($originalPacket->properties !== null) {
                $this->forwardProperties($originalPacket->properties, $properties);
            }

            if ($aliasInfo !== null) {
                $properties->set(PropertyId::TopicAlias, $aliasInfo['alias']);
            }

            foreach ($subscriptionIds as $subId) {
                $properties->set(PropertyId::SubscriptionIdentifier, $subId);
            }
        }

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

        // Deliberately not exceedsClientPacketSize(): this path keys on $version, which
        // for an offline client comes from the saved session rather than the connection.
        // The two look like the same check and are not.
        if ($version === ProtocolVersion::V50 && $connection !== null && $connection->getClientMaximumPacketSize() > 0) {
            $encoded = $this->packetEncoder->encode($deliverPacket);
            if (strlen($encoded) > $connection->getClientMaximumPacketSize()) {
                return; // Silently drop — must not send packets exceeding client's max
            }
        }

        if ($connection !== null && $connection->isConnected()) {
            if ($qos > 0 && $connection->getUnackedOutgoing() >= $connection->getReceiveMaximum()) {
                $session = $this->sessionManager->get($clientId);
                if ($session === null) {
                    $session = $this->sessionManager->getOrCreate($clientId);
                }
                $this->queuePendingMessage($session, $deliverPacket);
                return;
            }

            $this->inFlight->sendTracked($connection, $deliverPacket, $clientId);
        } else {
            $session = $this->sessionManager->get($clientId);
            if ($session !== null) {
                $this->queuePendingMessage($session, $deliverPacket);
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
                $packetId = $this->inFlight->allocatePacketId($clientId);
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
            }
            $this->inFlight->sendTracked($connection, $msg, $clientId);
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
            if ($connection->getSessionExpiryInterval() > 0) {
                return true;
            }
            // SEI=0 means the session expires the moment the client disconnects, but a
            // client that also sent cleanStart=false is asking to keep it, so honour that.
            return !$connection->isCleanSession();
        }

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

}
