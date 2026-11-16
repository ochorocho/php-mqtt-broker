<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Broker;

use PhpMqtt\Broker\Configuration;
use PhpMqtt\Broker\Protocol\DataType;
use PhpMqtt\Broker\Protocol\Packet\ConnackPacket;
use PhpMqtt\Broker\Protocol\Packet\PingreqPacket;
use PhpMqtt\Broker\Protocol\Packet\PingrespPacket;
use PhpMqtt\Broker\Protocol\Packet\PublishPacket;
use PhpMqtt\Broker\Protocol\Packet\SubscribePacket;
use PhpMqtt\Broker\Tests\Unit\Handler\RecordingAuthenticator;

/**
 * Nothing a client sends may escape Broker::onData: the broker is one process with
 * one event loop, so an exception there stops it for every client.
 */
final class BrokerRobustnessTest extends BrokerTestCase
{
    public function testMaximumSizePublishFollowedByAnotherPacketIsProcessed(): void
    {
        $this->startBroker(new Configuration(maxPacketSize: 4096));

        $subscriber = $this->connectClient('sub');
        $subscriber->push(self::encode(new SubscribePacket(
            packetId: 1,
            subscriptions: [['topic' => 't', 'qos' => 0]],
        )));

        $publisher = $this->connectClient('pub', remoteAddress: 'tcp://127.0.0.1:50001');
        $publish = self::encode(new PublishPacket(topicName: 't', payload: str_repeat('p', 4090)));
        self::assertSame(4096, strlen($publish));

        // The last byte of the publish shares a read with the next packet.
        $publisher->push(substr($publish, 0, -1));
        $publisher->push(substr($publish, -1) . self::encode(new PingreqPacket()));

        self::assertFalse($publisher->closed);
        self::assertCount(1, self::receivedOfType($publisher, PingrespPacket::class));
        self::assertCount(1, self::receivedOfType($subscriber, PublishPacket::class));
    }

    public function testOversizedTrailingDataClosesOnlyThatConnection(): void
    {
        $this->startBroker(new Configuration(maxPacketSize: 4096));
        $bystander = $this->connectClient('bystander');

        // A CONNECT header declaring the maximum, its body, and more bytes behind it.
        $attacker = $this->server->connect('tcp://203.0.113.9:40000');
        $remaining = 4096 - 3;
        $attacker->push("\x10" . DataType::encodeVariableByteInteger($remaining) . str_repeat("\x00", $remaining - 1));
        $attacker->push("\x00" . str_repeat("\xFF", 64));

        self::assertTrue($attacker->closed);
        self::assertFalse($bystander->closed);

        // The broker still serves new clients.
        $late = $this->connectClient('late', remoteAddress: 'tcp://127.0.0.1:50002');
        self::assertCount(1, self::receivedOfType($late, ConnackPacket::class));
    }

    public function testNothingAfterARefusedConnectIsProcessed(): void
    {
        $authenticator = new RecordingAuthenticator(authenticateResult: false);
        $this->startBroker(authenticator: $authenticator);

        // Many CONNECTs in one read. Each would cost a password hash, and the first
        // refusal closes the connection; the rest must not be handled.
        $stream = $this->server->connect();
        $stream->push(str_repeat(self::connectBytes('guess', username: 'u', password: 'p'), 20));

        self::assertTrue($stream->closed);
        self::assertSame(['authenticate:guess'], array_values(array_filter(
            $authenticator->calls,
            static fn(string $call): bool => str_starts_with($call, 'authenticate:'),
        )));
    }

    public function testPartialPacketOtherThanConnectIsRejectedImmediately(): void
    {
        $stream = $this->server->connect();

        // The start of a PUBLISH, well within the size limit but incomplete. Nothing
        // more needs to arrive for the broker to know it is not a CONNECT.
        $stream->push("\x30\x7F\x00");

        self::assertTrue($stream->closed);
    }

    public function testFirstPacketOtherThanConnectIsRejectedBeforeDecoding(): void
    {
        $stream = $this->server->connect();

        // A topic the decoder would reject with its own message. It must never get that
        // far: an unauthenticated peer should not reach the packet decoders at all.
        $stream->push(self::encode(new PublishPacket(topicName: "a/+\ninjected", payload: 'x')));

        self::assertTrue($stream->closed);
        $warnings = $this->logger->messagesAt('warning');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('First packet must be CONNECT', $warnings[0]);
        self::assertStringNotContainsString('injected', $warnings[0]);
    }

    public function testUnauthenticatedPeersGetASmallerPacketLimit(): void
    {
        $this->startBroker(new Configuration(maxConnectPacketSize: 1024));

        // Before CONNECT a peer has proven nothing, yet could buffer a full-size packet.
        // Ten thousand of them used to be ten gigabytes.
        $stream = $this->server->connect();
        $stream->push("\x10" . DataType::encodeVariableByteInteger(2000) . str_repeat("\x00", 100));

        self::assertTrue($stream->closed);
    }

    public function testTheFullPacketLimitAppliesOnceConnected(): void
    {
        $this->startBroker(new Configuration(maxPacketSize: 8192, maxConnectPacketSize: 1024));

        $subscriber = $this->connectClient('sub');
        $subscriber->push(self::encode(new SubscribePacket(packetId: 1, subscriptions: [['topic' => 't', 'qos' => 0]])));

        $publisher = $this->connectClient('pub', remoteAddress: 'tcp://127.0.0.1:50001');
        $publisher->push(self::encode(new PublishPacket(topicName: 't', payload: str_repeat('p', 4000))));

        self::assertFalse($publisher->closed);
        self::assertCount(1, self::receivedOfType($subscriber, PublishPacket::class));
    }

    public function testASubscriberThatStopsReadingIsDisconnected(): void
    {
        $this->startBroker(new Configuration(maxOutboundBufferBytes: 10_000));

        $subscriber = $this->connectClient('slow');
        $subscriber->push(self::encode(new SubscribePacket(packetId: 1, subscriptions: [['topic' => 't', 'qos' => 0]])));
        $subscriber->writeResult = false;

        $publisher = $this->connectClient('pub', remoteAddress: 'tcp://127.0.0.1:50001');
        for ($i = 0; $i < 50; $i++) {
            $publisher->push(self::encode(new PublishPacket(topicName: 't', payload: str_repeat('p', 500))));
        }

        self::assertTrue($subscriber->closed);
        self::assertFalse($publisher->closed);
        self::assertNotSame([], array_filter(
            $this->logger->messagesAt('warning'),
            static fn(string $line): bool => str_contains($line, 'outbound buffer'),
        ));
    }

    public function testRestoredRetainedTopicsAreHeldToTheTopicLimits(): void
    {
        $directory = sys_get_temp_dir() . '/mqtt-limits-' . bin2hex(random_bytes(4));
        mkdir($directory);
        $path = $directory . '/retained.ndjson';
        file_put_contents($path, implode("\n", [
            json_encode(['t' => str_repeat('t', 50), 'p' => base64_encode('x'), 'q' => 0, 'v' => 4]),
            json_encode(['t' => 'ok', 'p' => base64_encode('y'), 'q' => 0, 'v' => 4]),
        ]) . "\n");

        try {
            // A file written under looser limits must not pin more than these allow.
            $this->startBroker(new Configuration(maxTopicLength: 20, retainedStatePath: $path));

            self::assertSame(['ok'], array_keys($this->broker->getPacketHandler()->getRetainedMessages()->all()));
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }
}
