<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Server;

use PhpMqtt\Broker\Server\ReactPhpConnectionStream;
use PhpMqtt\Broker\Tests\Unit\Broker\FakeLoop;
use PHPUnit\Framework\TestCase;

final class ReactPhpConnectionStreamTest extends TestCase
{
    public function testCloseEndsGracefullyFirst(): void
    {
        $connection = new FakeReactConnection(flushesOnEnd: true);
        $loop = new FakeLoop();

        (new ReactPhpConnectionStream($connection, $loop))->close();

        self::assertTrue($connection->ended);
        self::assertTrue($connection->closed);
        self::assertSame(0, $loop->pendingTimerCount(), 'A socket that flushed needs no forced close');
    }

    public function testASocketThatNeverFlushesIsForcedShut(): void
    {
        // end() waits for the write buffer to drain. A peer that stops reading never
        // lets it, and the socket used to stay open, with its buffer and its
        // connection slot, for the life of the process.
        $connection = new FakeReactConnection(flushesOnEnd: false);
        $loop = new FakeLoop();

        (new ReactPhpConnectionStream($connection, $loop))->close();
        self::assertFalse($connection->closed);

        $loop->advance(ReactPhpConnectionStream::FORCE_CLOSE_AFTER + 0.1);

        self::assertTrue($connection->closed);
    }

    public function testClosingTwiceArmsOneTimer(): void
    {
        $connection = new FakeReactConnection(flushesOnEnd: false);
        $loop = new FakeLoop();
        $stream = new ReactPhpConnectionStream($connection, $loop);

        $stream->close();
        $stream->close();

        self::assertSame(1, $loop->pendingTimerCount());
    }

    public function testWriteReportsBackpressure(): void
    {
        $connection = new FakeReactConnection(writeResult: false);

        self::assertFalse((new ReactPhpConnectionStream($connection, new FakeLoop()))->write('x'));
    }
}
