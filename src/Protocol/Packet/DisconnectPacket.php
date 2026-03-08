<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Protocol\Packet;

use PhpMqtt\Broker\Protocol\DataType;
use PhpMqtt\Broker\Protocol\Property\PropertyCodec;
use PhpMqtt\Broker\Protocol\Property\PropertyCollection;
use PhpMqtt\Broker\Protocol\ProtocolVersion;

final class DisconnectPacket implements PacketInterface
{
    public function __construct(
        public readonly ProtocolVersion $protocolVersion = ProtocolVersion::V311,
        public readonly int $reasonCode = 0x00,
        public readonly ?PropertyCollection $properties = null,
    ) {
    }

    public static function decode(string $data = '', ProtocolVersion $version = ProtocolVersion::V311): self
    {
        $offset = 0;
        $reasonCode = 0x00;
        $properties = null;

        if ($version === ProtocolVersion::V50 && $offset < strlen($data)) {
            $reasonCode = DataType::decodeByte($data, $offset);
            if ($offset < strlen($data)) {
                $properties = PropertyCodec::decode($data, $offset);
            }
        }

        return new self(
            protocolVersion: $version,
            reasonCode: $reasonCode,
            properties: $properties,
        );
    }

    public function encode(): string
    {
        if ($this->protocolVersion === ProtocolVersion::V50) {
            $data = DataType::encodeByte($this->reasonCode);
            if ($this->properties !== null && !$this->properties->isEmpty()) {
                $data .= PropertyCodec::encode($this->properties);
            }
            return $data;
        }

        return '';
    }

    public function getType(): PacketType
    {
        return PacketType::DISCONNECT;
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return $this->protocolVersion;
    }
}
