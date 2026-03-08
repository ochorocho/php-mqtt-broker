<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Protocol\Packet;

use PhpMqtt\Broker\Exception\ProtocolViolationException;
use PhpMqtt\Broker\Protocol\DataType;
use PhpMqtt\Broker\Protocol\ProtocolVersion;
use PhpMqtt\Broker\Protocol\Property\PropertyCodec;
use PhpMqtt\Broker\Protocol\Property\PropertyCollection;

final class PublishPacket implements PacketInterface
{
    public function __construct(
        public readonly string $topicName,
        public readonly string $payload,
        public readonly int $qos = 0,
        public readonly bool $dup = false,
        public readonly bool $retain = false,
        public readonly ?int $packetId = null,
        public readonly ProtocolVersion $protocolVersion = ProtocolVersion::V311,
        public readonly ?PropertyCollection $properties = null,
    ) {
        if ($qos > 0 && $packetId === null) {
            throw new ProtocolViolationException('Packet ID required for QoS > 0');
        }
        if ($qos === 0 && $packetId !== null) {
            throw new ProtocolViolationException('Packet ID must not be set for QoS 0');
        }
    }

    public static function decode(string $data, int $flags, ProtocolVersion $version = ProtocolVersion::V311): self
    {
        $dup = ($flags & 0x08) !== 0;
        $qos = ($flags >> 1) & 0x03;
        $retain = ($flags & 0x01) !== 0;

        if ($qos === 3) {
            throw new ProtocolViolationException('QoS value 3 is invalid');
        }

        $offset = 0;
        $topicName = DataType::decodeUtf8String($data, $offset);

        $packetId = null;
        if ($qos > 0) {
            $packetId = DataType::decodeTwoByteInteger($data, $offset);
        }

        $properties = null;
        if ($version === ProtocolVersion::V50) {
            $properties = PropertyCodec::decode($data, $offset);
        }

        $payload = substr($data, $offset);

        return new self(
            topicName: $topicName,
            payload: $payload,
            qos: $qos,
            dup: $dup,
            retain: $retain,
            packetId: $packetId,
            protocolVersion: $version,
            properties: $properties,
        );
    }

    public function encode(): string
    {
        $data = DataType::encodeUtf8String($this->topicName);

        if ($this->qos > 0 && $this->packetId !== null) {
            $data .= DataType::encodeTwoByteInteger($this->packetId);
        }

        if ($this->protocolVersion === ProtocolVersion::V50) {
            $data .= $this->properties !== null ? PropertyCodec::encode($this->properties) : PropertyCodec::encodeEmpty();
        }

        $data .= $this->payload;

        return $data;
    }

    public function getFixedHeaderFlags(): int
    {
        $flags = 0;
        if ($this->dup) {
            $flags |= 0x08;
        }
        $flags |= ($this->qos & 0x03) << 1;
        if ($this->retain) {
            $flags |= 0x01;
        }
        return $flags;
    }

    public function getType(): PacketType
    {
        return PacketType::PUBLISH;
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return $this->protocolVersion;
    }
}
