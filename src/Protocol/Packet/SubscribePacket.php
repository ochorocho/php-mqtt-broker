<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Protocol\Packet;

use PhpMqtt\Broker\Exception\ProtocolViolationException;
use PhpMqtt\Broker\Protocol\DataType;
use PhpMqtt\Broker\Protocol\Property\PropertyCodec;
use PhpMqtt\Broker\Protocol\Property\PropertyCollection;
use PhpMqtt\Broker\Protocol\ProtocolVersion;

final class SubscribePacket implements PacketInterface
{
    /**
     * @param array<array{topic: string, qos: int, noLocal?: bool, retainAsPublished?: bool, retainHandling?: int}> $subscriptions
     */
    public function __construct(
        public readonly int $packetId,
        public readonly array $subscriptions,
        public readonly ProtocolVersion $protocolVersion = ProtocolVersion::V311,
        public readonly ?PropertyCollection $properties = null,
    ) {
        if ($subscriptions === []) {
            throw new ProtocolViolationException('SUBSCRIBE must contain at least one topic filter');
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

        $subscriptions = [];
        $length = strlen($data);

        while ($offset < $length) {
            $topic = DataType::decodeUtf8String($data, $offset);

            if ($version === ProtocolVersion::V50) {
                $optionsByte = DataType::decodeByte($data, $offset);
                $qos = $optionsByte & 0x03;
                $noLocal = ($optionsByte & 0x04) !== 0;
                $retainAsPublished = ($optionsByte & 0x08) !== 0;
                $retainHandling = ($optionsByte >> 4) & 0x03;

                if ($qos > 2) {
                    throw new ProtocolViolationException(
                        sprintf('Invalid QoS value %d in SUBSCRIBE', $qos)
                    );
                }

                $subscriptions[] = [
                    'topic' => $topic,
                    'qos' => $qos,
                    'noLocal' => $noLocal,
                    'retainAsPublished' => $retainAsPublished,
                    'retainHandling' => $retainHandling,
                ];
            } else {
                $qos = DataType::decodeByte($data, $offset);

                if ($qos > 2) {
                    throw new ProtocolViolationException(
                        sprintf('Invalid QoS value %d in SUBSCRIBE', $qos)
                    );
                }

                $subscriptions[] = [
                    'topic' => $topic,
                    'qos' => $qos,
                    'noLocal' => false,
                    'retainAsPublished' => false,
                    'retainHandling' => 0,
                ];
            }
        }

        return new self(
            packetId: $packetId,
            subscriptions: $subscriptions,
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

        foreach ($this->subscriptions as $sub) {
            $data .= DataType::encodeUtf8String($sub['topic']);

            if ($this->protocolVersion === ProtocolVersion::V50) {
                $optionsByte = ($sub['qos'] & 0x03)
                    | (($sub['noLocal'] ?? false) ? 0x04 : 0)
                    | (($sub['retainAsPublished'] ?? false) ? 0x08 : 0)
                    | ((($sub['retainHandling'] ?? 0) & 0x03) << 4);
                $data .= DataType::encodeByte($optionsByte);
            } else {
                $data .= DataType::encodeByte($sub['qos']);
            }
        }

        return $data;
    }

    public function getType(): PacketType
    {
        return PacketType::SUBSCRIBE;
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return $this->protocolVersion;
    }
}
