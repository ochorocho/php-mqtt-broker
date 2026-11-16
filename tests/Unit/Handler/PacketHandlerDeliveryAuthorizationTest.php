<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Handler;

use PhpMqtt\Broker\Auth\AuthenticatorInterface;
use PhpMqtt\Broker\Auth\DeliveryAuthorizerInterface;
use PhpMqtt\Broker\Connection\Connection;
use PhpMqtt\Broker\Protocol\Packet\PublishPacket;
use PhpMqtt\Broker\Protocol\Packet\SubscribePacket;

/**
 * Authorizing a subscription once means a wildcard grant covers every topic beneath
 * it, for good. An authorizer that also implements DeliveryAuthorizerInterface is asked
 * about each message instead, wherever it is delivered from.
 */
final class PacketHandlerDeliveryAuthorizationTest extends PacketHandlerTestCase
{
    private ReadAuthorizer $authorizer;

    protected function setUp(): void
    {
        $this->authorizer = new ReadAuthorizer();
        $this->makeHandler($this->authorizer);
    }

    public function testADeniedDeliveryIsNotSent(): void
    {
        $this->authorizer->deniedTopics = ['secret/plans'];
        $subscriber = $this->subscriber();

        $this->publish('secret/plans');
        $this->publish('public/news');

        self::assertSame(['public/news'], $this->topicsOn($subscriber));
    }

    public function testRetainedMessagesAreCheckedToo(): void
    {
        $this->publish('secret/plans', retain: true);
        $this->authorizer->deniedTopics = ['secret/plans'];

        $subscriber = $this->subscriber();

        self::assertSame([], $this->topicsOn($subscriber));
    }

    public function testQueuedMessagesAreCheckedWhenTheyAreSent(): void
    {
        $subscriber = $this->subscriber(persistent: true);
        $this->handler->handleDisconnect($subscriber, false);
        $this->connections->remove($subscriber);
        $this->publish('secret/plans', qos: 1);

        // Allowed when it was queued, revoked before the client came back.
        $this->authorizer->deniedTopics = ['secret/plans'];
        $returning = $this->connect('alice-phone', cleanSession: false, username: 'alice');

        self::assertSame([], $this->topicsOn($returning));
    }

    public function testTheCheckIsMadeForTheSubscriberNotThePublisher(): void
    {
        $this->subscriber();

        $this->publish('a/b');

        self::assertContains('alice-phone:alice:a/b', $this->authorizer->asked);
    }

    public function testAFailingAuthorizerDeniesTheDeliveryAndNothingElse(): void
    {
        $this->authorizer->throws = true;
        $subscriber = $this->subscriber();

        $publisher = $this->publish('a/b');

        self::assertSame([], $this->topicsOn($subscriber));
        self::assertTrue($publisher->isConnected(), 'The publisher did nothing wrong');
        self::assertNotSame([], $this->logger->messagesAt('error'));
    }

    private function subscriber(bool $persistent = false): Connection
    {
        $connection = $this->connect('alice-phone', cleanSession: !$persistent, username: 'alice');
        $this->handler->handle($connection, new SubscribePacket(1, [['topic' => '#', 'qos' => 1]]));

        return $connection;
    }

    private function publish(string $topic, bool $retain = false, int $qos = 0): Connection
    {
        $publisher = $this->connect('publisher', username: 'bob');
        $this->handler->handle($publisher, new PublishPacket(
            topicName: $topic,
            payload: 'x',
            qos: $qos,
            retain: $retain,
            packetId: $qos > 0 ? 1 : null,
        ));

        return $publisher;
    }

    /**
     * @return list<string>
     */
    private function topicsOn(Connection $connection): array
    {
        return array_values(array_map(
            static fn(PublishPacket $p): string => $p->topicName,
            array_filter($this->sentPackets($connection), static fn($p): bool => $p instanceof PublishPacket),
        ));
    }
}

final class ReadAuthorizer implements AuthenticatorInterface, DeliveryAuthorizerInterface
{
    /** @var list<string> */
    public array $deniedTopics = [];

    /** @var list<string> "clientId:username:topic" for every delivery asked about */
    public array $asked = [];

    public bool $throws = false;

    public function authenticate(string $clientId, ?string $username, ?string $password): bool
    {
        return true;
    }

    public function canUseClientId(string $clientId, ?string $username): bool
    {
        return true;
    }

    public function canSubscribe(string $clientId, ?string $username, string $topicFilter): bool
    {
        return true;
    }

    public function canPublish(string $clientId, ?string $username, string $topicName): bool
    {
        return true;
    }

    public function canReceive(string $clientId, ?string $username, string $topicName): bool
    {
        $this->asked[] = $clientId . ':' . ($username ?? '<none>') . ':' . $topicName;
        if ($this->throws) {
            throw new \RuntimeException('ACL backend unavailable');
        }

        return !in_array($topicName, $this->deniedTopics, true);
    }
}
