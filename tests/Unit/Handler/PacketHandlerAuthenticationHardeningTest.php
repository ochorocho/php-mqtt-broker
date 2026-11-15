<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Handler;

use PhpMqtt\Broker\Auth\PasswordFileAuthenticator;
use PhpMqtt\Broker\Configuration;
use PhpMqtt\Broker\Protocol\Packet\ConnackPacket;
use PhpMqtt\Broker\Protocol\Packet\ConnectPacket;
use PhpMqtt\Broker\Protocol\Packet\PublishPacket;
use PhpMqtt\Broker\Protocol\Packet\SubscribePacket;
use PhpMqtt\Broker\Protocol\ProtocolVersion;

/**
 * Password guessing, client-ID ownership, and who an authorization check is made for.
 */
final class PacketHandlerAuthenticationHardeningTest extends PacketHandlerTestCase
{
    public function testAHostOverTheFailureBudgetIsRefusedWithoutAuthenticating(): void
    {
        $authenticator = new RecordingAuthenticator(authenticateResult: false);
        $this->makeHandler($authenticator, new Configuration(maxAuthFailuresPerHost: 2));

        $this->connect('guess-1', username: 'u', remoteAddress: 'tcp://203.0.113.9:40001');
        $this->connect('guess-2', username: 'u', remoteAddress: 'tcp://203.0.113.9:40002');
        $third = $this->connect('guess-3', username: 'u', remoteAddress: 'tcp://203.0.113.9:40003');

        // Each check is a bcrypt hash on the only event-loop thread; a host that keeps
        // failing must stop costing one.
        self::assertSame(2, $this->authenticateCalls($authenticator));
        self::assertTrue($this->stream($third)->closed);
        self::assertSame(0x05, $this->lastSent($third, ConnackPacket::class)->returnCode);
    }

    public function testAThrottledMqtt5ClientIsToldTheConnectionRateWasExceeded(): void
    {
        $this->makeHandler(
            new RecordingAuthenticator(authenticateResult: false),
            new Configuration(maxAuthFailuresPerHost: 1),
        );

        $this->connect('guess-1', version: ProtocolVersion::V50, username: 'u', remoteAddress: 'tcp://203.0.113.9:1');
        $second = $this->connect('guess-2', version: ProtocolVersion::V50, username: 'u', remoteAddress: 'tcp://203.0.113.9:2');

        self::assertSame(0x9F, $this->lastSent($second, ConnackPacket::class, ProtocolVersion::V50)->returnCode);
    }

    public function testOtherHostsAreNotThrottledByOnesFailures(): void
    {
        $authenticator = new RecordingAuthenticator(authenticateResult: false);
        $this->makeHandler($authenticator, new Configuration(maxAuthFailuresPerHost: 1));

        $this->connect('guess', username: 'u', remoteAddress: 'tcp://203.0.113.9:1');
        $this->connect('other', username: 'u', remoteAddress: 'tcp://198.51.100.1:1');

        self::assertSame(2, $this->authenticateCalls($authenticator));
    }

    public function testAThrottledAttemptIsNotLoggedAsAnAuthenticationFailure(): void
    {
        $this->makeHandler(
            new RecordingAuthenticator(authenticateResult: false),
            new Configuration(maxAuthFailuresPerHost: 1),
        );

        $this->connect('guess-1', username: 'u', remoteAddress: 'tcp://203.0.113.9:1');
        $this->connect('guess-2', username: 'u', remoteAddress: 'tcp://203.0.113.9:2');

        // The fail2ban filter matches "Authentication failed"; a throttled attempt was
        // never checked, so it says so on a line of its own.
        $warnings = $this->logger->messagesAt('warning');
        self::assertCount(2, $warnings);
        self::assertStringContainsString('Authentication failed', $warnings[0]);
        self::assertStringNotContainsString('Authentication failed', $warnings[1]);
        self::assertStringContainsString('throttled', $warnings[1]);
        self::assertStringContainsString('203.0.113.9', $warnings[1]);
    }

    public function testSuccessfulLoginsAreNotThrottled(): void
    {
        $authenticator = new RecordingAuthenticator();
        $this->makeHandler($authenticator, new Configuration(maxAuthFailuresPerHost: 1));

        for ($i = 0; $i < 5; $i++) {
            self::assertTrue($this->connect('device-' . $i, remoteAddress: 'tcp://203.0.113.9:' . $i)->isConnected());
        }
    }

    public function testABrokerAssignedClientIdNeedsNoOwner(): void
    {
        // With client IDs bound to accounts, the broker's own 'auto-…' IDs used to be
        // refused, so an empty client ID never worked.
        $this->makeHandler(new PasswordFileAuthenticator([
            'alice' => password_hash('pw', PASSWORD_DEFAULT, ['cost' => 4]),
        ]));

        foreach ([ProtocolVersion::V311, ProtocolVersion::V50] as $version) {
            $connection = $this->connection();
            $this->handler->handle($connection, new ConnectPacket(
                protocolName: 'MQTT',
                protocolLevel: $version->value,
                cleanSession: true,
                keepAlive: 60,
                clientId: '',
                hasUsername: true,
                username: 'alice',
                hasPassword: true,
                password: 'pw',
            ));

            self::assertTrue($connection->isConnected(), $version->name);
            self::assertStringStartsWith('auto-', (string) $connection->getClientId());
        }
    }

    public function testAuthorizationChecksAreMadeForTheAuthenticatedUsername(): void
    {
        $authenticator = new RecordingAuthenticator();
        $this->makeHandler($authenticator);

        $connection = $this->connect('alice-phone', username: 'alice');
        $this->handler->handle($connection, new SubscribePacket(1, [['topic' => 'alice/#', 'qos' => 0]]));
        $this->handler->handle($connection, new PublishPacket(topicName: 'alice/x', payload: 'p'));

        self::assertSame(['canSubscribe:alice', 'canPublish:alice'], $authenticator->authorizedAs);
    }

    public function testARefusedClaimOnAClientIdDoesNotChangeWhoItIsAuthorizedAs(): void
    {
        $authenticator = new RecordingAuthenticator();
        $this->makeHandler($authenticator);
        $owner = $this->connect('alice-phone', username: 'alice');

        // Mallory has valid credentials but may not use alice's client ID. Authorizers
        // that remembered "who is alice-phone" during authenticate() were poisoned by
        // exactly this attempt; with the username passed in, there is nothing to poison.
        $authenticator->deniedClientIds = ['alice-phone'];
        $this->connect('alice-phone', username: 'mallory');
        $authenticator->deniedClientIds = [];

        $authenticator->authorizedAs = [];
        $this->handler->handle($owner, new PublishPacket(topicName: 'alice/x', payload: 'p'));

        self::assertTrue($owner->isConnected());
        self::assertSame(['canPublish:alice'], $authenticator->authorizedAs);
    }

    public function testAWillIsAuthorizedForTheUsernameAtPublicationToo(): void
    {
        $authenticator = new RecordingAuthenticator();
        $this->makeHandler($authenticator);

        $connection = $this->connection();
        $this->handler->handle($connection, new ConnectPacket(
            protocolName: 'MQTT',
            protocolLevel: ProtocolVersion::V311->value,
            cleanSession: true,
            keepAlive: 60,
            clientId: 'alice-phone',
            hasWill: true,
            willTopic: 'alice/status',
            willPayload: 'gone',
            hasUsername: true,
            username: 'alice',
        ));
        $this->handler->handleDisconnect($connection, false);

        self::assertSame(['canPublish:alice', 'canPublish:alice'], $authenticator->authorizedAs);
    }

    private function authenticateCalls(RecordingAuthenticator $authenticator): int
    {
        return count(array_filter(
            $authenticator->calls,
            static fn(string $call): bool => str_starts_with($call, 'authenticate:'),
        ));
    }
}
