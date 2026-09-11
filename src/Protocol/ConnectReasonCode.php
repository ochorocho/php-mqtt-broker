<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Protocol;

/**
 * CONNACK codes.
 *
 * 3.1.1 calls these return codes and defines only 0x00-0x05; 5.0 calls them reason
 * codes and uses a wider set. Both are listed here because CONNACK is the one packet
 * where the broker has to pick per version.
 */
enum ConnectReasonCode: int
{
    case Success = 0x00;

    // MQTT 3.1.1 (MQTT-3.2.2.3)
    case UnacceptableProtocolVersion = 0x01;
    case IdentifierRejected = 0x02;
    case BadUsernameOrPassword = 0x04;
    case NotAuthorizedV311 = 0x05;

    // MQTT 5.0 (MQTT-3.2.2.2)
    case UnsupportedProtocolVersion = 0x84;
    case ClientIdentifierNotValid = 0x85;
    case BadUserNameOrPassword = 0x86;
    case NotAuthorized = 0x87;
}
