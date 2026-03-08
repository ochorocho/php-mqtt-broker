<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Protocol\Packet;

enum PacketType: int
{
    case CONNECT     = 1;
    case CONNACK     = 2;
    case PUBLISH     = 3;
    case PUBACK      = 4;
    case PUBREC      = 5;
    case PUBREL      = 6;
    case PUBCOMP     = 7;
    case SUBSCRIBE   = 8;
    case SUBACK      = 9;
    case UNSUBSCRIBE = 10;
    case UNSUBACK    = 11;
    case PINGREQ     = 12;
    case PINGRESP    = 13;
    case DISCONNECT  = 14;
    case AUTH        = 15;

    /**
     * Expected fixed header flags for each packet type.
     * Returns null for PUBLISH since its flags are variable.
     */
    public function expectedFlags(): ?int
    {
        return match ($this) {
            self::PUBLISH => null,
            self::PUBREL, self::SUBSCRIBE, self::UNSUBSCRIBE => 0x02,
            default => 0x00,
        };
    }
}
