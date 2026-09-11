<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Protocol\Property;

use PhpMqtt\Broker\Exception\MalformedPacketException;
use PhpMqtt\Broker\Protocol\Property\PropertyCodec;
use PhpMqtt\Broker\Protocol\Property\PropertyCollection;
use PhpMqtt\Broker\Protocol\Property\PropertyId;
use PHPUnit\Framework\TestCase;

final class PropertyCodecTest extends TestCase
{
    public function testDecodesWellFormedProperties(): void
    {
        // Property length 5: SessionExpiryInterval (0x11) plus its four-byte value.
        $data = "\x05\x11\x00\x00\x00\x63";
        $offset = 0;

        $properties = PropertyCodec::decode($data, $offset);

        self::assertSame(99, $properties->get(PropertyId::SessionExpiryInterval));
        self::assertSame(6, $offset, 'Offset should land exactly past the property block');
    }

    public function testRejectsValueOverrunningDeclaredLength(): void
    {
        // Declares three bytes of properties but holds a ContentType whose own length
        // prefix claims eight. Reading it would consume the bytes that follow the
        // block, letting the sender decide which properties the broker sees and which
        // it silently skips.
        $data = "\x03\x03" . pack('n', 8) . 'AAAAAAAA' . "\x01\x01";
        $offset = 0;

        $this->expectException(MalformedPacketException::class);
        PropertyCodec::decode($data, $offset);
    }

    public function testRejectsShortReadWithinDeclaredLength(): void
    {
        // Declares ten bytes but supplies a single five-byte property, so the block
        // does not account for its own declared length.
        $data = "\x0A\x11\x00\x00\x00\x01\x00\x00\x00\x00\x00";
        $offset = 0;

        $this->expectException(MalformedPacketException::class);
        PropertyCodec::decode($data, $offset);
    }

    public function testRejectsDuplicateSingleValueProperty(): void
    {
        // Two SessionExpiryIntervals: last-write-wins would let the sender choose which
        // value takes effect, so this is a protocol error instead.
        $data = "\x0A\x11\x00\x00\x00\x01\x11\x00\x00\x00\x63";
        $offset = 0;

        $this->expectException(MalformedPacketException::class);
        PropertyCodec::decode($data, $offset);
    }

    public function testAllowsRepeatedMultiValueProperty(): void
    {
        $collection = new PropertyCollection();
        $collection->set(PropertyId::UserProperty, ['a', '1']);
        $collection->set(PropertyId::UserProperty, ['b', '2']);

        $encoded = PropertyCodec::encode($collection);
        $offset = 0;
        $decoded = PropertyCodec::decode($encoded, $offset);

        self::assertSame(
            [['a', '1'], ['b', '2']],
            $decoded->get(PropertyId::UserProperty),
        );
    }

    public function testRejectsUnknownPropertyId(): void
    {
        $data = "\x02\x7F\x00";
        $offset = 0;

        $this->expectException(MalformedPacketException::class);
        PropertyCodec::decode($data, $offset);
    }

    public function testRoundTripsThroughEncode(): void
    {
        $collection = new PropertyCollection();
        $collection->set(PropertyId::ContentType, 'application/json');
        $collection->set(PropertyId::MessageExpiryInterval, 120);

        $offset = 0;
        $decoded = PropertyCodec::decode(PropertyCodec::encode($collection), $offset);

        self::assertSame('application/json', $decoded->get(PropertyId::ContentType));
        self::assertSame(120, $decoded->get(PropertyId::MessageExpiryInterval));
    }
}
