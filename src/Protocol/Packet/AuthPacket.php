<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Protocol\Packet;

use PhpMqtt\Broker\Protocol\DataType;
use PhpMqtt\Broker\Protocol\Property\PropertyCodec;
use PhpMqtt\Broker\Protocol\Property\PropertyCollection;
use PhpMqtt\Broker\Protocol\ProtocolVersion;

final class AuthPacket implements PacketInterface
{
    public function __construct(
        public readonly int $reasonCode = 0x00,
        public readonly ?PropertyCollection $properties = null,
    ) {
    }

    public static function decode(string $data): self
    {
        $offset = 0;
        $reasonCode = 0x00;
        $properties = null;

        if ($offset < strlen($data)) {
            $reasonCode = DataType::decodeByte($data, $offset);
            if ($offset < strlen($data)) {
                $properties = PropertyCodec::decode($data, $offset);
            }
        }

        return new self(reasonCode: $reasonCode, properties: $properties);
    }

    public function encode(): string
    {
        $data = DataType::encodeByte($this->reasonCode);
        $data .= $this->properties !== null ? PropertyCodec::encode($this->properties) : PropertyCodec::encodeEmpty();
        return $data;
    }

    public function getType(): PacketType
    {
        return PacketType::AUTH;
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return ProtocolVersion::V50;
    }
}
