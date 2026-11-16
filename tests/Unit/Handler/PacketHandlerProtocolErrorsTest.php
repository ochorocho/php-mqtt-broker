<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Handler;

use PhpMqtt\Broker\Exception\ProtocolViolationException;
use PhpMqtt\Broker\Protocol\Packet\ConnackPacket;
use PhpMqtt\Broker\Protocol\Packet\ConnectPacket;
use PhpMqtt\Broker\Protocol\Packet\DisconnectPacket;
use PhpMqtt\Broker\Protocol\Packet\PublishPacket;
use PhpMqtt\Broker\Protocol\Packet\SubscribePacket;
use PhpMqtt\Broker\Protocol\Property\PropertyCollection;
use PhpMqtt\Broker\Protocol\Property\PropertyId;
use PhpMqtt\Broker\Protocol\ProtocolVersion;

/**
 * Values MQTT 5.0 names a Protocol Error. Accepting them quietly leaves client and
 * broker disagreeing about the connection - a client that asked for enhanced
 * authentication and was let in without it believes the server proved who it is.
 */
final class PacketHandlerProtocolErrorsTest extends PacketHandlerTestCase
{
    public function testAReceiveMaximumOfZeroIsRefused(): void
    {
        $connection = $this->connectWith(PropertyId::ReceiveMaximum, 0);

        $this->assertRefusedWith(0x82, $connection);
    }

    public function testAMaximumPacketSizeOfZeroIsRefused(): void
    {
        $connection = $this->connectWith(PropertyId::MaximumPacketSize, 0);

        $this->assertRefusedWith(0x82, $connection);
    }

    public function testEnhancedAuthenticationIsRefusedAsUnsupported(): void
    {
        // Admitting the client anyway would skip the challenge it asked for - and with
        // it any proof that this is the server it meant to reach.
        $connection = $this->connectWith(PropertyId::AuthenticationMethod, 'SCRAM-SHA-256');

        $this->assertRefusedWith(0x8C, $connection);
        self::assertSame([], array_filter($this->authenticator->calls, static fn(string $c): bool => str_starts_with($c, 'authenticate:')));
    }

    public function testAuthenticationDataWithoutAMethodIsRefused(): void
    {
        $connection = $this->connectWith(PropertyId::AuthenticationData, "\x01\x02");

        $this->assertRefusedWith(0x82, $connection);
    }

    public function testDisconnectCannotStartASessionTheConnectDidNotAskFor(): void
    {
        $connection = $this->connect('client', version: ProtocolVersion::V50);
        $properties = new PropertyCollection();
        $properties->set(PropertyId::SessionExpiryInterval, 3600);

        try {
            $this->handler->handle($connection, new DisconnectPacket(
                protocolVersion: ProtocolVersion::V50,
                properties: $properties,
            ));
            self::fail('Expected a protocol violation');
        } catch (ProtocolViolationException) {
        }

        // What the broker does with any protocol error: close, as an abnormal disconnect.
        $this->handler->handleDisconnect($connection, false);
        self::assertNull($this->handler->getSessionManager()->get('client'));
    }

    public function testNoLocalOnASharedSubscriptionIsAProtocolError(): void
    {
        $connection = $this->connect('client', version: ProtocolVersion::V50);

        $this->expectException(ProtocolViolationException::class);
        $this->handler->handle($connection, new SubscribePacket(
            packetId: 1,
            subscriptions: [['topic' => '$share/g/jobs', 'qos' => 0, 'noLocal' => true]],
            protocolVersion: ProtocolVersion::V50,
        ));
    }

    public function testNoRetainedMessagesAreSentForASharedSubscription(): void
    {
        $publisher = $this->connect('publisher');
        $this->handler->handle($publisher, new PublishPacket(topicName: 'jobs/1', payload: 'retained', retain: true));

        // A retained message delivered to whichever group member subscribed first is
        // neither shared nor useful; the spec sends none (4.8.2).
        $worker = $this->connect('worker', version: ProtocolVersion::V50);
        $this->handler->handle($worker, new SubscribePacket(
            packetId: 1,
            subscriptions: [['topic' => '$share/g/jobs/#', 'qos' => 0]],
            protocolVersion: ProtocolVersion::V50,
        ));
        self::assertSame(0, $this->countSent($worker, PublishPacket::class, ProtocolVersion::V50));

        $observer = $this->connect('observer', version: ProtocolVersion::V50);
        $this->handler->handle($observer, new SubscribePacket(
            packetId: 1,
            subscriptions: [['topic' => 'jobs/#', 'qos' => 0]],
            protocolVersion: ProtocolVersion::V50,
        ));
        self::assertSame(1, $this->countSent($observer, PublishPacket::class, ProtocolVersion::V50));
    }

    private function connectWith(PropertyId $property, mixed $value): \PhpMqtt\Broker\Connection\Connection
    {
        $properties = new PropertyCollection();
        $properties->set($property, $value);

        return $this->connect('client', packet: new ConnectPacket(
            protocolName: 'MQTT',
            protocolLevel: ProtocolVersion::V50->value,
            cleanSession: true,
            keepAlive: 60,
            clientId: 'client',
            properties: $properties,
        ));
    }

    private function assertRefusedWith(int $reasonCode, \PhpMqtt\Broker\Connection\Connection $connection): void
    {
        self::assertFalse($connection->isConnected());
        self::assertTrue($this->stream($connection)->closed);
        self::assertSame($reasonCode, $this->lastSent($connection, ConnackPacket::class, ProtocolVersion::V50)->returnCode);
    }
}
