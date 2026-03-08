<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Protocol;

use PhpMqtt\Broker\Exception\MalformedPacketException;
use PhpMqtt\Broker\Protocol\DataType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataTypeTest extends TestCase
{
    // --- Variable Byte Integer ---

    public function testEncodeVariableByteIntegerZero(): void
    {
        self::assertSame("\x00", DataType::encodeVariableByteInteger(0));
    }

    public function testEncodeVariableByteInteger127(): void
    {
        self::assertSame("\x7F", DataType::encodeVariableByteInteger(127));
    }

    public function testEncodeVariableByteInteger128(): void
    {
        self::assertSame("\x80\x01", DataType::encodeVariableByteInteger(128));
    }

    public function testEncodeVariableByteInteger16383(): void
    {
        self::assertSame("\xFF\x7F", DataType::encodeVariableByteInteger(16383));
    }

    public function testEncodeVariableByteInteger16384(): void
    {
        self::assertSame("\x80\x80\x01", DataType::encodeVariableByteInteger(16384));
    }

    public function testEncodeVariableByteInteger2097151(): void
    {
        self::assertSame("\xFF\xFF\x7F", DataType::encodeVariableByteInteger(2097151));
    }

    public function testEncodeVariableByteInteger2097152(): void
    {
        self::assertSame("\x80\x80\x80\x01", DataType::encodeVariableByteInteger(2097152));
    }

    public function testEncodeVariableByteInteger268435455(): void
    {
        self::assertSame("\xFF\xFF\xFF\x7F", DataType::encodeVariableByteInteger(268435455));
    }

    public function testEncodeVariableByteIntegerOverflowThrows(): void
    {
        $this->expectException(MalformedPacketException::class);
        DataType::encodeVariableByteInteger(268435456);
    }

    public function testEncodeVariableByteIntegerNegativeThrows(): void
    {
        $this->expectException(MalformedPacketException::class);
        DataType::encodeVariableByteInteger(-1);
    }

    #[DataProvider('variableByteIntegerProvider')]
    public function testDecodeVariableByteIntegerRoundTrip(int $value): void
    {
        $encoded = DataType::encodeVariableByteInteger($value);
        $offset = 0;
        $decoded = DataType::decodeVariableByteInteger($encoded, $offset);
        self::assertSame($value, $decoded);
        self::assertSame(strlen($encoded), $offset);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function variableByteIntegerProvider(): iterable
    {
        yield 'zero' => [0];
        yield '1' => [1];
        yield '127' => [127];
        yield '128' => [128];
        yield '16383' => [16383];
        yield '16384' => [16384];
        yield '2097151' => [2097151];
        yield '2097152' => [2097152];
        yield '268435455' => [268435455];
    }

    public function testDecodeVariableByteIntegerMalformedFifthByte(): void
    {
        // Five continuation bytes — malformed
        $data = "\x80\x80\x80\x80\x01";
        $offset = 0;
        $this->expectException(MalformedPacketException::class);
        DataType::decodeVariableByteInteger($data, $offset);
    }

    public function testDecodeVariableByteIntegerIncomplete(): void
    {
        $data = "\x80"; // continuation bit set, but no next byte
        $offset = 0;
        $this->expectException(MalformedPacketException::class);
        DataType::decodeVariableByteInteger($data, $offset);
    }

    // --- Two Byte Integer ---

    public function testEncodeTwoByteInteger(): void
    {
        self::assertSame("\x00\x00", DataType::encodeTwoByteInteger(0));
        self::assertSame("\x00\x01", DataType::encodeTwoByteInteger(1));
        self::assertSame("\x01\x00", DataType::encodeTwoByteInteger(256));
        self::assertSame("\xFF\xFF", DataType::encodeTwoByteInteger(65535));
    }

    public function testDecodeTwoByteIntegerRoundTrip(): void
    {
        foreach ([0, 1, 256, 65535] as $value) {
            $encoded = DataType::encodeTwoByteInteger($value);
            $offset = 0;
            self::assertSame($value, DataType::decodeTwoByteInteger($encoded, $offset));
            self::assertSame(2, $offset);
        }
    }

    public function testDecodeTwoByteIntegerIncomplete(): void
    {
        $this->expectException(MalformedPacketException::class);
        $offset = 0;
        DataType::decodeTwoByteInteger("\x00", $offset);
    }

    public function testEncodeTwoByteIntegerOverflow(): void
    {
        $this->expectException(MalformedPacketException::class);
        DataType::encodeTwoByteInteger(65536);
    }

    // --- Four Byte Integer ---

    public function testEncodeFourByteInteger(): void
    {
        self::assertSame("\x00\x00\x00\x00", DataType::encodeFourByteInteger(0));
        self::assertSame("\x00\x00\x00\x01", DataType::encodeFourByteInteger(1));
        self::assertSame("\x00\x01\x00\x00", DataType::encodeFourByteInteger(65536));
        self::assertSame("\xFF\xFF\xFF\xFF", DataType::encodeFourByteInteger(4294967295));
    }

    public function testDecodeFourByteIntegerRoundTrip(): void
    {
        foreach ([0, 1, 65536, 4294967295] as $value) {
            $encoded = DataType::encodeFourByteInteger($value);
            $offset = 0;
            self::assertSame($value, DataType::decodeFourByteInteger($encoded, $offset));
            self::assertSame(4, $offset);
        }
    }

    public function testDecodeFourByteIntegerIncomplete(): void
    {
        $this->expectException(MalformedPacketException::class);
        $offset = 0;
        DataType::decodeFourByteInteger("\x00\x00", $offset);
    }

    // --- UTF-8 String ---

    public function testEncodeUtf8String(): void
    {
        $encoded = DataType::encodeUtf8String('hello');
        self::assertSame("\x00\x05hello", $encoded);
    }

    public function testEncodeUtf8StringEmpty(): void
    {
        self::assertSame("\x00\x00", DataType::encodeUtf8String(''));
    }

    public function testDecodeUtf8StringRoundTrip(): void
    {
        $original = 'hello world';
        $encoded = DataType::encodeUtf8String($original);
        $offset = 0;
        self::assertSame($original, DataType::decodeUtf8String($encoded, $offset));
        self::assertSame(strlen($encoded), $offset);
    }

    public function testDecodeUtf8StringIncomplete(): void
    {
        $this->expectException(MalformedPacketException::class);
        $offset = 0;
        DataType::decodeUtf8String("\x00\x05hel", $offset);
    }

    public function testValidateUtf8StringRejectsNullChar(): void
    {
        $this->expectException(MalformedPacketException::class);
        DataType::validateUtf8String("hello\x00world");
    }

    public function testValidateUtf8StringAcceptsValidUtf8(): void
    {
        // Should not throw
        DataType::validateUtf8String('Hello 🌍');
        DataType::validateUtf8String('');
        DataType::validateUtf8String('ASCII only');
        $this->addToAssertionCount(1);
    }

    public function testEncodeUtf8StringRejectsNullChar(): void
    {
        $this->expectException(MalformedPacketException::class);
        DataType::encodeUtf8String("bad\x00string");
    }

    // --- Binary Data ---

    public function testEncodeBinaryData(): void
    {
        $data = "\x00\x01\x02\xFF";
        $encoded = DataType::encodeBinaryData($data);
        self::assertSame("\x00\x04\x00\x01\x02\xFF", $encoded);
    }

    public function testDecodeBinaryDataRoundTrip(): void
    {
        $original = "\x00\x01\x02\xFF";
        $encoded = DataType::encodeBinaryData($original);
        $offset = 0;
        self::assertSame($original, DataType::decodeBinaryData($encoded, $offset));
    }

    public function testDecodeBinaryDataIncomplete(): void
    {
        $this->expectException(MalformedPacketException::class);
        $offset = 0;
        DataType::decodeBinaryData("\x00\x04\x00\x01", $offset);
    }

    // --- UTF-8 String Pair ---

    public function testEncodeUtf8StringPair(): void
    {
        $encoded = DataType::encodeUtf8StringPair('key', 'value');
        $expected = "\x00\x03key\x00\x05value";
        self::assertSame($expected, $encoded);
    }

    public function testDecodeUtf8StringPairRoundTrip(): void
    {
        $encoded = DataType::encodeUtf8StringPair('name', 'test');
        $offset = 0;
        $result = DataType::decodeUtf8StringPair($encoded, $offset);
        self::assertSame(['name', 'test'], $result);
        self::assertSame(strlen($encoded), $offset);
    }

    // --- Byte ---

    public function testEncodeByte(): void
    {
        self::assertSame("\x00", DataType::encodeByte(0));
        self::assertSame("\x01", DataType::encodeByte(1));
        self::assertSame("\xFF", DataType::encodeByte(255));
    }

    public function testDecodeByte(): void
    {
        $offset = 0;
        self::assertSame(0, DataType::decodeByte("\x00", $offset));

        $offset = 0;
        self::assertSame(255, DataType::decodeByte("\xFF", $offset));
    }

    public function testDecodeByteAdvancesOffset(): void
    {
        $data = "\x01\x02\x03";
        $offset = 0;
        self::assertSame(1, DataType::decodeByte($data, $offset));
        self::assertSame(1, $offset);
        self::assertSame(2, DataType::decodeByte($data, $offset));
        self::assertSame(2, $offset);
    }

    public function testDecodeByteIncomplete(): void
    {
        $this->expectException(MalformedPacketException::class);
        $offset = 0;
        DataType::decodeByte('', $offset);
    }

    public function testEncodeByteOverflow(): void
    {
        $this->expectException(MalformedPacketException::class);
        DataType::encodeByte(256);
    }
}
