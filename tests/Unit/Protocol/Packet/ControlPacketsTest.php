<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Protocol\Packet;

use PhpMqtt\Broker\Protocol\Packet\DisconnectPacket;
use PhpMqtt\Broker\Protocol\Packet\PingreqPacket;
use PhpMqtt\Broker\Protocol\Packet\PingrespPacket;
use PhpMqtt\Broker\Protocol\PacketEncoder;
use PhpMqtt\Broker\Protocol\PacketFactory;
use PHPUnit\Framework\TestCase;

final class ControlPacketsTest extends TestCase
{
    private PacketEncoder $encoder;
    private PacketFactory $factory;

    protected function setUp(): void
    {
        $this->encoder = new PacketEncoder();
        $this->factory = new PacketFactory();
    }

    public function testPingreqEncoding(): void
    {
        $packet = new PingreqPacket();
        $raw = $this->encoder->encode($packet);
        self::assertSame("\xC0\x00", $raw);
    }

    public function testPingreqDecoding(): void
    {
        $decoded = $this->factory->decode("\xC0\x00");
        self::assertInstanceOf(PingreqPacket::class, $decoded);
    }

    public function testPingrespEncoding(): void
    {
        $packet = new PingrespPacket();
        $raw = $this->encoder->encode($packet);
        self::assertSame("\xD0\x00", $raw);
    }

    public function testPingrespDecoding(): void
    {
        $decoded = $this->factory->decode("\xD0\x00");
        self::assertInstanceOf(PingrespPacket::class, $decoded);
    }

    public function testDisconnectEncoding(): void
    {
        $packet = new DisconnectPacket();
        $raw = $this->encoder->encode($packet);
        self::assertSame("\xE0\x00", $raw);
    }

    public function testDisconnectDecoding(): void
    {
        $decoded = $this->factory->decode("\xE0\x00");
        self::assertInstanceOf(DisconnectPacket::class, $decoded);
    }
}
