<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Handler;

use PhpMqtt\Broker\Connection\Connection;
use PhpMqtt\Broker\Protocol\Packet\ConnectPacket;
use PhpMqtt\Broker\Protocol\Packet\PublishPacket;
use PhpMqtt\Broker\Protocol\Packet\SubscribePacket;
use PhpMqtt\Broker\Protocol\PacketEncoder;
use PhpMqtt\Broker\Server\ConnectionStream;
use React\EventLoop\Loop;

/**
 * Routing one PUBLISH writes to every subscriber, inside the publisher's packet handling.
 * An exception for one subscriber used to escape to the publisher's connection: routing
 * stopped for everyone after it, and the publisher - who did nothing wrong - was
 * disconnected.
 */
final class PacketHandlerDeliveryIsolationTest extends PacketHandlerTestCase
{
    public function testAFailingSubscriberDoesNotStopTheOthersOrThePublisher(): void
    {
        $brokenStream = new FailingStream();
        $broken = $this->subscribedThrough($brokenStream, 'broken');
        $healthy = $this->connect('healthy');
        $this->handler->handle($healthy, new SubscribePacket(1, [['topic' => 't', 'qos' => 0]]));

        $brokenStream->failing = true;
        $publisher = $this->connect('publisher');
        $this->handler->handle($publisher, new PublishPacket(topicName: 't', payload: 'x'));

        self::assertSame(1, $this->countSent($healthy, PublishPacket::class));
        self::assertTrue($publisher->isConnected());
        self::assertFalse($this->stream($publisher)->closed);
    }

    public function testTheFailingSubscriberIsClosedAndTheErrorLogged(): void
    {
        $brokenStream = new FailingStream();
        $this->subscribedThrough($brokenStream, 'broken');

        $brokenStream->failing = true;
        $publisher = $this->connect('publisher');
        $this->handler->handle($publisher, new PublishPacket(topicName: 't', payload: 'x'));

        self::assertTrue($brokenStream->closed);
        $errors = $this->logger->messagesAt('error');
        self::assertCount(1, $errors);
        self::assertStringContainsString('broken', $errors[0]);
    }

    private function subscribedThrough(FailingStream $stream, string $clientId): Connection
    {
        $connection = new Connection($stream, new PacketEncoder(), Loop::get());
        $this->handler->handle($connection, new ConnectPacket(
            protocolName: 'MQTT',
            protocolLevel: 4,
            cleanSession: true,
            keepAlive: 60,
            clientId: $clientId,
        ));
        $this->connections->register($clientId, $connection);
        $this->handler->handle($connection, new SubscribePacket(1, [['topic' => 't', 'qos' => 0]]));

        return $connection;
    }
}

/**
 * A socket that breaks once told to: every write then throws.
 */
final class FailingStream implements ConnectionStream
{
    public bool $failing = false;
    public bool $closed = false;

    public function write(string $data): bool
    {
        if ($this->failing) {
            throw new \RuntimeException('Broken pipe');
        }

        return true;
    }

    public function close(): void
    {
        $this->closed = true;
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
}
