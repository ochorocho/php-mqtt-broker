<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Handler;

use PhpMqtt\Broker\Auth\AuthenticatorInterface;
use PhpMqtt\Broker\Configuration;
use PhpMqtt\Broker\Connection\Connection;
use PhpMqtt\Broker\Connection\ConnectionManager;
use PhpMqtt\Broker\Handler\PacketHandler;
use PhpMqtt\Broker\Protocol\Packet\ConnectPacket;
use PhpMqtt\Broker\Protocol\Packet\PacketInterface;
use PhpMqtt\Broker\Protocol\PacketEncoder;
use PhpMqtt\Broker\Protocol\PacketFactory;
use PhpMqtt\Broker\Protocol\ProtocolVersion;
use PhpMqtt\Broker\Subscription\SubscriptionManager;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;

/**
 * Shared scaffolding for the PacketHandler tests.
 *
 * Assertions go through the public API and through the bytes the broker actually
 * writes, decoded back into packets. Nothing here reaches into private state, so the
 * tests describe behaviour a client can observe rather than the current implementation.
 */
abstract class PacketHandlerTestCase extends TestCase
{
    protected ConnectionManager $connections;
    protected SubscriptionManager $subscriptions;
    protected PacketHandler $handler;
    protected RecordingAuthenticator $authenticator;
    protected RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->makeHandler();
    }

    protected function makeHandler(
        ?AuthenticatorInterface $authenticator = null,
        ?Configuration $config = null,
        ?RecordingLogger $logger = null,
    ): void {
        $this->connections = new ConnectionManager();
        $this->subscriptions = new SubscriptionManager();
        $this->authenticator = $authenticator instanceof RecordingAuthenticator
            ? $authenticator
            : new RecordingAuthenticator();
        $this->logger = $logger ?? new RecordingLogger();

        $this->handler = new PacketHandler(
            connectionManager: $this->connections,
            subscriptionManager: $this->subscriptions,
            authenticator: $authenticator ?? $this->authenticator,
            loop: Loop::get(),
            packetEncoder: new PacketEncoder(),
            logger: $this->logger,
            config: $config ?? new Configuration(),
        );
    }

    protected function connection(): Connection
    {
        return new Connection(new RecordingStream(), new PacketEncoder(), Loop::get());
    }

    /**
     * Connect a client and register it, the way Broker does once CONNECT succeeds.
     */
    protected function connect(
        string $clientId,
        bool $cleanSession = true,
        ProtocolVersion $version = ProtocolVersion::V311,
        ?string $username = null,
        ?ConnectPacket $packet = null,
    ): Connection {
        $connection = $this->connection();

        $this->handler->handle($connection, $packet ?? new ConnectPacket(
            protocolName: 'MQTT',
            protocolLevel: $version->value,
            cleanSession: $cleanSession,
            keepAlive: 60,
            clientId: $clientId,
            hasUsername: $username !== null,
            username: $username,
        ));

        if ($connection->isConnected()) {
            $this->connections->register($clientId, $connection);
        }

        return $connection;
    }

    protected function stream(Connection $connection): RecordingStream
    {
        $stream = $connection->getStream();
        self::assertInstanceOf(RecordingStream::class, $stream);

        return $stream;
    }

    /**
     * Every packet written to this connection, decoded.
     *
     * @return list<PacketInterface>
     */
    protected function sentPackets(Connection $connection, ?ProtocolVersion $version = null): array
    {
        $factory = new PacketFactory();
        $packets = [];

        foreach ($this->stream($connection)->writes as $frame) {
            $packets[] = $factory->decode($frame, $version ?? $connection->getProtocolVersion());
        }

        return $packets;
    }

    /**
     * The last packet of the given type written to this connection.
     *
     * @template T of PacketInterface
     * @param class-string<T> $type
     * @return T
     */
    protected function lastSent(Connection $connection, string $type, ?ProtocolVersion $version = null): object
    {
        $matches = array_values(array_filter(
            $this->sentPackets($connection, $version),
            static fn(PacketInterface $packet): bool => $packet instanceof $type,
        ));

        self::assertNotSame([], $matches, sprintf('Expected a %s to have been sent', $type));

        return $matches[count($matches) - 1];
    }

    protected function countSent(Connection $connection, string $type, ?ProtocolVersion $version = null): int
    {
        return count(array_filter(
            $this->sentPackets($connection, $version),
            static fn(PacketInterface $packet): bool => $packet instanceof $type,
        ));
    }
}
