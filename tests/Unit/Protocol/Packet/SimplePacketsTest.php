<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Protocol\Packet;

use PhpMqtt\Broker\Protocol\Packet\ConnackPacket;
use PhpMqtt\Broker\Protocol\Packet\PubackPacket;
use PhpMqtt\Broker\Protocol\Packet\PubcompPacket;
use PhpMqtt\Broker\Protocol\Packet\PubrecPacket;
use PhpMqtt\Broker\Protocol\Packet\PubrelPacket;
use PhpMqtt\Broker\Protocol\Packet\UnsubackPacket;
use PhpMqtt\Broker\Protocol\PacketEncoder;
use PhpMqtt\Broker\Protocol\PacketFactory;
use PHPUnit\Framework\TestCase;

final class SimplePacketsTest extends TestCase
{
    private PacketEncoder $encoder;
    private PacketFactory $factory;

    protected function setUp(): void
    {
        $this->encoder = new PacketEncoder();
        $this->factory = new PacketFactory();
    }

    // --- CONNACK ---

    public function testConnackRoundTrip(): void
    {
        $packet = new ConnackPacket(sessionPresent: true, returnCode: 0);
        $raw = $this->encoder->encode($packet);
        $decoded = $this->factory->decode($raw);

        self::assertInstanceOf(ConnackPacket::class, $decoded);
        self::assertTrue($decoded->sessionPresent);
        self::assertSame(0, $decoded->returnCode);
    }

    public function testConnackRefused(): void
    {
        $packet = new ConnackPacket(sessionPresent: false, returnCode: 5);
        $encoded = $packet->encode();
        $decoded = ConnackPacket::decode($encoded);

        self::assertFalse($decoded->sessionPresent);
        self::assertSame(5, $decoded->returnCode);
    }

    // --- PUBACK ---

    public function testPubackRoundTrip(): void
    {
        $packet = new PubackPacket(packetId: 1234);
        $raw = $this->encoder->encode($packet);
        $decoded = $this->factory->decode($raw);

        self::assertInstanceOf(PubackPacket::class, $decoded);
        self::assertSame(1234, $decoded->packetId);
    }

    // --- PUBREC ---

    public function testPubrecRoundTrip(): void
    {
        $packet = new PubrecPacket(packetId: 5678);
        $raw = $this->encoder->encode($packet);
        $decoded = $this->factory->decode($raw);

        self::assertInstanceOf(PubrecPacket::class, $decoded);
        self::assertSame(5678, $decoded->packetId);
    }

    // --- PUBREL ---

    public function testPubrelRoundTrip(): void
    {
        $packet = new PubrelPacket(packetId: 999);
        $raw = $this->encoder->encode($packet);
        $decoded = $this->factory->decode($raw);

        self::assertInstanceOf(PubrelPacket::class, $decoded);
        self::assertSame(999, $decoded->packetId);
    }

    public function testPubrelFixedHeaderFlags(): void
    {
        $packet = new PubrelPacket(packetId: 1);
        $raw = $this->encoder->encode($packet);
        // PUBREL first byte: type 6 << 4 | flags 0x02 = 0x62
        self::assertSame(0x62, ord($raw[0]));
    }

    // --- PUBCOMP ---

    public function testPubcompRoundTrip(): void
    {
        $packet = new PubcompPacket(packetId: 42);
        $raw = $this->encoder->encode($packet);
        $decoded = $this->factory->decode($raw);

        self::assertInstanceOf(PubcompPacket::class, $decoded);
        self::assertSame(42, $decoded->packetId);
    }

    // --- UNSUBACK ---

    public function testUnsubackRoundTrip(): void
    {
        $packet = new UnsubackPacket(packetId: 777);
        $raw = $this->encoder->encode($packet);
        $decoded = $this->factory->decode($raw);

        self::assertInstanceOf(UnsubackPacket::class, $decoded);
        self::assertSame(777, $decoded->packetId);
    }
}
