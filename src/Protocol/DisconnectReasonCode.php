<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Protocol;

/**
 * Server-sent DISCONNECT reason codes (MQTT 5.0 only).
 */
enum DisconnectReasonCode: int
{
    case NormalDisconnection = 0x00;
    case DisconnectWithWillMessage = 0x04;
    case ReceiveMaximumExceeded = 0x93;
    case TopicAliasInvalid = 0x94;
}
