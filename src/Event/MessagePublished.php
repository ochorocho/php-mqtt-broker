<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Event;

final class MessagePublished
{
    public function __construct(
        public readonly string $topic,
        public readonly string $payload,
        public readonly int $qos,
        public readonly bool $retain,
        public readonly string $clientId,
        public readonly \DateTimeImmutable $timestamp = new \DateTimeImmutable(),
    ) {}
}