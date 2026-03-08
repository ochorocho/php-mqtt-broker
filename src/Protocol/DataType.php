<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Protocol;

use PhpMqtt\Broker\Exception\MalformedPacketException;

final class DataType
{
    private const int VARIABLE_BYTE_INT_MAX = 268_435_455;

    public static function encodeVariableByteInteger(int $value): string
    {
        if ($value < 0 || $value > self::VARIABLE_BYTE_INT_MAX) {
            throw new MalformedPacketException(
                sprintf('Variable byte integer value %d out of range [0, %d]', $value, self::VARIABLE_BYTE_INT_MAX)
            );
        }

        $encoded = '';
        do {
            $byte = $value % 128;
            $value = intdiv($value, 128);
            if ($value > 0) {
                $byte |= 0x80;
            }
            $encoded .= chr($byte);
        } while ($value > 0);

        return $encoded;
    }

    public static function decodeVariableByteInteger(string $data, int &$offset): int
    {
        $multiplier = 1;
        $value = 0;
        $length = strlen($data);

        do {
            if ($offset >= $length) {
                throw new MalformedPacketException('Incomplete variable byte integer');
            }

            $byte = ord($data[$offset++]);
            $value += ($byte & 0x7F) * $multiplier;

            if ($multiplier > 128 * 128 * 128) {
                throw new MalformedPacketException('Malformed variable byte integer: too many bytes');
            }

            $multiplier *= 128;
        } while (($byte & 0x80) !== 0);

        return $value;
    }

    public static function encodeTwoByteInteger(int $value): string
    {
        if ($value < 0 || $value > 0xFFFF) {
            throw new MalformedPacketException(
                sprintf('Two byte integer value %d out of range [0, 65535]', $value)
            );
        }

        return pack('n', $value);
    }

    public static function decodeTwoByteInteger(string $data, int &$offset): int
    {
        if ($offset + 2 > strlen($data)) {
            throw new MalformedPacketException('Incomplete two byte integer');
        }

        /** @var array{1: int} $result */
        $result = unpack('n', $data, $offset);
        $offset += 2;

        return $result[1];
    }

    public static function encodeFourByteInteger(int $value): string
    {
        if ($value < 0 || $value > 0xFFFFFFFF) {
            throw new MalformedPacketException(
                sprintf('Four byte integer value %d out of range [0, 4294967295]', $value)
            );
        }

        return pack('N', $value);
    }

    public static function decodeFourByteInteger(string $data, int &$offset): int
    {
        if ($offset + 4 > strlen($data)) {
            throw new MalformedPacketException('Incomplete four byte integer');
        }

        /** @var array{1: int} $result */
        $result = unpack('N', $data, $offset);
        $offset += 4;

        return $result[1];
    }

    public static function encodeUtf8String(string $value): string
    {
        self::validateUtf8String($value);

        $length = strlen($value);
        if ($length > 0xFFFF) {
            throw new MalformedPacketException(
                sprintf('UTF-8 string length %d exceeds maximum 65535', $length)
            );
        }

        return pack('n', $length) . $value;
    }

    public static function decodeUtf8String(string $data, int &$offset): string
    {
        if ($offset + 2 > strlen($data)) {
            throw new MalformedPacketException('Incomplete UTF-8 string length');
        }

        /** @var array{1: int} $result */
        $result = unpack('n', $data, $offset);
        $length = $result[1];
        $offset += 2;

        if ($offset + $length > strlen($data)) {
            throw new MalformedPacketException('Incomplete UTF-8 string data');
        }

        $value = substr($data, $offset, $length);
        $offset += $length;

        self::validateUtf8String($value);

        return $value;
    }

    public static function validateUtf8String(string $value): void
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new MalformedPacketException('Invalid UTF-8 encoding');
        }

        // Must not contain null character U+0000
        if (str_contains($value, "\x00")) {
            throw new MalformedPacketException('UTF-8 string must not contain null character U+0000');
        }

        // Check for surrogate code points U+D800-U+DFFF (should not appear in valid UTF-8,
        // but we check the raw bytes for the overlong encoding pattern)
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($value[$i]);
            // 3-byte UTF-8 sequence starting with 0xED
            if ($byte === 0xED && $i + 2 < $length) {
                $byte2 = ord($value[$i + 1]);
                if ($byte2 >= 0xA0 && $byte2 <= 0xBF) {
                    throw new MalformedPacketException('UTF-8 string must not contain surrogate code points U+D800-U+DFFF');
                }
            }
        }
    }

    public static function encodeBinaryData(string $value): string
    {
        $length = strlen($value);
        if ($length > 0xFFFF) {
            throw new MalformedPacketException(
                sprintf('Binary data length %d exceeds maximum 65535', $length)
            );
        }

        return pack('n', $length) . $value;
    }

    public static function decodeBinaryData(string $data, int &$offset): string
    {
        if ($offset + 2 > strlen($data)) {
            throw new MalformedPacketException('Incomplete binary data length');
        }

        /** @var array{1: int} $result */
        $result = unpack('n', $data, $offset);
        $length = $result[1];
        $offset += 2;

        if ($offset + $length > strlen($data)) {
            throw new MalformedPacketException('Incomplete binary data');
        }

        $value = substr($data, $offset, $length);
        $offset += $length;

        return $value;
    }

    /**
     * @return array{string, string}
     */
    public static function decodeUtf8StringPair(string $data, int &$offset): array
    {
        $key = self::decodeUtf8String($data, $offset);
        $value = self::decodeUtf8String($data, $offset);

        return [$key, $value];
    }

    public static function encodeUtf8StringPair(string $key, string $value): string
    {
        return self::encodeUtf8String($key) . self::encodeUtf8String($value);
    }

    public static function encodeByte(int $value): string
    {
        if ($value < 0 || $value > 0xFF) {
            throw new MalformedPacketException(
                sprintf('Byte value %d out of range [0, 255]', $value)
            );
        }

        return chr($value);
    }

    public static function decodeByte(string $data, int &$offset): int
    {
        if ($offset >= strlen($data)) {
            throw new MalformedPacketException('Incomplete byte');
        }

        return ord($data[$offset++]);
    }
}
