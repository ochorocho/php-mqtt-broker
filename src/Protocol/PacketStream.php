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
     * Where the next packet starts. Packets are read by advancing this rather than
     * cutting each one off the front, which copied the rest of the buffer once per
     * packet: quadratic in a read full of tiny packets.
     */
    private int $offset = 0;

    /**
     * @param int $maxPacketSize Cap on a single packet and on the bytes left over after
     *                           complete packets are taken. Without it a client can
     *                           declare a remaining length of up to 268 MB, or never
     *                           complete a packet, and the buffer grows until the
     *                           process runs out of memory.
     */
    public function __construct(
        private int $maxPacketSize = self::DEFAULT_MAX_PACKET_SIZE,
    ) {
    }

    /**
     * Change the cap, e.g. once a peer has authenticated and may send full-size packets.
     */
    public function setMaxPacketSize(int $maxPacketSize): void
    {
        $this->maxPacketSize = $maxPacketSize;
    }

    /**
     * The cap applies to what is already buffered, not to buffer plus new data: a read
     * routinely carries the tail of one full-size packet and the start of the next.
     * Callers drain complete packets after every append, so the buffer stays within
     * the cap plus one read.
     */
    public function append(string $data): void
    {
        if ($this->offset > 0) {
            $this->buffer = (string) substr($this->buffer, $this->offset);
            $this->offset = 0;
        }

        if (strlen($this->buffer) > $this->maxPacketSize) {
            throw new MalformedPacketException(sprintf(
                'Receive buffer exceeds maximum packet size of %d bytes',
                $this->maxPacketSize,
            ));
        }

        $this->buffer .= $data;
    }

    /**
     * The control packet type of the packet at the head of the buffer, or null when
     * nothing is buffered. Lets a caller refuse a packet before decoding any of it.
     */
    public function peekPacketType(): ?int
    {
        if ($this->offset >= strlen($this->buffer)) {
            return null;
        }

        return ord($this->buffer[$this->offset]) >> 4;
    }

    public function hasCompletePacket(): bool
    {
        $size = $this->totalPacketSize();
        if ($size === null) {
            return false;
        }

        return $this->getBufferLength() >= $size;
    }

    /**
     * Size of the packet at the head of the buffer, or null while the fixed header is
     * still incomplete.
     */
    private function totalPacketSize(): ?int
    {
        $length = strlen($this->buffer);
        if ($length - $this->offset < 2) {
            return null;
        }

        $position = $this->offset + 1;
        $multiplier = 1;
        $remainingLength = 0;

        do {
            if ($position >= $length) {
                return null;
            }

            $byte = ord($this->buffer[$position]);
            $remainingLength += ($byte & 0x7F) * $multiplier;
            $position++;

            if ($multiplier > self::VARIABLE_BYTE_INT_MAX_MULTIPLIER) {
                throw new MalformedPacketException('Malformed remaining length');
            }

            $multiplier *= 128;
        } while (($byte & 0x80) !== 0);

        $totalSize = ($position - $this->offset) + $remainingLength;

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

        if ($totalSize === null || $this->getBufferLength() < $totalSize) {
            throw new MalformedPacketException('No complete packet in buffer');
        }

        $packet = substr($this->buffer, $this->offset, $totalSize);
        $this->offset += $totalSize;

        if ($this->offset >= strlen($this->buffer)) {
            $this->buffer = '';
            $this->offset = 0;
        }

        return $packet;
    }

    public function getBufferLength(): int
    {
        return strlen($this->buffer) - $this->offset;
    }

    public function clear(): void
    {
        $this->buffer = '';
        $this->offset = 0;
    }
}
