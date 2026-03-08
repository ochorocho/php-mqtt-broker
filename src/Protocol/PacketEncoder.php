<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Protocol;

use PhpMqtt\Broker\Protocol\Packet\PacketInterface;
use PhpMqtt\Broker\Protocol\Packet\PublishPacket;

final class PacketEncoder
{
    public function encode(PacketInterface $packet): string
    {
        $variableHeaderAndPayload = $packet->encode();
        $remainingLength = strlen($variableHeaderAndPayload);

        $type = $packet->getType();

        // Build fixed header flags
        if ($packet instanceof PublishPacket) {
            $flags = $packet->getFixedHeaderFlags();
        } else {
            $flags = $type->expectedFlags() ?? 0x00;
        }

        /** @var int<0, 255> $firstByte */
        $firstByte = ($type->value << 4) | ($flags & 0x0F);

        return chr($firstByte)
            . DataType::encodeVariableByteInteger($remainingLength)
            . $variableHeaderAndPayload;
    }
}
