<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Protocol\Packet;

use PhpMqtt\Broker\Protocol\DataType;
use PhpMqtt\Broker\Protocol\ProtocolVersion;
use PhpMqtt\Broker\Protocol\PubackReasonCode;
use PhpMqtt\Broker\Protocol\Property\PropertyCodec;
use PhpMqtt\Broker\Protocol\Property\PropertyCollection;

/**
 * Shared wire format for PUBACK, PUBREC, PUBREL and PUBCOMP.
 *
 * All four are a packet id plus, in 5.0 only, an optional reason code and property
 * block. Subclasses differ solely in their packet type, which is what lets decode()
 * construct them through new static().
 *
 * @phpstan-consistent-constructor
 */
abstract class AcknowledgementPacket implements PacketInterface
{
    public function __construct(
        public readonly int $packetId,
        public readonly ProtocolVersion $protocolVersion = ProtocolVersion::V311,
        public readonly int $reasonCode = 0x00,
        public readonly ?PropertyCollection $properties = null,
    ) {
    }

    public static function decode(string $data, ProtocolVersion $version = ProtocolVersion::V311): static
    {
        $offset = 0;
        $packetId = DataType::decodeTwoByteInteger($data, $offset);

        $reasonCode = PubackReasonCode::Success->value;
        $properties = null;
        if ($version === ProtocolVersion::V50 && $offset < strlen($data)) {
            $reasonCode = DataType::decodeByte($data, $offset);
            if ($offset < strlen($data)) {
                $properties = PropertyCodec::decode($data, $offset);
            }
        }

        return new static(
            packetId: $packetId,
            protocolVersion: $version,
            reasonCode: $reasonCode,
            properties: $properties,
        );
    }

    public function encode(): string
    {
        $data = DataType::encodeTwoByteInteger($this->packetId);

        if ($this->protocolVersion !== ProtocolVersion::V50) {
            return $data;
        }

        // Both trailing fields may be omitted when they carry nothing (MQTT-3.4.2-1),
        // and the property block may only be omitted along with the reason code.
        $hasProperties = $this->properties !== null && !$this->properties->isEmpty();
        if ($this->reasonCode === PubackReasonCode::Success->value && !$hasProperties) {
            return $data;
        }

        $data .= DataType::encodeByte($this->reasonCode);
        if ($hasProperties) {
            $data .= PropertyCodec::encode($this->properties);
        }

        return $data;
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return $this->protocolVersion;
    }
}
