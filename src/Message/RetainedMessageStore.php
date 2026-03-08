<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Message;

use PhpMqtt\Broker\Protocol\Packet\PublishPacket;

final class RetainedMessageStore
{
    /** @var array<string, PublishPacket> topic => retained message */
    private array $messages = [];

    public function store(string $topic, PublishPacket $packet): void
    {
        $this->messages[$topic] = $packet;
    }

    public function remove(string $topic): void
    {
        unset($this->messages[$topic]);
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
