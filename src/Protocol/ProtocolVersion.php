<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Protocol;

enum ProtocolVersion: int
{
    case V311 = 4;
    case V50 = 5;
}
