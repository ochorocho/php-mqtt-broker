<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Protocol\Packet;

use PhpMqtt\Broker\Protocol\Packet\PacketInterface;
use PhpMqtt\Broker\Protocol\Packet\PubackPacket;
use PhpMqtt\Broker\Protocol\Packet\PubcompPacket;
use PhpMqtt\Broker\Protocol\Packet\PubrecPacket;
use PhpMqtt\Broker\Protocol\Packet\PubrelPacket;
use PhpMqtt\Broker\Protocol\PacketEncoder;
use PhpMqtt\Broker\Protocol\PacketFactory;
use PhpMqtt\Broker\Protocol\ProtocolVersion;
use PhpMqtt\Broker\Protocol\Property\PropertyCollection;
use PhpMqtt\Broker\Protocol\Property\PropertyId;
use PhpMqtt\Broker\Protocol\PubackReasonCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PUBACK, PUBREC, PUBREL and PUBCOMP share one wire format: a packet id, plus an
 * optional reason code and properties in 5.0. These tests cover all four together so
 * the shared behaviour cannot drift between them.
 */
final class AcknowledgementPacketTest extends TestCase
{
    private PacketEncoder $encoder;
    private PacketFactory $factory;

    protected function setUp(): void
    {
        $this->encoder = new PacketEncoder();
        $this->factory = new PacketFactory();
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function ackPackets(): iterable
    {
        yield 'PUBACK' => [PubackPacket::class];
        yield 'PUBREC' => [PubrecPacket::class];
        yield 'PUBREL' => [PubrelPacket::class];
        yield 'PUBCOMP' => [PubcompPacket::class];
    }

    /**
     * @param class-string<PacketInterface> $class
     */
    #[DataProvider('ackPackets')]
    public function testRoundTripsPacketId(string $class): void
    {
        $packet = new $class(packetId: 1234);
        $decoded = $this->factory->decode($this->encoder->encode($packet));

        self::assertInstanceOf($class, $decoded);
        self::assertSame(1234, $decoded->packetId);
    }

    /**
     * @param class-string<PacketInterface> $class
     */
    #[DataProvider('ackPackets')]
    public function testRoundTripsReasonCodeInV5(string $class): void
    {
        $packet = new $class(
            packetId: 7,
            protocolVersion: ProtocolVersion::V50,
            reasonCode: PubackReasonCode::NotAuthorized->value,
        );

        $decoded = $this->factory->decode($this->encoder->encode($packet), ProtocolVersion::V50);

        self::assertInstanceOf($class, $decoded);
        self::assertSame(7, $decoded->packetId);
        self::assertSame(PubackReasonCode::NotAuthorized->value, $decoded->reasonCode);
    }

    /**
     * @param class-string<PacketInterface> $class
     */
    #[DataProvider('ackPackets')]
    public function testRoundTripsPropertiesInV5(string $class): void
    {
        $properties = new PropertyCollection();
        $properties->set(PropertyId::ReasonString, 'nope');

        $packet = new $class(
            packetId: 9,
            protocolVersion: ProtocolVersion::V50,
            reasonCode: PubackReasonCode::UnspecifiedError->value,
            properties: $properties,
        );

        $decoded = $this->factory->decode($this->encoder->encode($packet), ProtocolVersion::V50);

        self::assertInstanceOf($class, $decoded);
        self::assertSame(PubackReasonCode::UnspecifiedError->value, $decoded->reasonCode);
        self::assertNotNull($decoded->properties);
        self::assertSame('nope', $decoded->properties->get(PropertyId::ReasonString));
    }

    /**
     * @param class-string<PacketInterface> $class
     */
    #[DataProvider('ackPackets')]
    public function testOmitsReasonCodeWhenItIsSuccessAndThereAreNoProperties(string $class): void
    {
        $packet = new $class(packetId: 1, protocolVersion: ProtocolVersion::V50);

        // Both trailing fields may be dropped when they carry nothing, leaving just the
        // two-byte packet id (MQTT-3.4.2-1).
        self::assertSame(2, strlen($packet->encode()));
    }

    /**
     * @param class-string<PacketInterface> $class
     */
    #[DataProvider('ackPackets')]
    public function testV311CarriesNoReasonCode(string $class): void
    {
        $packet = new $class(
            packetId: 3,
            protocolVersion: ProtocolVersion::V311,
            reasonCode: PubackReasonCode::NotAuthorized->value,
        );

        // 3.1.1 has no reason code on these packets, so it must not reach the wire.
        self::assertSame(2, strlen($packet->encode()));

        $decoded = $this->factory->decode($this->encoder->encode($packet));
        self::assertInstanceOf($class, $decoded);
        self::assertSame(PubackReasonCode::Success->value, $decoded->reasonCode);
    }
}
