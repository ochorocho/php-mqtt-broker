<?php

declare(strict_types=1);

namespace PhpMqtt\Broker;

use PhpMqtt\Broker\Auth\AllowAllAuthenticator;
use PhpMqtt\Broker\Auth\AuthenticatorInterface;
use PhpMqtt\Broker\Connection\Connection;
use PhpMqtt\Broker\Connection\ConnectionManager;
use PhpMqtt\Broker\Exception\MalformedPacketException;
use PhpMqtt\Broker\Exception\ProtocolViolationException;
use PhpMqtt\Broker\Handler\PacketHandler;
use PhpMqtt\Broker\Protocol\PacketEncoder;
use PhpMqtt\Broker\Protocol\PacketFactory;
use PhpMqtt\Broker\Server\ConnectionStream;
use PhpMqtt\Broker\Server\ReactPhpServer;
use PhpMqtt\Broker\Server\ServerInterface;
use PhpMqtt\Broker\Subscription\SubscriptionManager;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class Broker
{
    private readonly ConnectionManager $connectionManager;
    private readonly SubscriptionManager $subscriptionManager;
    private readonly PacketFactory $packetFactory;
    private readonly PacketEncoder $packetEncoder;
    private readonly PacketHandler $packetHandler;
    public bool $running = false;

    public function __construct(
        private readonly Configuration $config = new Configuration(),
        private readonly ServerInterface $server = new ReactPhpServer(),
        ?AuthenticatorInterface $authenticator = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
        $this->connectionManager = new ConnectionManager();
        $this->subscriptionManager = new SubscriptionManager();
        $this->packetFactory = new PacketFactory();
        $this->packetEncoder = new PacketEncoder();

        $this->packetHandler = new PacketHandler(
            connectionManager: $this->connectionManager,
            subscriptionManager: $this->subscriptionManager,
            authenticator: $authenticator ?? new AllowAllAuthenticator(),
            loop: $this->server->getLoop(),
            packetEncoder: $this->packetEncoder,
            logger: $this->logger,
            eventDispatcher: $this->eventDispatcher,
            config: $this->config,
        );
    }

    public function start(): void
    {
        $uri = $this->config->getListenUri();
        $this->logger->info('MQTT Broker starting on {uri}', ['uri' => $uri]);

        $this->server->listen($uri, function (ConnectionStream $stream): void {
            $this->onConnection($stream);
        });

        // Expiry used to be evaluated only when a session was looked up, so sessions
        // nobody asked for again were never reclaimed. Sweep them on a timer instead.
        $this->server->getLoop()->addPeriodicTimer(60.0, function (): void {
            try {
                $reaped = $this->packetHandler->getSessionManager()->reapExpired();
                if ($reaped > 0) {
                    $this->logger->debug('Reaped {count} expired session(s)', ['count' => $reaped]);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Session reaper failed: {error}', [
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        });

        $this->running = true;
        $this->server->getLoop()->run();
    }

    public function stop(): void
    {
        $this->running = false;
        $this->logger->info('MQTT Broker stopping');

        // Close all connections
        foreach ($this->connectionManager->getAll() as $connection) {
            $connection->close();
        }

        $this->server->stop();
        $this->server->getLoop()->stop();
    }

    public function getConnectionManager(): ConnectionManager
    {
        return $this->connectionManager;
    }

    public function getSubscriptionManager(): SubscriptionManager
    {
        return $this->subscriptionManager;
    }

    public function getPacketHandler(): PacketHandler
    {
        return $this->packetHandler;
    }

    private function onConnection(ConnectionStream $stream): void
    {
        if ($this->connectionManager->count() >= $this->config->maxConnections) {
            $stream->close();
            return;
        }

        $connection = new Connection(
            $stream,
            $this->packetEncoder,
            $this->server->getLoop(),
            $this->config->maxPacketSize,
        );
        $this->connectionManager->add($connection);

        $this->logger->debug('New TCP connection from {address}', [
            'address' => $stream->getRemoteAddress(),
        ]);

        // Drop the connection if CONNECT never arrives. Otherwise an unauthenticated
        // peer can hold a slot indefinitely and exhaust maxConnections for free.
        $connection->startConnectTimer(
            $this->config->connectTimeout,
            function (Connection $conn): void {
                $this->logger->debug('CONNECT timeout from {address}', [
                    'address' => $conn->getRemoteAddress(),
                ]);
                $conn->close();
            },
        );

        $stream->onData(function (string $data) use ($connection): void {
            $this->onData($connection, $data);
        });

        $stream->onClose(function () use ($connection): void {
            $this->onClose($connection);
        });
    }

    private function onData(Connection $connection, string $data): void
    {
        $connection->updateActivity();
        $connection->getPacketStream()->append($data);

        try {
            while ($connection->getPacketStream()->hasCompletePacket()) {
                $rawPacket = $connection->getPacketStream()->nextPacket();
                $version = $connection->isConnected() ? $connection->getProtocolVersion() : null;

                $packet = $this->packetFactory->decode($rawPacket, $version);
                $this->packetHandler->handle($connection, $packet);
            }
        } catch (MalformedPacketException|ProtocolViolationException $e) {
            $this->logger->warning('Protocol error from {client}: {error}', [
                'client' => $connection->getClientId() ?? $connection->getRemoteAddress(),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
            // Only close if not already being disconnected (clientId set + connected=false means graceful disconnect in progress)
            if ($connection->isConnected() || $connection->getClientId() === null) {
                $connection->close();
            }
        } catch (\Throwable $e) {
            // The broker is a single process with one event loop: anything escaping here
            // would reach $loop->run() and drop every connected client. Contain the
            // failure to the connection that caused it.
            $this->logger->error('Unexpected error handling data from {client}: {error}', [
                'client' => $connection->getClientId() ?? $connection->getRemoteAddress(),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
            $connection->close();
        }
    }

    private function onClose(Connection $connection): void
    {
        // handleDisconnect publishes will messages and encodes packets, either of which
        // can throw. This runs from the socket's close callback, outside onData's guard.
        try {
            $this->packetHandler->handleDisconnect($connection, false);
        } catch (\Throwable $e) {
            $this->logger->error('Error during disconnect of {client}: {error}', [
                'client' => $connection->getClientId() ?? $connection->getRemoteAddress(),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }

        $this->connectionManager->remove($connection);

        $this->logger->debug('Connection closed: {client}', [
            'client' => $connection->getClientId() ?? $connection->getRemoteAddress(),
        ]);
    }
}
