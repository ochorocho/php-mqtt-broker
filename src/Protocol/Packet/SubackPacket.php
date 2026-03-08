<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Protocol\Packet;

use PhpMqtt\Broker\Protocol\DataType;
use PhpMqtt\Broker\Protocol\Property\PropertyCodec;
use PhpMqtt\Broker\Protocol\Property\PropertyCollection;
use PhpMqtt\Broker\Protocol\ProtocolVersion;

final class SubackPacket implements PacketInterface
{
    /**
     * @param int[] $returnCodes QoS 0 (0x00), QoS 1 (0x01), QoS 2 (0x02), or Failure (0x80)
     */
    public function __construct(
        public readonly int $packetId,
        public readonly array $returnCodes,
        public readonly ProtocolVersion $protocolVersion = ProtocolVersion::V311,
        public readonly ?PropertyCollection $properties = null,
    ) {
    }

    public static function decode(string $data, ProtocolVersion $version = ProtocolVersion::V311): self
    {
        $offset = 0;
        $packetId = DataType::decodeTwoByteInteger($data, $offset);

        $properties = null;
        if ($version === ProtocolVersion::V50) {
            $properties = PropertyCodec::decode($data, $offset);
        }

        $returnCodes = [];
        $length = strlen($data);

        while ($offset < $length) {
            $returnCodes[] = DataType::decodeByte($data, $offset);
        }

        return new self(
            packetId: $packetId,
            returnCodes: $returnCodes,
            protocolVersion: $version,
            properties: $properties,
        );
    }

    public function encode(): string
    {
        $data = DataType::encodeTwoByteInteger($this->packetId);

        if ($this->protocolVersion === ProtocolVersion::V50) {
            $data .= $this->properties !== null ? PropertyCodec::encode($this->properties) : PropertyCodec::encodeEmpty();
        }

        foreach ($this->returnCodes as $code) {
            $data .= DataType::encodeByte($code);
        }

        return $data;
    }

    public function getType(): PacketType
    {
        return PacketType::SUBACK;
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return $this->protocolVersion;
    }
}
