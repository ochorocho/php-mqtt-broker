<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Protocol;

use PhpMqtt\Broker\Protocol\PacketStream;
use PHPUnit\Framework\TestCase;

final class PacketStreamTest extends TestCase
{
    public function testEmptyBufferHasNoPacket(): void
    {
        $stream = new PacketStream();
        self::assertFalse($stream->hasCompletePacket());
        self::assertSame(0, $stream->getBufferLength());
    }

    public function testSingleByteIsIncomplete(): void
    {
        $stream = new PacketStream();
        $stream->append("\xC0"); // PINGREQ first byte only
        self::assertFalse($stream->hasCompletePacket());
    }

    public function testMinimalPacketDetected(): void
    {
        $stream = new PacketStream();
        // PINGREQ: type 12, flags 0, remaining length 0
        $stream->append("\xC0\x00");
        self::assertTrue($stream->hasCompletePacket());

        $packet = $stream->nextPacket();
        self::assertSame("\xC0\x00", $packet);
        self::assertSame(0, $stream->getBufferLength());
    }

    public function testTwoByteRemainingLength(): void
    {
        $stream = new PacketStream();
        // Remaining length 128 = 0x80 0x01
        // Total packet = 1 (fixed) + 2 (remaining len bytes) + 128 (payload) = 131 bytes
        $packet = "\x30\x80\x01" . str_repeat('A', 128);
        $stream->append($packet);
        self::assertTrue($stream->hasCompletePacket());

        $extracted = $stream->nextPacket();
        self::assertSame($packet, $extracted);
    }

    public function testPartialDataThenComplete(): void
    {
        $stream = new PacketStream();
        // CONNECT-like packet: type 1, remaining length 10, then 10 bytes payload
        $fullPacket = "\x10\x0A" . str_repeat('X', 10);

        // Feed first 5 bytes
        $stream->append(substr($fullPacket, 0, 5));
        self::assertFalse($stream->hasCompletePacket());

        // Feed the rest
        $stream->append(substr($fullPacket, 5));
        self::assertTrue($stream->hasCompletePacket());

        $extracted = $stream->nextPacket();
        self::assertSame($fullPacket, $extracted);
    }

    public function testMultiplePacketsInBuffer(): void
    {
        $stream = new PacketStream();
        $pingreq = "\xC0\x00";
        $pingresp = "\xD0\x00";
        $stream->append($pingreq . $pingresp);

        self::assertTrue($stream->hasCompletePacket());
        self::assertSame($pingreq, $stream->nextPacket());

        self::assertTrue($stream->hasCompletePacket());
        self::assertSame($pingresp, $stream->nextPacket());

        self::assertFalse($stream->hasCompletePacket());
    }

    public function testFourByteRemainingLength(): void
    {
        $stream = new PacketStream();
        // Remaining length 2097152 = 0x80 0x80 0x80 0x01
        // We won't actually create 2MB of payload, just verify detection logic
        // by checking that it needs more data
        $stream->append("\x30\x80\x80\x80\x01");
        // Buffer has 5 bytes, but needs 5 + 2097152 = 2097157
        self::assertFalse($stream->hasCompletePacket());
    }

    public function testFragmentedRemainingLength(): void
    {
        $stream = new PacketStream();
        // Packet with remaining length 200 = 0xC8 0x01
        // Total = 1 + 2 + 200 = 203 bytes
        $payload = str_repeat('B', 200);
        $fullPacket = "\x30\xC8\x01" . $payload;

        // Feed just the fixed header byte
        $stream->append("\x30");
        self::assertFalse($stream->hasCompletePacket());

        // Feed first byte of remaining length (continuation bit set)
        $stream->append("\xC8");
        self::assertFalse($stream->hasCompletePacket());

        // Feed second byte of remaining length
        $stream->append("\x01");
        self::assertFalse($stream->hasCompletePacket()); // still need payload

        // Feed partial payload
        $stream->append(substr($payload, 0, 100));
        self::assertFalse($stream->hasCompletePacket());

        // Feed rest of payload
        $stream->append(substr($payload, 100));
        self::assertTrue($stream->hasCompletePacket());

        $extracted = $stream->nextPacket();
        self::assertSame($fullPacket, $extracted);
    }

    public function testClear(): void
    {
        $stream = new PacketStream();
        $stream->append("\xC0\x00\xD0\x00");
        self::assertSame(4, $stream->getBufferLength());

        $stream->clear();
        self::assertSame(0, $stream->getBufferLength());
        self::assertFalse($stream->hasCompletePacket());
    }
}
