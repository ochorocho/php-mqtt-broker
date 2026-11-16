<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Log;

use PhpMqtt\Broker\Log\LogSanitizer;
use PHPUnit\Framework\TestCase;

final class LogSanitizerTest extends TestCase
{
    public function testEscapesLineBreaksAndOtherControlCharacters(): void
    {
        self::assertSame('a\\nb\\rc\\td\\000e\\177', LogSanitizer::escape("a\nb\rc\td\0e\x7F"));
    }

    public function testEscapesTheBackslashSoEscapesStayUnambiguous(): void
    {
        // A literal backslash-n typed by the client must not read as an escaped newline.
        self::assertSame('a\\\\nb', LogSanitizer::escape('a\\nb'));
        self::assertNotSame(LogSanitizer::escape("a\nb"), LogSanitizer::escape('a\\nb'));
    }

    public function testControlCharacterEscapingLeavesBackslashesAlone(): void
    {
        self::assertSame('a\\nb\\n', LogSanitizer::escapeControlCharacters("a\\nb\n"));
    }

    public function testLeavesPrintableUnicodeUntouched(): void
    {
        self::assertSame('sensor/küche/°C', LogSanitizer::forLog('sensor/küche/°C'));
    }

    public function testTruncatesLongValues(): void
    {
        $logged = LogSanitizer::forLog(str_repeat('x', 500));

        self::assertSame(str_repeat('x', 128) . '…', $logged);
    }

    public function testTruncationLimitIsAdjustable(): void
    {
        self::assertSame('abc…', LogSanitizer::forLog('abcdef', 3));
    }

    public function testAddressDropsTheTransportScheme(): void
    {
        self::assertSame('10.0.0.5:51234', LogSanitizer::address('tcp://10.0.0.5:51234'));
        self::assertSame('[2001:db8::1]:8883', LogSanitizer::address('tls://[2001:db8::1]:8883'));
    }
}
