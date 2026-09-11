<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Protocol\Packet;

final class PubrecPacket extends AcknowledgementPacket
{
    public function getType(): PacketType
    {
        return PacketType::PUBREC;
    }
}
