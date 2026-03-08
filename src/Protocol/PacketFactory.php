<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Protocol;

use PhpMqtt\Broker\Exception\MalformedPacketException;
use PhpMqtt\Broker\Exception\ProtocolViolationException;
use PhpMqtt\Broker\Protocol\Packet\ConnackPacket;
use PhpMqtt\Broker\Protocol\Packet\ConnectPacket;
use PhpMqtt\Broker\Protocol\Packet\DisconnectPacket;
use PhpMqtt\Broker\Protocol\Packet\PacketInterface;
use PhpMqtt\Broker\Protocol\Packet\PacketType;
use PhpMqtt\Broker\Protocol\Packet\PingreqPacket;
use PhpMqtt\Broker\Protocol\Packet\PingrespPacket;
use PhpMqtt\Broker\Protocol\Packet\PubackPacket;
use PhpMqtt\Broker\Protocol\Packet\PubcompPacket;
use PhpMqtt\Broker\Protocol\Packet\PublishPacket;
use PhpMqtt\Broker\Protocol\Packet\PubrecPacket;
use PhpMqtt\Broker\Protocol\Packet\PubrelPacket;
use PhpMqtt\Broker\Protocol\Packet\SubackPacket;
use PhpMqtt\Broker\Protocol\Packet\SubscribePacket;
use PhpMqtt\Broker\Protocol\Packet\UnsubackPacket;
use PhpMqtt\Broker\Protocol\Packet\AuthPacket;
use PhpMqtt\Broker\Protocol\Packet\UnsubscribePacket;

final class PacketFactory
{
    public function decode(string $rawPacket, ?ProtocolVersion $version = null): PacketInterface
    {
        if (strlen($rawPacket) < 2) {
            throw new MalformedPacketException('Packet too short');
        }

        // Parse fixed header
        $firstByte = ord($rawPacket[0]);
        $typeValue = ($firstByte >> 4) & 0x0F;
        $flags = $firstByte & 0x0F;

        $packetType = PacketType::tryFrom($typeValue);
        if ($packetType === null) {
            throw new MalformedPacketException(sprintf('Unknown packet type %d', $typeValue));
        }

        // Validate fixed header flags
        $expectedFlags = $packetType->expectedFlags();
        if ($expectedFlags !== null && $flags !== $expectedFlags) {
            throw new ProtocolViolationException(
                sprintf('Invalid flags 0x%02X for %s (expected 0x%02X)', $flags, $packetType->name, $expectedFlags)
            );
        }

        // Parse remaining length
        $offset = 1;
        $remainingLength = DataType::decodeVariableByteInteger($rawPacket, $offset);

        // Extract variable header + payload
        $data = substr($rawPacket, $offset, $remainingLength);

        $v = $version ?? ProtocolVersion::V311;

        return match ($packetType) {
            PacketType::CONNECT => ConnectPacket::decode($data),
            PacketType::CONNACK => ConnackPacket::decode($data, $v),
            PacketType::PUBLISH => PublishPacket::decode($data, $flags, $v),
            PacketType::PUBACK => PubackPacket::decode($data, $v),
            PacketType::PUBREC => PubrecPacket::decode($data, $v),
            PacketType::PUBREL => PubrelPacket::decode($data, $v),
            PacketType::PUBCOMP => PubcompPacket::decode($data, $v),
            PacketType::SUBSCRIBE => SubscribePacket::decode($data, $v),
            PacketType::SUBACK => SubackPacket::decode($data, $v),
            PacketType::UNSUBSCRIBE => UnsubscribePacket::decode($data, $v),
            PacketType::UNSUBACK => UnsubackPacket::decode($data, $v),
            PacketType::PINGREQ => PingreqPacket::decode($v),
            PacketType::PINGRESP => PingrespPacket::decode($v),
            PacketType::DISCONNECT => DisconnectPacket::decode($data, $v),
            PacketType::AUTH => $v === ProtocolVersion::V50
                ? AuthPacket::decode($data)
                : throw new ProtocolViolationException('AUTH packet not supported in MQTT 3.1.1'),
        };
    }
}
