<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Protocol;

use PhpMqtt\Broker\Exception\MalformedPacketException;
use PhpMqtt\Broker\Exception\ProtocolViolationException;
use PhpMqtt\Broker\Protocol\PacketFactory;
use PHPUnit\Framework\TestCase;

final class PacketFactoryTest extends TestCase
{
    private PacketFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new PacketFactory();
    }

    public function testInvalidPacketTypeThrows(): void
    {
        $this->expectException(MalformedPacketException::class);
        // Type 0 is reserved/invalid
        $this->factory->decode("\x00\x00");
    }

    public function testPacketTooShortThrows(): void
    {
        $this->expectException(MalformedPacketException::class);
        $this->factory->decode("\xC0");
    }

    public function testWrongFixedHeaderFlagsThrows(): void
    {
        $this->expectException(ProtocolViolationException::class);
        // SUBSCRIBE with flags 0x00 instead of 0x02
        // Type 8 << 4 | 0x00 = 0x80
        $this->factory->decode("\x80\x05\x00\x01\x00\x01t\x00");
    }

    public function testAuthPacketInV311Throws(): void
    {
        $this->expectException(ProtocolViolationException::class);
        // AUTH = type 15, first byte = 0xF0
        $this->factory->decode("\xF0\x00");
    }
}
