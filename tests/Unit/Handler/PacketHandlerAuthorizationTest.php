<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Handler;

use PhpMqtt\Broker\Protocol\Packet\ConnackPacket;
use PhpMqtt\Broker\Protocol\Packet\ConnectPacket;
use PhpMqtt\Broker\Protocol\Packet\PublishPacket;
use PhpMqtt\Broker\Protocol\Packet\SubackPacket;
use PhpMqtt\Broker\Protocol\Packet\SubscribePacket;
use PhpMqtt\Broker\Protocol\ProtocolVersion;

/**
 * Authorization is the boundary an operator configures through AuthenticatorInterface.
 * Each check below was, at some point, defined but not consulted.
 */
final class PacketHandlerAuthorizationTest extends PacketHandlerTestCase
{
    public function testRejectedCredentialsGetConnackFiveAndNoSession(): void
    {
        $this->makeHandler(new RecordingAuthenticator(authenticateResult: false));

        $connection = $this->connect('nobody');

        self::assertFalse($connection->isConnected());
        self::assertTrue($this->stream($connection)->closed);
        self::assertSame(0x05, $this->lastSent($connection, ConnackPacket::class)->returnCode);
    }

    public function testRejectedCredentialsGetBadUserNameOrPasswordOnV50(): void
    {
        $this->makeHandler(new RecordingAuthenticator(authenticateResult: false));

        $connection = $this->connect('nobody', version: ProtocolVersion::V50, username: 'wrong');

        // 0x05 means "Unspecified error" in 5.0, so a client told only that cannot
        // distinguish bad credentials from a broker fault. 0x86 says which it was.
        self::assertFalse($connection->isConnected());
        self::assertSame(0x86, $this->lastSent($connection, ConnackPacket::class)->returnCode);
    }

    public function testRejectedCredentialsAreLoggedWithTheRemoteAddress(): void
    {
        $this->makeHandler(new RecordingAuthenticator(authenticateResult: false));

        $this->connect('nobody', username: 'mallory');

        // Without this line a guessing run leaves no trace at all, and a log-based
        // blocker has nothing to match on.
        $warnings = $this->logger->messagesAt('warning');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('Authentication failed', $warnings[0]);
        self::assertStringContainsString('mallory', $warnings[0]);
        self::assertStringContainsString('127.0.0.1:1883', $warnings[0]);
    }

    public function testSuccessfulLoginLogsNoAuthenticationFailure(): void
    {
        $this->makeHandler(new RecordingAuthenticator());

        $this->connect('welcome', username: 'alice');

        self::assertSame([], $this->logger->messagesAt('warning'));
    }

    public function testAnonymousRejectionNamesNoUsernameRatherThanBlank(): void
    {
        $this->makeHandler(new RecordingAuthenticator(authenticateResult: false));

        $this->connect('anon');

        self::assertStringContainsString('<none>', $this->logger->messagesAt('warning')[0]);
    }

    public function testUsernameCannotForgeALogLine(): void
    {
        $this->makeHandler(new RecordingAuthenticator(authenticateResult: false));

        // A username is attacker-chosen and survives UTF-8 validation with newlines
        // intact, so an unescaped one could fabricate whole entries.
        $this->connect('nobody', username: "admin\n[00:00:00] warning: Authentication failed for root");

        $logged = $this->logger->messagesAt('warning')[0];
        self::assertStringNotContainsString("\n", $logged);
        self::assertStringContainsString('\\n', $logged);
    }

    public function testDeniedPublishNeverReachesSubscribersOrRetainedStore(): void
    {
        $this->makeHandler(new RecordingAuthenticator(deniedPublishTopics: ['secret/data']));

        $subscriber = $this->connect('subscriber');
        $this->handler->handle($subscriber, new SubscribePacket(1, [
            ['topic' => 'secret/data', 'qos' => 0],
        ]));
        $before = $this->countSent($subscriber, PublishPacket::class);

        $publisher = $this->connect('publisher');
        $this->handler->handle($publisher, new PublishPacket(
            topicName: 'secret/data',
            payload: 'classified',
            retain: true,
        ));

        self::assertSame($before, $this->countSent($subscriber, PublishPacket::class));
        self::assertSame(0, $this->handler->getRetainedMessages()->count());
    }

    public function testDeniedPublishStillAcknowledgesQos1(): void
    {
        $this->makeHandler(new RecordingAuthenticator(deniedPublishTopics: ['blocked']));

        $publisher = $this->connect('publisher');
        $this->handler->handle($publisher, new PublishPacket(
            topicName: 'blocked',
            payload: 'x',
            qos: 1,
            packetId: 42,
        ));

        // MQTT 3.1.1 has no way to refuse a publish, so it is acknowledged and dropped.
        // Withholding the PUBACK would stall the client instead.
        $puback = $this->lastSent($publisher, \PhpMqtt\Broker\Protocol\Packet\PubackPacket::class);
        self::assertSame(42, $puback->packetId);
    }

    public function testEmptyRetainedPayloadCannotDeleteADeniedTopic(): void
    {
        $this->makeHandler(new RecordingAuthenticator());

        $owner = $this->connect('owner');
        $this->handler->handle($owner, new PublishPacket(
            topicName: 'device/state',
            payload: 'online',
            retain: true,
        ));
        self::assertSame(1, $this->handler->getRetainedMessages()->count());

        // Now deny that topic and let another client try to clear it.
        $this->authenticator->deniedPublishTopics = ['device/state'];
        $attacker = $this->connect('attacker');
        $this->handler->handle($attacker, new PublishPacket(
            topicName: 'device/state',
            payload: '',
            retain: true,
        ));

        self::assertSame(1, $this->handler->getRetainedMessages()->count());
    }

    public function testDeniedSubscriptionIsRefusedWithFailureCode(): void
    {
        $this->makeHandler(new RecordingAuthenticator(deniedSubscribeFilters: ['secret/#']));

        $connection = $this->connect('client');
        $this->handler->handle($connection, new SubscribePacket(7, [
            ['topic' => 'secret/#', 'qos' => 0],
            ['topic' => 'public/#', 'qos' => 1],
        ]));

        $suback = $this->lastSent($connection, SubackPacket::class);
        self::assertSame([0x80, 0x01], $suback->returnCodes);
        self::assertFalse($this->subscriptions->hasSubscription('client', 'secret/#'));
        self::assertTrue($this->subscriptions->hasSubscription('client', 'public/#'));
    }

    public function testClientIdCannotBeUsedByAnotherIdentity(): void
    {
        $this->makeHandler(new RecordingAuthenticator(deniedClientIds: ['victim']));

        $connection = $this->connect('victim', username: 'mallory');

        self::assertFalse($connection->isConnected());
        self::assertTrue($this->stream($connection)->closed);
    }

    public function testTakeoverCannotStealAnotherClientsSession(): void
    {
        $this->makeHandler(new RecordingAuthenticator(deniedClientIds: ['sensor-01']));

        // The legitimate owner is not affected by a refused takeover attempt.
        $this->authenticator->deniedClientIds = [];
        $victim = $this->connect('sensor-01', cleanSession: false);
        self::assertTrue($victim->isConnected());

        $this->authenticator->deniedClientIds = ['sensor-01'];
        $attacker = $this->connect('sensor-01', username: 'mallory');

        self::assertFalse($attacker->isConnected());
        self::assertTrue($victim->isConnected(), 'A refused takeover must not disconnect the owner');
    }

    public function testWillOnAForbiddenTopicIsRefusedAtConnectTime(): void
    {
        $this->makeHandler(new RecordingAuthenticator(deniedPublishTopics: ['admin/shutdown']));

        $connection = $this->connection();
        $this->handler->handle($connection, new ConnectPacket(
            protocolName: 'MQTT',
            protocolLevel: ProtocolVersion::V311->value,
            cleanSession: true,
            keepAlive: 60,
            clientId: 'willer',
            hasWill: true,
            willTopic: 'admin/shutdown',
            willPayload: 'boom',
        ));

        // Refusing at CONNECT is clearer than accepting a will that would be dropped
        // silently much later, when the client is no longer around to be told.
        self::assertFalse($connection->isConnected());
        self::assertTrue($this->stream($connection)->closed);
    }

    public function testRestoredSubscriptionsAreReauthorizedOnReconnect(): void
    {
        $this->makeHandler(new RecordingAuthenticator());

        $first = $this->connect('persistent', cleanSession: false);
        $this->handler->handle($first, new SubscribePacket(1, [
            ['topic' => 'secret/#', 'qos' => 0],
            ['topic' => 'public/#', 'qos' => 0],
        ]));
        $this->handler->handleDisconnect($first, true);
        $this->connections->remove($first);

        // Access is revoked while the client is away.
        $this->authenticator->deniedSubscribeFilters = ['secret/#'];
        $this->connect('persistent', cleanSession: false);

        self::assertFalse(
            $this->subscriptions->hasSubscription('persistent', 'secret/#'),
            'A grant made before revocation must not survive a reconnect',
        );
        self::assertTrue($this->subscriptions->hasSubscription('persistent', 'public/#'));
    }

    public function testAuthenticatorFailureDeniesRatherThanGrants(): void
    {
        $this->makeHandler(new RecordingAuthenticator(throwOn: 'canPublish'));

        $publisher = $this->connect('publisher');
        $this->handler->handle($publisher, new PublishPacket(
            topicName: 'anything',
            payload: 'x',
            retain: true,
        ));

        // A backend that is briefly down must fail closed, and must not escape into
        // the event loop, where it would drop every connected client.
        self::assertSame(0, $this->handler->getRetainedMessages()->count());
    }
}
