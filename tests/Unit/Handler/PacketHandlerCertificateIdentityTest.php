<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Handler;

use PhpMqtt\Broker\Configuration;
use PhpMqtt\Broker\Protocol\Packet\ConnackPacket;
use PhpMqtt\Broker\Protocol\Packet\SubscribePacket;
use PhpMqtt\Broker\Protocol\ProtocolVersion;

/**
 * With mutual TLS the certificate already proves who the client is, but the broker
 * never told the authenticator: any holder of a valid certificate could claim any
 * username and any client ID. With tlsIdentityAsUsername the certificate's common name
 * is the username, whatever the CONNECT says.
 */
final class PacketHandlerCertificateIdentityTest extends PacketHandlerTestCase
{
    protected function setUp(): void
    {
        $this->makeHandler(config: new Configuration(tlsIdentityAsUsername: true));
    }

    public function testTheCertificateNameIsTheUsernameEverywhere(): void
    {
        $connection = $this->connect('device-1', username: 'device-1', peerCommonName: 'device-1');
        $this->handler->handle($connection, new SubscribePacket(1, [['topic' => 'a', 'qos' => 0]]));

        self::assertTrue($connection->isConnected());
        self::assertSame('device-1', $connection->getUsername());
        self::assertSame(['authenticate:device-1', 'canUseClientId:device-1'], $this->authenticator->authenticatedAs);
        self::assertSame(['canSubscribe:device-1'], $this->authenticator->authorizedAs);
    }

    public function testAClaimedUsernameIsIgnored(): void
    {
        $this->connect('device-1', username: 'admin', peerCommonName: 'device-1');

        self::assertSame(['authenticate:device-1', 'canUseClientId:device-1'], $this->authenticator->authenticatedAs);
    }

    public function testAConnectionWithoutACertificateNameIsRefused(): void
    {
        $v311 = $this->connect('device-1', username: 'device-1');
        $v5 = $this->connect('device-2', version: ProtocolVersion::V50, username: 'device-2');

        self::assertFalse($v311->isConnected());
        self::assertSame(0x05, $this->lastSent($v311, ConnackPacket::class)->returnCode);
        self::assertSame(0x87, $this->lastSent($v5, ConnackPacket::class, ProtocolVersion::V50)->returnCode);
        self::assertSame([], $this->authenticator->authenticatedAs, 'Nothing to authenticate without an identity');
    }

    public function testWithoutTheOptionTheClaimedUsernameStands(): void
    {
        $this->makeHandler(config: new Configuration());

        $this->connect('alice-phone', username: 'alice', peerCommonName: 'device-1');

        self::assertSame(['authenticate:alice', 'canUseClientId:alice'], $this->authenticator->authenticatedAs);
    }
}
