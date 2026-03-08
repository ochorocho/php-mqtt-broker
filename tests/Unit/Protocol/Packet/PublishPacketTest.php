<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Protocol\Packet;

use PhpMqtt\Broker\Exception\ProtocolViolationException;
use PhpMqtt\Broker\Protocol\Packet\PublishPacket;
use PhpMqtt\Broker\Protocol\PacketEncoder;
use PhpMqtt\Broker\Protocol\PacketFactory;
use PHPUnit\Framework\TestCase;

final class PublishPacketTest extends TestCase
{
    public function testQoS0NoPacketIdentifier(): void
    {
        $packet = new PublishPacket(
            topicName: 'test/topic',
            payload: 'hello',
            qos: 0,
        );

        self::assertNull($packet->packetId);

        $encoded = $packet->encode();
        $decoded = PublishPacket::decode($encoded, $packet->getFixedHeaderFlags());

        self::assertSame('test/topic', $decoded->topicName);
        self::assertSame('hello', $decoded->payload);
        self::assertSame(0, $decoded->qos);
        self::assertNull($decoded->packetId);
    }

    public function testQoS1HasPacketIdentifier(): void
    {
        $packet = new PublishPacket(
            topicName: 'test/topic',
            payload: 'hello',
            qos: 1,
            packetId: 42,
        );

        $encoded = $packet->encode();
        $decoded = PublishPacket::decode($encoded, $packet->getFixedHeaderFlags());

        self::assertSame(1, $decoded->qos);
        self::assertSame(42, $decoded->packetId);
    }

    public function testQoS2HasPacketIdentifier(): void
    {
        $packet = new PublishPacket(
            topicName: 'sensor/data',
            payload: '{"temp":22.5}',
            qos: 2,
            packetId: 65535,
        );

        $encoded = $packet->encode();
        $decoded = PublishPacket::decode($encoded, $packet->getFixedHeaderFlags());

        self::assertSame(2, $decoded->qos);
        self::assertSame(65535, $decoded->packetId);
    }

    public function testQoS0WithPacketIdThrows(): void
    {
        $this->expectException(ProtocolViolationException::class);
        new PublishPacket(topicName: 'test', payload: '', qos: 0, packetId: 1);
    }

    public function testQoS1WithoutPacketIdThrows(): void
    {
        $this->expectException(ProtocolViolationException::class);
        new PublishPacket(topicName: 'test', payload: '', qos: 1);
    }

    public function testEmptyPayload(): void
    {
        $packet = new PublishPacket(topicName: 'test', payload: '', qos: 0);
        $encoded = $packet->encode();
        $decoded = PublishPacket::decode($encoded, $packet->getFixedHeaderFlags());

        self::assertSame('', $decoded->payload);
    }

    public function testRetainFlag(): void
    {
        $packet = new PublishPacket(
            topicName: 'test',
            payload: 'retained',
            qos: 0,
            retain: true,
        );

        self::assertSame(0x01, $packet->getFixedHeaderFlags());
    }

    public function testDupFlag(): void
    {
        $packet = new PublishPacket(
            topicName: 'test',
            payload: 'data',
            qos: 1,
            dup: true,
            packetId: 1,
        );

        self::assertSame(0x0A, $packet->getFixedHeaderFlags()); // dup(8) + qos1(2)
    }

    public function testQoS3Throws(): void
    {
        $this->expectException(ProtocolViolationException::class);
        PublishPacket::decode("\x00\x04test", 0x06); // flags = QoS 3
    }

    public function testRoundTripViaFactoryEncoder(): void
    {
        $encoder = new PacketEncoder();
        $factory = new PacketFactory();

        $packet = new PublishPacket(
            topicName: 'my/topic',
            payload: 'data here',
            qos: 1,
            dup: false,
            retain: true,
            packetId: 100,
        );

        $raw = $encoder->encode($packet);
        $decoded = $factory->decode($raw);

        self::assertInstanceOf(PublishPacket::class, $decoded);
        self::assertSame('my/topic', $decoded->topicName);
        self::assertSame('data here', $decoded->payload);
        self::assertSame(1, $decoded->qos);
        self::assertTrue($decoded->retain);
        self::assertSame(100, $decoded->packetId);
    }
}
