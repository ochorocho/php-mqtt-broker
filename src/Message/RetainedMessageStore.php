<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Message;

use PhpMqtt\Broker\Protocol\Packet\PublishPacket;
use PhpMqtt\Broker\Protocol\TopicFilter;

final class RetainedMessageStore
{
    /** @var array<string, PublishPacket> topic => retained message */
    private array $messages = [];

    private int $totalBytes = 0;

    /**
     * Retained messages persist until overwritten, so without bounds any client can
     * pin unlimited attacker-chosen data in memory for the life of the process.
     */
    public function __construct(
        private readonly int $maxMessages = 10000,
        private readonly int $maxBytes = 67108864,
    ) {
    }

    /**
     * @return bool False when the message was rejected because a limit is reached.
     */
    public function store(string $topic, PublishPacket $packet): bool
    {
        $size = strlen($packet->payload);
        $existing = $this->messages[$topic] ?? null;
        $delta = $size - ($existing !== null ? strlen($existing->payload) : 0);

        if ($existing === null && count($this->messages) >= $this->maxMessages) {
            return false;
        }

        if ($this->totalBytes + $delta > $this->maxBytes) {
            return false;
        }

        $this->messages[$topic] = $packet;
        $this->totalBytes += $delta;

        return true;
    }

    public function remove(string $topic): void
    {
        $existing = $this->messages[$topic] ?? null;
        if ($existing !== null) {
            $this->totalBytes -= strlen($existing->payload);
            unset($this->messages[$topic]);
        }
    }

    public function count(): int
    {
        return count($this->messages);
    }

    /**
     * @return PublishPacket[]
     */
    public function getMatching(string $topicFilter): array
    {
        $results = [];

        foreach ($this->messages as $topic => $packet) {
            if ($this->topicMatchesFilter($topic, $topicFilter)) {
                $results[] = $packet;
            }
        }

        return $results;
    }

    private function topicMatchesFilter(string $topicName, string $topicFilter): bool
    {
        // Matching lives in one place. This used to be a second, independently written
        // implementation, so retained delivery and live routing could disagree about
        // which subscribers a topic reaches.
        return TopicFilter::matches($topicName, $topicFilter);
    }
}
