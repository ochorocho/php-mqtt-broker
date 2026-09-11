<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Connection;

use PhpMqtt\Broker\Connection\Connection;
use PhpMqtt\Broker\Protocol\PacketEncoder;
use PhpMqtt\Broker\Server\ConnectionStream;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;

final class ConnectionTest extends TestCase
{
    private function connection(): Connection
    {
        $stream = new class implements ConnectionStream {
            public function write(string $data): void
            {
            }

            public function close(): void
            {
            }

            public function onData(callable $handler): void
            {
            }

            public function onClose(callable $handler): void
            {
            }

            public function getRemoteAddress(): string
            {
                return '127.0.0.1:1';
            }
        };

        return new Connection($stream, new PacketEncoder(), Loop::get());
    }

    public function testDisconnectHandledIsSeparateFromConnected(): void
    {
        $connection = $this->connection();
        $connection->setConnected(true);

        self::assertFalse($connection->isDisconnectHandled());

        // A connection can stop accepting packets — the receive-maximum path does
        // exactly this before deferring the close — without teardown having run. If
        // these two were the same flag, the later close would skip cleanup entirely.
        $connection->setConnected(false);

        self::assertFalse(
            $connection->isDisconnectHandled(),
            'Marking a connection not-connected must not count as having torn it down',
        );
    }

    public function testDisconnectHandledLatches(): void
    {
        $connection = $this->connection();

        $connection->markDisconnectHandled();
        self::assertTrue($connection->isDisconnectHandled());

        // Idempotent: the keepalive timer and the socket close can both fire.
        $connection->markDisconnectHandled();
        self::assertTrue($connection->isDisconnectHandled());
    }

    public function testNewConnectionStartsDisconnectedAndUntornDown(): void
    {
        $connection = $this->connection();

        self::assertFalse($connection->isConnected());
        self::assertFalse($connection->isDisconnectHandled());
    }
}
