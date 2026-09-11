<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Protocol;

/**
 * SUBACK and UNSUBACK codes.
 *
 * A granted subscription answers with the QoS it was granted, so 0x00-0x02 double as
 * both "success" and the granted level.
 */
enum SubackReasonCode: int
{
    case GrantedQos0 = 0x00;
    case GrantedQos1 = 0x01;
    case GrantedQos2 = 0x02;
    case NoSubscriptionExisted = 0x11;
    case UnspecifiedError = 0x80;
    case NotAuthorized = 0x87;
}
