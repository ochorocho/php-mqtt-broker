<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Message;

use PhpMqtt\Broker\Protocol\Packet\PublishPacket;

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

    public function totalBytes(): int
    {
        return $this->totalBytes;
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
        $topicLevels = explode('/', $topicName);
        $filterLevels = explode('/', $topicFilter);

        return $this->matchLevels($topicLevels, $filterLevels, 0, 0, $topicName);
    }

    /**
     * @param string[] $topicLevels
     * @param string[] $filterLevels
     */
    private function matchLevels(array $topicLevels, array $filterLevels, int $ti, int $fi, string $topicName): bool
    {
        $topicCount = count($topicLevels);
        $filterCount = count($filterLevels);

        while ($fi < $filterCount) {
            $filterLevel = $filterLevels[$fi];

            if ($filterLevel === '#') {
                // $ topics should not match # at root level
                if ($fi === 0 && isset($topicName[0]) && $topicName[0] === '$') {
                    return false;
                }
                return true;
            }

            if ($ti >= $topicCount) {
                return false;
            }

            if ($filterLevel === '+') {
                // $ topics should not match + at root level
                if ($fi === 0 && isset($topicName[0]) && $topicName[0] === '$') {
                    return false;
                }
                $ti++;
                $fi++;
                continue;
            }

            if ($filterLevel !== $topicLevels[$ti]) {
                return false;
            }

            $ti++;
            $fi++;
        }

        return $ti === $topicCount;
    }
}
