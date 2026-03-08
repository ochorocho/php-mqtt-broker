<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Protocol\Packet;

use PhpMqtt\Broker\Protocol\ProtocolVersion;

interface PacketInterface
{
    public function getType(): PacketType;

    public function getProtocolVersion(): ProtocolVersion;

    /**
     * Encode the variable header + payload (without fixed header).
     */
    public function encode(): string;
}
