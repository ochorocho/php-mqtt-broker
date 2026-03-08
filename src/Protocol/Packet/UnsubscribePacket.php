<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Protocol\Packet;

use PhpMqtt\Broker\Exception\ProtocolViolationException;
use PhpMqtt\Broker\Protocol\DataType;
use PhpMqtt\Broker\Protocol\Property\PropertyCodec;
use PhpMqtt\Broker\Protocol\Property\PropertyCollection;
use PhpMqtt\Broker\Protocol\ProtocolVersion;

final class UnsubscribePacket implements PacketInterface
{
    /**
     * @param string[] $topicFilters
     */
    public function __construct(
        public readonly int $packetId,
        public readonly array $topicFilters,
        public readonly ProtocolVersion $protocolVersion = ProtocolVersion::V311,
        public readonly ?PropertyCollection $properties = null,
    ) {
        if ($topicFilters === []) {
            throw new ProtocolViolationException('UNSUBSCRIBE must contain at least one topic filter');
        }
    }

    public static function decode(string $data, ProtocolVersion $version = ProtocolVersion::V311): self
    {
        $offset = 0;
        $packetId = DataType::decodeTwoByteInteger($data, $offset);

        $properties = null;
        if ($version === ProtocolVersion::V50) {
            $properties = PropertyCodec::decode($data, $offset);
        }

        $filters = [];
        $length = strlen($data);

        while ($offset < $length) {
            $filters[] = DataType::decodeUtf8String($data, $offset);
        }

        return new self(
            packetId: $packetId,
            topicFilters: $filters,
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

        foreach ($this->topicFilters as $filter) {
            $data .= DataType::encodeUtf8String($filter);
        }

        return $data;
    }

    public function getType(): PacketType
    {
        return PacketType::UNSUBSCRIBE;
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return $this->protocolVersion;
    }
}
