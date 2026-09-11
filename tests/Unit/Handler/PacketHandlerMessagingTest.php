<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Handler;

use PhpMqtt\Broker\Protocol\Packet\PubackPacket;
use PhpMqtt\Broker\Protocol\Packet\PubcompPacket;
use PhpMqtt\Broker\Protocol\Packet\PublishPacket;
use PhpMqtt\Broker\Protocol\Packet\PubrecPacket;
use PhpMqtt\Broker\Protocol\Packet\PubrelPacket;
use PhpMqtt\Broker\Protocol\Packet\SubackPacket;
use PhpMqtt\Broker\Protocol\Packet\SubscribePacket;
use PhpMqtt\Broker\Protocol\ProtocolVersion;

/**
 * Routing and delivery: who receives a message, how many times, and when.
 */
final class PacketHandlerMessagingTest extends PacketHandlerTestCase
{
    public function testQos0MessageReachesASubscriber(): void
    {
        $subscriber = $this->connect('subscriber');
        $this->handler->handle($subscriber, new SubscribePacket(1, [['topic' => 'a/b', 'qos' => 0]]));

        $publisher = $this->connect('publisher');
        $this->handler->handle($publisher, new PublishPacket(topicName: 'a/b', payload: 'hello'));

        $delivered = $this->lastSent($subscriber, PublishPacket::class);
        self::assertSame('a/b', $delivered->topicName);
        self::assertSame('hello', $delivered->payload);
    }

    public function testQos1PublishIsAcknowledged(): void
    {
        $publisher = $this->connect('publisher');
        $this->handler->handle($publisher, new PublishPacket(
            topicName: 'a/b',
            payload: 'x',
            qos: 1,
            packetId: 11,
        ));

        self::assertSame(11, $this->lastSent($publisher, PubackPacket::class)->packetId);
    }

    public function testQos2DefersDeliveryUntilPubrel(): void
    {
        $subscriber = $this->connect('subscriber');
        $this->handler->handle($subscriber, new SubscribePacket(1, [['topic' => 'a/b', 'qos' => 0]]));
        $before = $this->countSent($subscriber, PublishPacket::class);

        $publisher = $this->connect('publisher');
        $this->handler->handle($publisher, new PublishPacket(
            topicName: 'a/b',
            payload: 'exactly-once',
            qos: 2,
            packetId: 9,
        ));

        // PUBREC only; the message must not reach subscribers yet, because the
        // publisher has not yet confirmed it wants it delivered.
        self::assertSame(9, $this->lastSent($publisher, PubrecPacket::class)->packetId);
        self::assertSame($before, $this->countSent($subscriber, PublishPacket::class));

        $this->handler->handle($publisher, new PubrelPacket(9));

        self::assertSame(9, $this->lastSent($publisher, PubcompPacket::class)->packetId);
        self::assertSame($before + 1, $this->countSent($subscriber, PublishPacket::class));
        self::assertSame('exactly-once', $this->lastSent($subscriber, PublishPacket::class)->payload);
    }

    public function testWildcardSubscribersReceiveMatchingTopics(): void
    {
        $single = $this->connect('single');
        $this->handler->handle($single, new SubscribePacket(1, [['topic' => 'sport/+', 'qos' => 0]]));

        $multi = $this->connect('multi');
        $this->handler->handle($multi, new SubscribePacket(1, [['topic' => 'sport/#', 'qos' => 0]]));

        $publisher = $this->connect('publisher');
        $this->handler->handle($publisher, new PublishPacket(topicName: 'sport/tennis', payload: 'game'));

        self::assertSame(1, $this->countSent($single, PublishPacket::class));
        self::assertSame(1, $this->countSent($multi, PublishPacket::class));
    }

    public function testOverlappingSubscriptionsDeliverOnlyOnce(): void
    {
        $subscriber = $this->connect('subscriber');
        $this->handler->handle($subscriber, new SubscribePacket(1, [
            ['topic' => 'sport/+', 'qos' => 0],
            ['topic' => 'sport/#', 'qos' => 0],
            ['topic' => 'sport/tennis', 'qos' => 0],
        ]));

        $publisher = $this->connect('publisher');
        $this->handler->handle($publisher, new PublishPacket(topicName: 'sport/tennis', payload: 'game'));

        self::assertSame(
            1,
            $this->countSent($subscriber, PublishPacket::class),
            'A client matching through several filters is still one subscriber',
        );
    }

    public function testDollarTopicsAreHiddenFromWildcards(): void
    {
        $subscriber = $this->connect('subscriber');
        $this->handler->handle($subscriber, new SubscribePacket(1, [['topic' => '#', 'qos' => 0]]));

        $publisher = $this->connect('publisher');
        $this->handler->handle($publisher, new PublishPacket(topicName: '$SYS/uptime', payload: '1'));

        self::assertSame(0, $this->countSent($subscriber, PublishPacket::class));
    }

    public function testDollarTopicsReachAnExplicitSubscriber(): void
    {
        $subscriber = $this->connect('subscriber');
        $this->handler->handle($subscriber, new SubscribePacket(1, [['topic' => '$SYS/#', 'qos' => 0]]));

        $publisher = $this->connect('publisher');
        $this->handler->handle($publisher, new PublishPacket(topicName: '$SYS/uptime', payload: '1'));

        self::assertSame(1, $this->countSent($subscriber, PublishPacket::class));
    }

    public function testNoLocalSuppressesEchoToThePublisher(): void
    {
        $connection = $this->connect('loopback', version: ProtocolVersion::V50);
        $this->handler->handle($connection, new SubscribePacket(
            packetId: 1,
            subscriptions: [['topic' => 'echo', 'qos' => 0, 'noLocal' => true]],
            protocolVersion: ProtocolVersion::V50,
        ));
        $before = $this->countSent($connection, PublishPacket::class);

        $this->handler->handle($connection, new PublishPacket(
            topicName: 'echo',
            payload: 'mine',
            protocolVersion: ProtocolVersion::V50,
        ));

        self::assertSame($before, $this->countSent($connection, PublishPacket::class));
    }

    public function testRetainedMessageIsDeliveredToALaterSubscriber(): void
    {
        $publisher = $this->connect('publisher');
        $this->handler->handle($publisher, new PublishPacket(
            topicName: 'device/state',
            payload: 'online',
            retain: true,
        ));

        $subscriber = $this->connect('subscriber');
        $this->handler->handle($subscriber, new SubscribePacket(1, [['topic' => 'device/+', 'qos' => 0]]));

        $delivered = $this->lastSent($subscriber, PublishPacket::class);
        self::assertSame('online', $delivered->payload);
        self::assertTrue($delivered->retain, 'A retained message must be flagged as retained');
    }

    public function testEmptyRetainedPayloadClearsTheRetainedMessage(): void
    {
        $publisher = $this->connect('publisher');
        $this->handler->handle($publisher, new PublishPacket(
            topicName: 'device/state',
            payload: 'online',
            retain: true,
        ));
        self::assertSame(1, $this->handler->getRetainedMessages()->count());

        $this->handler->handle($publisher, new PublishPacket(
            topicName: 'device/state',
            payload: '',
            retain: true,
        ));

        self::assertSame(0, $this->handler->getRetainedMessages()->count());
    }

    public function testRetainedMessagesAreNotSentForARegularPublish(): void
    {
        $subscriber = $this->connect('subscriber');
        $this->handler->handle($subscriber, new SubscribePacket(1, [['topic' => 'live', 'qos' => 0]]));

        $publisher = $this->connect('publisher');
        $this->handler->handle($publisher, new PublishPacket(topicName: 'live', payload: 'now'));

        // retain is a property of storage, not of this delivery.
        self::assertFalse($this->lastSent($subscriber, PublishPacket::class)->retain);
    }

    public function testDeliveredQosIsTheLowerOfPublishAndSubscription(): void
    {
        $subscriber = $this->connect('subscriber');
        $this->handler->handle($subscriber, new SubscribePacket(1, [['topic' => 'a/b', 'qos' => 0]]));

        $publisher = $this->connect('publisher');
        $this->handler->handle($publisher, new PublishPacket(
            topicName: 'a/b',
            payload: 'x',
            qos: 1,
            packetId: 3,
        ));

        self::assertSame(0, $this->lastSent($subscriber, PublishPacket::class)->qos);
    }

    public function testMalformedFiltersAreRefusedWhileValidOnesAreGranted(): void
    {
        $connection = $this->connect('client');
        $this->handler->handle($connection, new SubscribePacket(1, [
            ['topic' => 'a/#/b', 'qos' => 0],
            ['topic' => 'sp+rt', 'qos' => 0],
            ['topic' => '$share/', 'qos' => 0],
            ['topic' => 'valid/#', 'qos' => 2],
        ]));

        self::assertSame(
            [0x80, 0x80, 0x80, 0x02],
            $this->lastSent($connection, SubackPacket::class)->returnCodes,
        );
    }

    public function testSharedSubscriptionDeliversToOneMemberOfTheGroup(): void
    {
        $first = $this->connect('worker-1', version: ProtocolVersion::V50);
        $this->handler->handle($first, new SubscribePacket(
            packetId: 1,
            subscriptions: [['topic' => '$share/workers/jobs', 'qos' => 0]],
            protocolVersion: ProtocolVersion::V50,
        ));

        $second = $this->connect('worker-2', version: ProtocolVersion::V50);
        $this->handler->handle($second, new SubscribePacket(
            packetId: 1,
            subscriptions: [['topic' => '$share/workers/jobs', 'qos' => 0]],
            protocolVersion: ProtocolVersion::V50,
        ));

        $publisher = $this->connect('publisher');
        $this->handler->handle($publisher, new PublishPacket(topicName: 'jobs', payload: 'task'));

        $total = $this->countSent($first, PublishPacket::class, ProtocolVersion::V50)
            + $this->countSent($second, PublishPacket::class, ProtocolVersion::V50);

        self::assertSame(1, $total, 'A shared group receives each message once, not once per member');
    }
}
