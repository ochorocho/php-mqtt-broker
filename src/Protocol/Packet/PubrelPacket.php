<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Protocol\Packet;

use PhpMqtt\Broker\Protocol\DataType;
use PhpMqtt\Broker\Protocol\ProtocolVersion;
use PhpMqtt\Broker\Protocol\Property\PropertyCodec;
use PhpMqtt\Broker\Protocol\Property\PropertyCollection;

final class PubrelPacket implements PacketInterface
{
    public function __construct(
        public readonly int $packetId,
        public readonly ProtocolVersion $protocolVersion = ProtocolVersion::V311,
        public readonly int $reasonCode = 0x00,
        public readonly ?PropertyCollection $properties = null,
    ) {
    }

    public static function decode(string $data, ProtocolVersion $version = ProtocolVersion::V311): self
    {
        $offset = 0;
        $packetId = DataType::decodeTwoByteInteger($data, $offset);

        $reasonCode = 0x00;
        $properties = null;
        if ($version === ProtocolVersion::V50 && $offset < strlen($data)) {
            $reasonCode = DataType::decodeByte($data, $offset);
            if ($offset < strlen($data)) {
                $properties = PropertyCodec::decode($data, $offset);
            }
        }

        return new self(
            packetId: $packetId,
            protocolVersion: $version,
            reasonCode: $reasonCode,
            properties: $properties,
        );
    }

    public function encode(): string
    {
        $data = DataType::encodeTwoByteInteger($this->packetId);
        if ($this->protocolVersion === ProtocolVersion::V50) {
            if ($this->reasonCode !== 0x00 || ($this->properties !== null && !$this->properties->isEmpty())) {
                $data .= DataType::encodeByte($this->reasonCode);
                if ($this->properties !== null && !$this->properties->isEmpty()) {
                    $data .= PropertyCodec::encode($this->properties);
                }
            }
        }
        return $data;
    }

    public function getType(): PacketType
    {
        return PacketType::PUBREL;
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return $this->protocolVersion;
    }
}
