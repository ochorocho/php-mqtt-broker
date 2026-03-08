<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Protocol\Packet;

use PhpMqtt\Broker\Protocol\DataType;
use PhpMqtt\Broker\Protocol\Property\PropertyCodec;
use PhpMqtt\Broker\Protocol\Property\PropertyCollection;
use PhpMqtt\Broker\Protocol\ProtocolVersion;

final class UnsubackPacket implements PacketInterface
{
    /**
     * @param int[] $reasonCodes
     */
    public function __construct(
        public readonly int $packetId,
        public readonly ProtocolVersion $protocolVersion = ProtocolVersion::V311,
        public readonly ?PropertyCollection $properties = null,
        public readonly array $reasonCodes = [],
    ) {
    }

    public static function decode(string $data, ProtocolVersion $version = ProtocolVersion::V311): self
    {
        $offset = 0;
        $packetId = DataType::decodeTwoByteInteger($data, $offset);

        $properties = null;
        $reasonCodes = [];

        if ($version === ProtocolVersion::V50) {
            $properties = PropertyCodec::decode($data, $offset);

            while ($offset < strlen($data)) {
                $reasonCodes[] = DataType::decodeByte($data, $offset);
            }
        }

        return new self(
            packetId: $packetId,
            protocolVersion: $version,
            properties: $properties,
            reasonCodes: $reasonCodes,
        );
    }

    public function encode(): string
    {
        $data = DataType::encodeTwoByteInteger($this->packetId);

        if ($this->protocolVersion === ProtocolVersion::V50) {
            $data .= $this->properties !== null ? PropertyCodec::encode($this->properties) : PropertyCodec::encodeEmpty();

            foreach ($this->reasonCodes as $reasonCode) {
                $data .= DataType::encodeByte($reasonCode);
            }
        }

        return $data;
    }

    public function getType(): PacketType
    {
        return PacketType::UNSUBACK;
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return $this->protocolVersion;
    }
}
