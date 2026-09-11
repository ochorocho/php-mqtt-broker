<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Protocol;

use PhpMqtt\Broker\Exception\MalformedPacketException;

final class PacketStream
{
    public const int DEFAULT_MAX_PACKET_SIZE = 1024 * 1024;

    /** A fifth continuation byte would exceed the remaining length range. */
    private const int VARIABLE_BYTE_INT_MAX_MULTIPLIER = 128 * 128 * 128;

    private string $buffer = '';

    /**
     * @param int $maxPacketSize Cap on both a single packet and the buffer itself.
     *                           Without it a client can declare a remaining length of
     *                           up to 268 MB, or simply never complete a packet, and
     *                           the buffer grows until the process runs out of memory.
     */
    public function __construct(
        private readonly int $maxPacketSize = self::DEFAULT_MAX_PACKET_SIZE,
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
        $size = $this->totalPacketSize();
        if ($size === null) {
            return false;
        }

        return strlen($this->buffer) >= $size;
    }

    /**
     * Size of the packet at the head of the buffer, or null while the fixed header is
     * still incomplete.
     */
    private function totalPacketSize(): ?int
    {
        $length = strlen($this->buffer);
        if ($length < 2) {
            return null;
        }

        $offset = 1;
        $multiplier = 1;
        $remainingLength = 0;

        do {
            if ($offset >= $length) {
                return null;
            }

            $byte = ord($this->buffer[$offset]);
            $remainingLength += ($byte & 0x7F) * $multiplier;
            $offset++;

            if ($multiplier > self::VARIABLE_BYTE_INT_MAX_MULTIPLIER) {
                throw new MalformedPacketException('Malformed remaining length');
            }

            $multiplier *= 128;
        } while (($byte & 0x80) !== 0);

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

        return $totalSize;
    }

    public function nextPacket(): string
    {
        $totalSize = $this->totalPacketSize();
        $length = strlen($this->buffer);

        if ($totalSize === null || $length < $totalSize) {
            throw new MalformedPacketException('No complete packet in buffer');
        }

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
