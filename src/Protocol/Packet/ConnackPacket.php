<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Protocol\Packet;

use PhpMqtt\Broker\Protocol\DataType;
use PhpMqtt\Broker\Protocol\ProtocolVersion;
use PhpMqtt\Broker\Protocol\Property\PropertyCodec;
use PhpMqtt\Broker\Protocol\Property\PropertyCollection;

final class ConnackPacket implements PacketInterface
{
    public function __construct(
        public readonly bool $sessionPresent,
        public readonly int $returnCode,
        public readonly ProtocolVersion $protocolVersion = ProtocolVersion::V311,
        public readonly ?PropertyCollection $properties = null,
    ) {
    }

    public static function decode(string $data, ProtocolVersion $version = ProtocolVersion::V311): self
    {
        $offset = 0;
        $flags = DataType::decodeByte($data, $offset);
        $sessionPresent = ($flags & 0x01) !== 0;
        $returnCode = DataType::decodeByte($data, $offset);

        $properties = null;
        if ($version === ProtocolVersion::V50 && $offset < strlen($data)) {
            $properties = PropertyCodec::decode($data, $offset);
        }

        return new self(
            sessionPresent: $sessionPresent,
            returnCode: $returnCode,
            protocolVersion: $version,
            properties: $properties,
        );
    }

    public function encode(): string
    {
        $flags = $this->sessionPresent ? 0x01 : 0x00;
        $data = DataType::encodeByte($flags) . DataType::encodeByte($this->returnCode);
        if ($this->protocolVersion === ProtocolVersion::V50) {
            $data .= $this->properties !== null ? PropertyCodec::encode($this->properties) : PropertyCodec::encodeEmpty();
        }
        return $data;
    }

    public function getType(): PacketType
    {
        return PacketType::CONNACK;
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return $this->protocolVersion;
    }
}
