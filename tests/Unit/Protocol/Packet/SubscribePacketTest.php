<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Protocol\Packet;

use PhpMqtt\Broker\Exception\ProtocolViolationException;
use PhpMqtt\Broker\Protocol\Packet\SubackPacket;
use PhpMqtt\Broker\Protocol\Packet\SubscribePacket;
use PhpMqtt\Broker\Protocol\Packet\UnsubscribePacket;
use PhpMqtt\Broker\Protocol\PacketEncoder;
use PhpMqtt\Broker\Protocol\PacketFactory;
use PHPUnit\Framework\TestCase;

final class SubscribePacketTest extends TestCase
{
    private PacketEncoder $encoder;
    private PacketFactory $factory;

    protected function setUp(): void
    {
        $this->encoder = new PacketEncoder();
        $this->factory = new PacketFactory();
    }

    public function testSubscribeSingleTopic(): void
    {
        $packet = new SubscribePacket(
            packetId: 10,
            subscriptions: [['topic' => 'test/topic', 'qos' => 1]],
        );

        $raw = $this->encoder->encode($packet);
        $decoded = $this->factory->decode($raw);

        self::assertInstanceOf(SubscribePacket::class, $decoded);
        self::assertSame(10, $decoded->packetId);
        self::assertCount(1, $decoded->subscriptions);
        self::assertSame('test/topic', $decoded->subscriptions[0]['topic']);
        self::assertSame(1, $decoded->subscriptions[0]['qos']);
    }

    public function testSubscribeMultipleTopics(): void
    {
        $packet = new SubscribePacket(
            packetId: 20,
            subscriptions: [
                ['topic' => 'a/b', 'qos' => 0],
                ['topic' => 'c/d', 'qos' => 1],
                ['topic' => 'e/f', 'qos' => 2],
            ],
        );

        $raw = $this->encoder->encode($packet);
        $decoded = $this->factory->decode($raw);

        self::assertInstanceOf(SubscribePacket::class, $decoded);
        self::assertCount(3, $decoded->subscriptions);
        self::assertSame(2, $decoded->subscriptions[2]['qos']);
    }

    public function testSubscribeFixedHeaderFlags(): void
    {
        $packet = new SubscribePacket(
            packetId: 1,
            subscriptions: [['topic' => 't', 'qos' => 0]],
        );

        $raw = $this->encoder->encode($packet);
        // SUBSCRIBE first byte: type 8 << 4 | flags 0x02 = 0x82
        self::assertSame(0x82, ord($raw[0]));
    }

    public function testSubscribeEmptySubscriptionsThrows(): void
    {
        $this->expectException(ProtocolViolationException::class);
        new SubscribePacket(packetId: 1, subscriptions: []);
    }

    public function testSubscribeInvalidQoSThrows(): void
    {
        $this->expectException(ProtocolViolationException::class);

        // Manually craft data with QoS 3
        $data = "\x00\x01" // packet ID = 1
              . "\x00\x01t" // topic "t"
              . "\x03";     // QoS 3 (invalid)

        SubscribePacket::decode($data);
    }

    // --- SUBACK ---

    public function testSubackRoundTrip(): void
    {
        $packet = new SubackPacket(
            packetId: 10,
            returnCodes: [0x00, 0x01, 0x02, 0x80],
        );

        $raw = $this->encoder->encode($packet);
        $decoded = $this->factory->decode($raw);

        self::assertInstanceOf(SubackPacket::class, $decoded);
        self::assertSame(10, $decoded->packetId);
        self::assertSame([0x00, 0x01, 0x02, 0x80], $decoded->returnCodes);
    }

    // --- UNSUBSCRIBE ---

    public function testUnsubscribeRoundTrip(): void
    {
        $packet = new UnsubscribePacket(
            packetId: 5,
            topicFilters: ['a/b', 'c/d'],
        );

        $raw = $this->encoder->encode($packet);
        $decoded = $this->factory->decode($raw);

        self::assertInstanceOf(UnsubscribePacket::class, $decoded);
        self::assertSame(5, $decoded->packetId);
        self::assertSame(['a/b', 'c/d'], $decoded->topicFilters);
    }

    public function testUnsubscribeFixedHeaderFlags(): void
    {
        $packet = new UnsubscribePacket(packetId: 1, topicFilters: ['t']);
        $raw = $this->encoder->encode($packet);
        // UNSUBSCRIBE first byte: type 10 << 4 | flags 0x02 = 0xA2
        self::assertSame(0xA2, ord($raw[0]));
    }

    public function testUnsubscribeEmptyThrows(): void
    {
        $this->expectException(ProtocolViolationException::class);
        new UnsubscribePacket(packetId: 1, topicFilters: []);
    }
}
