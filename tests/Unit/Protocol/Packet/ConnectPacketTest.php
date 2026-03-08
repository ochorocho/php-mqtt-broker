<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Protocol\Packet;

use PhpMqtt\Broker\Exception\ProtocolViolationException;
use PhpMqtt\Broker\Protocol\Packet\ConnectPacket;
use PhpMqtt\Broker\Protocol\PacketEncoder;
use PhpMqtt\Broker\Protocol\PacketFactory;
use PHPUnit\Framework\TestCase;

final class ConnectPacketTest extends TestCase
{
    public function testDecodeMinimalConnect(): void
    {
        $packet = new ConnectPacket(
            protocolName: 'MQTT',
            protocolLevel: 4,
            cleanSession: true,
            keepAlive: 60,
            clientId: 'test-client',
        );

        $encoded = $packet->encode();
        $decoded = ConnectPacket::decode($encoded);

        self::assertSame('MQTT', $decoded->protocolName);
        self::assertSame(4, $decoded->protocolLevel);
        self::assertTrue($decoded->cleanSession);
        self::assertSame(60, $decoded->keepAlive);
        self::assertSame('test-client', $decoded->clientId);
        self::assertFalse($decoded->hasWill);
        self::assertFalse($decoded->hasUsername);
        self::assertFalse($decoded->hasPassword);
    }

    public function testDecodeFullConnect(): void
    {
        $packet = new ConnectPacket(
            protocolName: 'MQTT',
            protocolLevel: 4,
            cleanSession: false,
            keepAlive: 120,
            clientId: 'full-client',
            hasWill: true,
            willQoS: 2,
            willRetain: true,
            willTopic: 'will/topic',
            willPayload: 'goodbye',
            hasUsername: true,
            username: 'user',
            hasPassword: true,
            password: 'pass',
        );

        $encoded = $packet->encode();
        $decoded = ConnectPacket::decode($encoded);

        self::assertSame('MQTT', $decoded->protocolName);
        self::assertSame(4, $decoded->protocolLevel);
        self::assertFalse($decoded->cleanSession);
        self::assertSame(120, $decoded->keepAlive);
        self::assertSame('full-client', $decoded->clientId);
        self::assertTrue($decoded->hasWill);
        self::assertSame(2, $decoded->willQoS);
        self::assertTrue($decoded->willRetain);
        self::assertSame('will/topic', $decoded->willTopic);
        self::assertSame('goodbye', $decoded->willPayload);
        self::assertTrue($decoded->hasUsername);
        self::assertSame('user', $decoded->username);
        self::assertTrue($decoded->hasPassword);
        self::assertSame('pass', $decoded->password);
    }

    public function testReservedBitMustBeZero(): void
    {
        $this->expectException(ProtocolViolationException::class);

        // Manually craft bytes with reserved bit set
        $data = "\x00\x04MQTT" // protocol name
              . "\x04"          // protocol level
              . "\x03"          // connect flags: clean session + reserved bit
              . "\x00\x3C"     // keepalive 60
              . "\x00\x04test"; // client id

        ConnectPacket::decode($data);
    }

    public function testRoundTripViaFactory(): void
    {
        $factory = new PacketFactory();
        $encoder = new PacketEncoder();

        $packet = new ConnectPacket(
            protocolName: 'MQTT',
            protocolLevel: 4,
            cleanSession: true,
            keepAlive: 30,
            clientId: 'roundtrip',
        );

        $raw = $encoder->encode($packet);
        $decoded = $factory->decode($raw);

        self::assertInstanceOf(ConnectPacket::class, $decoded);
        self::assertSame('roundtrip', $decoded->clientId);
    }

    public function testEmptyClientId(): void
    {
        $packet = new ConnectPacket(
            protocolName: 'MQTT',
            protocolLevel: 4,
            cleanSession: true,
            keepAlive: 60,
            clientId: '',
        );

        $encoded = $packet->encode();
        $decoded = ConnectPacket::decode($encoded);

        self::assertSame('', $decoded->clientId);
    }

    public function testWillQoSWithoutWillFlagThrows(): void
    {
        $this->expectException(ProtocolViolationException::class);

        // Will QoS = 1 but Will Flag = 0
        $data = "\x00\x04MQTT"
              . "\x04"
              . "\x0A" // flags: clean session (0x02) + will QoS 1 (0x08) but no will flag
              . "\x00\x3C"
              . "\x00\x04test";

        ConnectPacket::decode($data);
    }
}
