<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Broker;

use PhpMqtt\Broker\Protocol\Packet\ConnackPacket;
use PhpMqtt\Broker\Protocol\Packet\PublishPacket;
use PhpMqtt\Broker\Protocol\Packet\SubscribePacket;

final class BrokerSmokeTest extends BrokerTestCase
{
    public function testConnectThroughTheBrokerIsAcknowledged(): void
    {
        $stream = $this->connectClient('smoke');

        $connacks = self::receivedOfType($stream, ConnackPacket::class);
        self::assertCount(1, $connacks);
        self::assertSame(0, $connacks[0]->returnCode);
        self::assertFalse($stream->closed);
    }

    public function testMessageIsRoutedBetweenTwoClients(): void
    {
        $subscriber = $this->connectClient('sub');
        $subscriber->push(self::encode(new SubscribePacket(
            packetId: 1,
            subscriptions: [['topic' => 'a/b', 'qos' => 0]],
        )));

        $publisher = $this->connectClient('pub', remoteAddress: 'tcp://127.0.0.1:50001');
        $publisher->push(self::encode(new PublishPacket(topicName: 'a/b', payload: 'hello')));

        $publishes = self::receivedOfType($subscriber, PublishPacket::class);
        self::assertCount(1, $publishes);
        self::assertSame('hello', $publishes[0]->payload);
    }

    public function testConnectTimeoutClosesASilentSocket(): void
    {
        $stream = $this->server->connect();

        $this->loop()->advance(10.5);

        self::assertTrue($stream->closed);
    }
}
