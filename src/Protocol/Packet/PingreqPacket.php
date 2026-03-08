<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Protocol\Packet;

use PhpMqtt\Broker\Protocol\ProtocolVersion;

final class PingreqPacket implements PacketInterface
{
    public function __construct(
        public readonly ProtocolVersion $protocolVersion = ProtocolVersion::V311,
    ) {
    }

    public static function decode(ProtocolVersion $version = ProtocolVersion::V311): self
    {
        return new self(protocolVersion: $version);
    }

    public function encode(): string
    {
        return '';
    }

    public function getType(): PacketType
    {
        return PacketType::PINGREQ;
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return $this->protocolVersion;
    }
}
