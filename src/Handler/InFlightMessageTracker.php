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
     *
     * @param ?PublishPacket $stored What to keep for redelivery, when it differs from what
     *                               goes on the wire. A topic alias belongs to the
     *                               connection it was sent on; a packet kept for a later
     *                               connection must carry its topic name instead.
     */
    public function sendTracked(Connection $connection, PublishPacket $packet, string $clientId, ?PublishPacket $stored = null): void
    {
        $connection->send($packet);

        if ($packet->qos > 0 && $packet->packetId !== null) {
            $this->outgoing[$clientId][$packet->packetId] = $stored ?? $packet;
            $connection->incrementUnackedOutgoing();
        }
    }

    /**
     * The given id if it is free, otherwise a newly allocated one. A message redelivered
     * after a reconnect keeps its id (MQTT-4.4.0-1) unless something now in flight
     * already uses it.
     */
    public function claimPacketId(string $clientId, ?int $preferred): int
    {
        if ($preferred !== null && $preferred > 0 && !$this->isInUse($clientId, $preferred)) {
            return $preferred;
        }

        return $this->allocatePacketId($clientId);
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

    /**
     * PUBACK settles a QoS 1 message outright.
     *
     * @return bool Whether the id was actually in flight. Acknowledgements are client
     *              input: one for an id that was never sent must not count as a freed
     *              slot, or a client can hold its flow-control window open forever.
     */
    public function acknowledgeOutgoing(string $clientId, int $packetId): bool
    {
        if (!isset($this->outgoing[$clientId][$packetId])) {
            return false;
        }

        unset($this->outgoing[$clientId][$packetId]);

        return true;
    }

    /**
     * PUBREC moves a QoS 2 message from "unacknowledged" to "awaiting PUBCOMP".
     *
     * @return bool Whether the id was in flight; an unknown one is not recorded.
     */
    public function awaitPubcomp(string $clientId, int $packetId): bool
    {
        if (!isset($this->outgoing[$clientId][$packetId])) {
            return false;
        }

        unset($this->outgoing[$clientId][$packetId]);
        $this->awaitingPubcomp[$clientId][$packetId] = true;

        return true;
    }

    /**
     * @return bool Whether the id was awaiting PUBCOMP.
     */
    public function completeOutgoing(string $clientId, int $packetId): bool
    {
        if (!isset($this->awaitingPubcomp[$clientId][$packetId])) {
            return false;
        }

        unset($this->awaitingPubcomp[$clientId][$packetId]);

        return true;
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

    /**
     * The client left with a session to come back to.
     *
     * Its unacknowledged deliveries have been moved into the session queue by the caller
     * and go from here. What stays is the half of each QoS 2 exchange that cannot simply
     * be redone: a message the client sent, held until its PUBREL, and a delivery the
     * client confirmed with PUBREC, which is owed a PUBREL. Both used to be dropped, so
     * exactly-once became at-most-once across any reconnect.
     */
    public function suspend(string $clientId): void
    {
        unset($this->outgoing[$clientId]);
    }

    /**
     * Ids of deliveries the client confirmed with PUBREC and has not yet completed.
     *
     * @return list<int>
     */
    public function pubcompPending(string $clientId): array
    {
        return array_keys($this->awaitingPubcomp[$clientId] ?? []);
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
