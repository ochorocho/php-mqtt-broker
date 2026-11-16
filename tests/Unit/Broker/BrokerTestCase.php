<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Broker;

use PhpMqtt\Broker\Auth\AuthenticatorInterface;
use PhpMqtt\Broker\Broker;
use PhpMqtt\Broker\Configuration;
use PhpMqtt\Broker\Protocol\Packet\ConnectPacket;
use PhpMqtt\Broker\Protocol\Packet\PacketInterface;
use PhpMqtt\Broker\Protocol\PacketEncoder;
use PhpMqtt\Broker\Protocol\PacketFactory;
use PhpMqtt\Broker\Protocol\PacketStream;
use PhpMqtt\Broker\Protocol\ProtocolVersion;
use PhpMqtt\Broker\Tests\Unit\Handler\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * Drives a whole Broker with raw bytes: the path a real socket takes, from framing
 * through decoding and handling, including the error handling around it.
 */
abstract class BrokerTestCase extends TestCase
{
    protected FakeServer $server;
    protected RecordingLogger $logger;
    protected Broker $broker;

    protected function setUp(): void
    {
        $this->startBroker();
    }

    protected function startBroker(
        ?Configuration $config = null,
        ?AuthenticatorInterface $authenticator = null,
    ): void {
        $this->server = new FakeServer();
        $this->logger = new RecordingLogger();
        $this->broker = new Broker(
            config: $config ?? new Configuration(),
            server: $this->server,
            authenticator: $authenticator,
            logger: $this->logger,
        );
        $this->broker->start();
    }

    protected function loop(): FakeLoop
    {
        return $this->server->fakeLoop();
    }

    protected static function encode(PacketInterface $packet): string
    {
        return (new PacketEncoder())->encode($packet);
    }

    protected static function connectBytes(
        string $clientId,
        ProtocolVersion $version = ProtocolVersion::V311,
        bool $cleanSession = true,
        ?string $username = null,
        ?string $password = null,
    ): string {
        return self::encode(new ConnectPacket(
            protocolName: 'MQTT',
            protocolLevel: $version->value,
            cleanSession: $cleanSession,
            keepAlive: 60,
            clientId: $clientId,
            hasUsername: $username !== null,
            username: $username,
            hasPassword: $password !== null,
            password: $password,
        ));
    }

    /**
     * Connect a client through the broker and return its stream.
     */
    protected function connectClient(
        string $clientId,
        ProtocolVersion $version = ProtocolVersion::V311,
        string $remoteAddress = 'tcp://127.0.0.1:50000',
    ): ScriptedStream {
        $stream = $this->server->connect($remoteAddress);
        $stream->push(self::connectBytes($clientId, $version));

        return $stream;
    }

    /**
     * Everything written to the stream, decoded back into packets.
     *
     * @return list<PacketInterface>
     */
    protected static function received(ScriptedStream $stream, ProtocolVersion $version = ProtocolVersion::V311): array
    {
        // Writes are not guaranteed to be one packet each, so re-frame them.
        $framer = new PacketStream(PHP_INT_MAX);
        $framer->append(implode('', $stream->writes));

        $factory = new PacketFactory();
        $packets = [];
        while ($framer->hasCompletePacket()) {
            $packets[] = $factory->decode($framer->nextPacket(), $version);
        }

        return $packets;
    }

    /**
     * @template T of PacketInterface
     * @param class-string<T> $type
     * @return list<T>
     */
    protected static function receivedOfType(ScriptedStream $stream, string $type, ProtocolVersion $version = ProtocolVersion::V311): array
    {
        return array_values(array_filter(
            self::received($stream, $version),
            static fn(PacketInterface $packet): bool => $packet instanceof $type,
        ));
    }
}
