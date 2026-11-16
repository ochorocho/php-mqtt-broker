<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Broker;

use PhpMqtt\Broker\Configuration;
use PhpMqtt\Broker\Protocol\Packet\PublishPacket;
use PhpMqtt\Broker\Tests\Unit\Handler\RecordingAuthenticator;

/**
 * Client IDs and topics are attacker-chosen, and MQTT's UTF-8 rules allow newlines in
 * both. A line break reaching the log splits one record into two, and the second is
 * whatever the attacker wrote — for example a failed login a fail2ban jail acts on.
 */
final class BrokerLoggingTest extends BrokerTestCase
{
    private const string FORGED = "\n[2026-11-15 10:00:00+00:00] warning: Authentication failed for x from 198.51.100.7:1";

    public function testClientIdCannotForgeALogLine(): void
    {
        $stream = $this->connectClient('device' . self::FORGED);
        $stream->close();

        $this->assertNoRecordContainsAControlCharacter();
    }

    public function testRejectedTopicCannotForgeALogLine(): void
    {
        $stream = $this->connectClient('publisher');
        $stream->push(self::encode(new PublishPacket(topicName: 'a/+' . self::FORGED, payload: 'x')));

        self::assertTrue($stream->closed);
        $this->assertNoRecordContainsAControlCharacter();
    }

    public function testDeniedPublishCannotForgeALogLine(): void
    {
        $topic = 'secret' . self::FORGED;
        $this->startBroker(authenticator: new RecordingAuthenticator(deniedPublishTopics: [$topic]));

        $stream = $this->connectClient('publisher');
        $stream->push(self::encode(new PublishPacket(topicName: $topic, payload: 'x')));

        self::assertNotSame([], $this->logger->messagesAt('warning'));
        $this->assertNoRecordContainsAControlCharacter();
    }

    public function testDroppedRetainedMessageCannotForgeALogLine(): void
    {
        $this->startBroker(new Configuration(maxRetainedMessages: 0));

        $stream = $this->connectClient('publisher');
        $stream->push(self::encode(new PublishPacket(topicName: 'r' . self::FORGED, payload: 'x', retain: true)));

        self::assertNotSame([], $this->logger->messagesAt('warning'));
        $this->assertNoRecordContainsAControlCharacter();
    }

    public function testFailingAuthenticatorCannotForgeALogLine(): void
    {
        $this->startBroker(authenticator: new RecordingAuthenticator(throwOn: 'canPublish'));

        $stream = $this->connectClient('publisher' . self::FORGED);
        $stream->push(self::encode(new PublishPacket(topicName: 't', payload: 'x')));

        self::assertNotSame([], $this->logger->messagesAt('error'));
        $this->assertNoRecordContainsAControlCharacter();
    }

    private function assertNoRecordContainsAControlCharacter(): void
    {
        self::assertNotSame([], $this->logger->records);

        foreach ($this->logger->records as $record) {
            self::assertDoesNotMatchRegularExpression(
                '/[\x00-\x1F\x7F]/',
                $record['message'],
                sprintf('A %s record carries a raw control character', $record['level']),
            );
        }
    }
}
