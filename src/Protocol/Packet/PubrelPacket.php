<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Protocol\Packet;

final class PubrelPacket extends AcknowledgementPacket
{
    public function getType(): PacketType
    {
        return PacketType::PUBREL;
    }
}
