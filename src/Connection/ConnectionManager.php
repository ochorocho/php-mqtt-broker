<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Connection;

final class ConnectionManager
{
    /** @var array<string, Connection> clientId => Connection */
    private array $connections = [];

    /** @var \SplObjectStorage<Connection, true> All connections including unauthenticated */
    private \SplObjectStorage $allConnections;

    public function __construct()
    {
        $this->allConnections = new \SplObjectStorage();
    }

    public function add(Connection $connection): void
    {
        $this->allConnections[$connection] = true;
    }

    public function register(string $clientId, Connection $connection): void
    {
        $this->connections[$clientId] = $connection;
    }

    public function getByClientId(string $clientId): ?Connection
    {
        return $this->connections[$clientId] ?? null;
    }

    public function remove(Connection $connection): void
    {
        unset($this->allConnections[$connection]);

        $clientId = $connection->getClientId();
        if ($clientId !== null && isset($this->connections[$clientId]) && $this->connections[$clientId] === $connection) {
            unset($this->connections[$clientId]);
        }
    }

    public function count(): int
    {
        return $this->allConnections->count();
    }

    /**
     * @return Connection[]
     */
    public function getAll(): array
    {
        $connections = [];
        foreach ($this->allConnections as $conn) {
            $connections[] = $conn;
        }
        return $connections;
    }
}
