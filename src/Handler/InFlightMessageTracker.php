<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Handler;

use PhpMqtt\Broker\Connection\Connection;
use PhpMqtt\Broker\Protocol\Packet\PublishPacket;

/**
 * Per-client packet identifiers and the messages awaiting acknowledgement.
 *
 * QoS 1 and 2 are state machines: a message is in flight until its ack arrives, and a
 * packet identifier cannot be reused while any flow still holds it. Keeping that state
 * and the identifier allocator together is what lets allocatePacketId know which ids
 * are actually free.
 */
final class InFlightMessageTracker
{
    private const int MAX_PACKET_ID = 65535;

    /** @var array<string, array<int, PublishPacket>> clientId => [packetId => packet] awaiting PUBREL */
    private array $incomingQoS2 = [];

    /** @var array<string, array<int, PublishPacket>> clientId => [packetId => packet] awaiting PUBACK or PUBREC */
    private array $outgoing = [];

    /** @var array<string, array<int, true>> clientId => [packetId => true] awaiting PUBCOMP */
    private array $awaitingPubcomp = [];

    /** @var array<string, int> clientId => next candidate packet id */
    private array $nextPacketId = [];

    public function allocatePacketId(string $clientId): int
    {
        $id = $this->advance($clientId);

        $attempts = self::MAX_PACKET_ID;
        while ($attempts > 0 && $this->isInUse($clientId, $id)) {
            $id = $this->advance($clientId);
            $attempts--;
        }

        return $id;
    }

    /**
     * Send a message and record it if it needs acknowledging.
     *
     * Sending, recording and incrementing the flow-control counter have to happen
     * together: a site that sends without recording leaves the counter drifting from
     * the outstanding messages, and flow control degrades for the rest of the session.
     */
    public function sendTracked(Connection $connection, PublishPacket $packet, string $clientId): void
    {
        $connection->send($packet);

        if ($packet->qos > 0 && $packet->packetId !== null) {
            $this->outgoing[$clientId][$packet->packetId] = $packet;
            $connection->incrementUnackedOutgoing();
        }
    }

    /** A QoS 2 message held until its PUBREL arrives. */
    public function holdIncoming(string $clientId, PublishPacket $packet): void
    {
        if ($packet->packetId !== null) {
            $this->incomingQoS2[$clientId][$packet->packetId] = $packet;
        }
    }

    public function takeIncoming(string $clientId, int $packetId): ?PublishPacket
    {
        $packet = $this->incomingQoS2[$clientId][$packetId] ?? null;
        unset($this->incomingQoS2[$clientId][$packetId]);

        return $packet;
    }

    public function countIncoming(string $clientId): int
    {
        return count($this->incomingQoS2[$clientId] ?? []);
    }

    /** PUBACK settles a QoS 1 message outright. */
    public function acknowledgeOutgoing(string $clientId, int $packetId): void
    {
        unset($this->outgoing[$clientId][$packetId]);
    }

    /** PUBREC moves a QoS 2 message from "unacknowledged" to "awaiting PUBCOMP". */
    public function awaitPubcomp(string $clientId, int $packetId): void
    {
        unset($this->outgoing[$clientId][$packetId]);
        $this->awaitingPubcomp[$clientId][$packetId] = true;
    }

    public function completeOutgoing(string $clientId, int $packetId): void
    {
        unset($this->awaitingPubcomp[$clientId][$packetId]);
    }

    /**
     * Messages still unacknowledged, for saving into a session on disconnect.
     *
     * @return list<PublishPacket>
     */
    public function unacknowledged(string $clientId): array
    {
        return array_values($this->outgoing[$clientId] ?? []);
    }

    public function forget(string $clientId): void
    {
        unset(
            $this->incomingQoS2[$clientId],
            $this->outgoing[$clientId],
            $this->awaitingPubcomp[$clientId],
            $this->nextPacketId[$clientId],
        );
    }

    private function advance(string $clientId): int
    {
        $id = $this->nextPacketId[$clientId] ?? 1;
        $this->nextPacketId[$clientId] = ($id >= self::MAX_PACKET_ID) ? 1 : $id + 1;

        return $id;
    }

    private function isInUse(string $clientId, int $packetId): bool
    {
        return isset($this->outgoing[$clientId][$packetId])
            || isset($this->awaitingPubcomp[$clientId][$packetId]);
    }
}
