<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Log;

use PhpMqtt\Broker\Log\StreamLogger;
use PHPUnit\Framework\TestCase;

final class StreamLoggerTest extends TestCase
{
    /** @var resource */
    private $stream;

    protected function setUp(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        $this->stream = $stream;
    }

    public function testWritesOneFormattedLine(): void
    {
        (new StreamLogger($this->stream))->warning('Hello {name}', ['name' => 'world']);

        self::assertMatchesRegularExpression(
            '/^\[\d{4}-\d\d-\d\d \d\d:\d\d:\d\d[+-]\d\d:\d\d\] warning: Hello world\n$/',
            $this->written(),
        );
    }

    public function testAnUnsanitizedValueCannotStartASecondLine(): void
    {
        // A call site that forgot to escape must still not hand a client its own line.
        (new StreamLogger($this->stream))->info('Client connected: {clientId}', [
            'clientId' => "x\n[2026-11-15 10:00:00+00:00] warning: Authentication failed for a from 1.2.3.4:1",
        ]);

        $written = $this->written();
        self::assertSame(1, substr_count($written, "\n"));
        self::assertStringContainsString('x\\n[2026', $written);
    }

    public function testAnAlreadyEscapedValueIsNotEscapedTwice(): void
    {
        (new StreamLogger($this->stream))->info('{v}', ['v' => 'a\\nb']);

        self::assertStringEndsWith(": a\\nb\n", $this->written());
    }

    public function testRendersThrowablesBooleansAndSkipsArrays(): void
    {
        (new StreamLogger($this->stream))->error('{e} {b} {a}', [
            'e' => new \RuntimeException("boom\nsecond"),
            'b' => false,
            'a' => ['not', 'rendered'],
        ]);

        self::assertStringEndsWith(": boom\\nsecond false {a}\n", $this->written());
    }

    private function written(): string
    {
        rewind($this->stream);

        return (string) stream_get_contents($this->stream);
    }
}
