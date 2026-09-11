<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Protocol;

/**
 * PUBACK and PUBREC reason codes (MQTT 5.0 only).
 *
 * 3.1.1 has no way to refuse a publish, so a denied message is acknowledged with
 * Success there and dropped.
 */
enum PubackReasonCode: int
{
    case Success = 0x00;
    case NoMatchingSubscribers = 0x10;
    case UnspecifiedError = 0x80;
    case NotAuthorized = 0x87;
}
