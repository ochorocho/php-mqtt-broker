<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Protocol;

use PhpMqtt\Broker\Exception\MalformedPacketException;

final class PacketStream
{
    private string $buffer = '';

    /**
     * @param int $maxPacketSize Cap on both a single packet and the buffer itself.
     *                           Without it a client can declare a remaining length of
     *                           up to 268 MB, or simply never complete a packet, and
     *                           the buffer grows until the process runs out of memory.
     */
    public function __construct(
        private readonly int $maxPacketSize = 1048576,
    ) {
    }

    public function append(string $data): void
    {
        if (strlen($this->buffer) + strlen($data) > $this->maxPacketSize) {
            throw new MalformedPacketException(sprintf(
                'Receive buffer would exceed maximum packet size of %d bytes',
                $this->maxPacketSize,
            ));
        }

        $this->buffer .= $data;
    }

    public function hasCompletePacket(): bool
    {
        $length = strlen($this->buffer);
        if ($length < 2) {
            return false;
        }

        // Parse remaining length starting at byte index 1
        $offset = 1;
        $multiplier = 1;
        $remainingLength = 0;

        do {
            if ($offset >= $length) {
                return false; // Need more data for remaining length
            }

            $byte = ord($this->buffer[$offset]);
            $remainingLength += ($byte & 0x7F) * $multiplier;
            $offset++;

            if ($multiplier > 128 * 128 * 128) {
                throw new MalformedPacketException('Malformed remaining length');
            }

            $multiplier *= 128;
        } while (($byte & 0x80) !== 0);

        // Total packet size = fixed header byte + remaining length bytes + remaining length value
        $totalSize = $offset + $remainingLength;

        // Reject an oversized packet as soon as its declared length is known, rather
        // than buffering toward a limit it can never satisfy.
        if ($totalSize > $this->maxPacketSize) {
            throw new MalformedPacketException(sprintf(
                'Packet size %d exceeds maximum of %d bytes',
                $totalSize,
                $this->maxPacketSize,
            ));
        }

        return $length >= $totalSize;
    }

    public function nextPacket(): string
    {
        if (!$this->hasCompletePacket()) {
            throw new MalformedPacketException('No complete packet in buffer');
        }

        $length = strlen($this->buffer);

        // Parse remaining length again to determine packet boundaries
        $offset = 1;
        $multiplier = 1;
        $remainingLength = 0;

        do {
            $byte = ord($this->buffer[$offset]);
            $remainingLength += ($byte & 0x7F) * $multiplier;
            $offset++;
            $multiplier *= 128;
        } while (($byte & 0x80) !== 0);

        $totalSize = $offset + $remainingLength;
        $packet = substr($this->buffer, 0, $totalSize);

        if ($totalSize >= $length) {
            $this->buffer = '';
        } else {
            $this->buffer = substr($this->buffer, $totalSize);
        }

        return $packet;
    }

    public function getBufferLength(): int
    {
        return strlen($this->buffer);
    }

    public function clear(): void
    {
        $this->buffer = '';
    }
}
