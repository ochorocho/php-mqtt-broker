<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Protocol\Packet;

use PhpMqtt\Broker\Exception\MalformedPacketException;
use PhpMqtt\Broker\Exception\ProtocolViolationException;
use PhpMqtt\Broker\Protocol\DataType;
use PhpMqtt\Broker\Protocol\ProtocolVersion;
use PhpMqtt\Broker\Protocol\Property\PropertyCodec;
use PhpMqtt\Broker\Protocol\Property\PropertyCollection;

final class ConnectPacket implements PacketInterface
{
    public function __construct(
        public readonly string $protocolName,
        public readonly int $protocolLevel,
        public readonly bool $cleanSession,
        public readonly int $keepAlive,
        public readonly string $clientId,
        public readonly bool $hasWill = false,
        public readonly int $willQoS = 0,
        public readonly bool $willRetain = false,
        public readonly ?string $willTopic = null,
        public readonly ?string $willPayload = null,
        public readonly bool $hasUsername = false,
        public readonly ?string $username = null,
        public readonly bool $hasPassword = false,
        public readonly ?string $password = null,
        public readonly ?PropertyCollection $properties = null,
        public readonly ?PropertyCollection $willProperties = null,
    ) {
    }

    public static function decode(string $data): self
    {
        $offset = 0;

        // Variable header
        $protocolName = DataType::decodeUtf8String($data, $offset);
        $protocolLevel = DataType::decodeByte($data, $offset);
        $connectFlags = DataType::decodeByte($data, $offset);
        $keepAlive = DataType::decodeTwoByteInteger($data, $offset);

        // Parse connect flags
        $reserved = $connectFlags & 0x01;
        if ($reserved !== 0) {
            throw new ProtocolViolationException('CONNECT reserved flag must be 0');
        }

        $cleanSession = ($connectFlags & 0x02) !== 0;
        $hasWill = ($connectFlags & 0x04) !== 0;
        $willQoS = ($connectFlags >> 3) & 0x03;
        $willRetain = ($connectFlags & 0x20) !== 0;
        $hasPassword = ($connectFlags & 0x40) !== 0;
        $hasUsername = ($connectFlags & 0x80) !== 0;

        if (!$hasWill) {
            if ($willQoS !== 0) {
                throw new ProtocolViolationException('Will QoS must be 0 when Will Flag is 0');
            }
            if ($willRetain) {
                throw new ProtocolViolationException('Will Retain must be 0 when Will Flag is 0');
            }
        }

        if ($willQoS > 2) {
            throw new ProtocolViolationException('Will QoS must be 0, 1, or 2');
        }

        // MQTT 5.0 properties
        $properties = null;
        $isV5 = ($protocolLevel === ProtocolVersion::V50->value);
        if ($isV5) {
            $properties = PropertyCodec::decode($data, $offset);
        }

        // Payload
        $clientId = DataType::decodeUtf8String($data, $offset);

        $willProperties = null;
        $willTopic = null;
        $willPayload = null;
        if ($hasWill) {
            if ($isV5) {
                $willProperties = PropertyCodec::decode($data, $offset);
            }
            $willTopic = DataType::decodeUtf8String($data, $offset);
            $willPayload = DataType::decodeBinaryData($data, $offset);
        }

        $username = null;
        if ($hasUsername) {
            $username = DataType::decodeUtf8String($data, $offset);
        }

        $password = null;
        if ($hasPassword) {
            $password = DataType::decodeBinaryData($data, $offset);
        }

        return new self(
            protocolName: $protocolName,
            protocolLevel: $protocolLevel,
            cleanSession: $cleanSession,
            keepAlive: $keepAlive,
            clientId: $clientId,
            hasWill: $hasWill,
            willQoS: $willQoS,
            willRetain: $willRetain,
            willTopic: $willTopic,
            willPayload: $willPayload,
            hasUsername: $hasUsername,
            username: $username,
            hasPassword: $hasPassword,
            password: $password,
            properties: $properties,
            willProperties: $willProperties,
        );
    }

    public function encode(): string
    {
        $data = '';

        // Variable header
        $data .= DataType::encodeUtf8String($this->protocolName);
        $data .= DataType::encodeByte($this->protocolLevel);

        // Connect flags
        $flags = 0;
        if ($this->cleanSession) {
            $flags |= 0x02;
        }
        if ($this->hasWill) {
            $flags |= 0x04;
            $flags |= ($this->willQoS & 0x03) << 3;
            if ($this->willRetain) {
                $flags |= 0x20;
            }
        }
        if ($this->hasPassword) {
            $flags |= 0x40;
        }
        if ($this->hasUsername) {
            $flags |= 0x80;
        }
        $data .= DataType::encodeByte($flags);
        $data .= DataType::encodeTwoByteInteger($this->keepAlive);

        // MQTT 5.0 properties
        if ($this->protocolLevel === ProtocolVersion::V50->value) {
            $data .= $this->properties !== null ? PropertyCodec::encode($this->properties) : PropertyCodec::encodeEmpty();
        }

        // Payload
        $data .= DataType::encodeUtf8String($this->clientId);

        if ($this->hasWill) {
            if ($this->protocolLevel === ProtocolVersion::V50->value) {
                $data .= $this->willProperties !== null ? PropertyCodec::encode($this->willProperties) : PropertyCodec::encodeEmpty();
            }
            $data .= DataType::encodeUtf8String($this->willTopic ?? '');
            $data .= DataType::encodeBinaryData($this->willPayload ?? '');
        }

        if ($this->hasUsername) {
            $data .= DataType::encodeUtf8String($this->username ?? '');
        }

        if ($this->hasPassword) {
            $data .= DataType::encodeBinaryData($this->password ?? '');
        }

        return $data;
    }

    public function getType(): PacketType
    {
        return PacketType::CONNECT;
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return ProtocolVersion::tryFrom($this->protocolLevel) ?? ProtocolVersion::V311;
    }
}
