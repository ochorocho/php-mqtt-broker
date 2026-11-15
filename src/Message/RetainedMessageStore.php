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

    /** Whether anything changed since the store was last written out. */
    private bool $dirty = false;

    /**
     * The retained topics as a tree of levels, so a lookup walks only the branches a
     * filter can reach. Scanning every topic per filter made one small SUBSCRIBE of
     * 128 filters cost a quarter of a second against 10k retained messages.
     */
    private readonly TopicNode $index;

    /**
     * Retained messages persist until overwritten, so without bounds any client can
     * pin unlimited attacker-chosen data in memory for the life of the process.
     */
    public function __construct(
        private readonly int $maxMessages = 10000,
        private readonly int $maxBytes = 67108864,
    ) {
        $this->index = new TopicNode();
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

        if ($existing === null) {
            $this->indexAdd($topic);
        }

        $this->messages[$topic] = $packet;
        $this->totalBytes += $delta;
        $this->dirty = true;

        return true;
    }

    public function remove(string $topic): void
    {
        $existing = $this->messages[$topic] ?? null;
        if ($existing !== null) {
            $this->totalBytes -= strlen($existing->payload);
            unset($this->messages[$topic]);
            self::indexRemove($this->index, explode('/', $topic), 0);
            $this->dirty = true;
        }
    }

    /**
     * Every retained message, keyed by topic. For writing the store out.
     *
     * @return array<string, PublishPacket>
     */
    public function all(): array
    {
        return $this->messages;
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    /** Called once the store has been written out, or filled from a file. */
    public function markClean(): void
    {
        $this->dirty = false;
    }

    public function count(): int
    {
        return count($this->messages);
    }

    /**
     * The retained messages a topic filter matches, by the rules of TopicFilter::matches.
     *
     * @return list<PublishPacket>
     */
    public function getMatching(string $topicFilter): array
    {
        $topics = [];
        self::collect($this->index, explode('/', TopicFilter::stripSharedPrefix($topicFilter)), 0, $topics);

        return array_map(fn(string $topic): PublishPacket => $this->messages[$topic], $topics);
    }

    private function indexAdd(string $topic): void
    {
        $node = $this->index;
        foreach (explode('/', $topic) as $level) {
            $node = $node->children[$level] ??= new TopicNode();
        }
        $node->topic = $topic;
    }

    /**
     * Clear a topic and prune the nodes it leaves empty.
     *
     * @param list<string> $levels
     * @return bool Whether this node is now empty and may be dropped by its parent.
     */
    private static function indexRemove(TopicNode $node, array $levels, int $depth): bool
    {
        if ($depth === count($levels)) {
            $node->topic = null;
        } else {
            $child = $node->children[$levels[$depth]] ?? null;
            if ($child !== null && self::indexRemove($child, $levels, $depth + 1)) {
                unset($node->children[$levels[$depth]]);
            }
        }

        return $node->isEmpty();
    }

    /**
     * @param list<string> $levels Filter levels.
     * @param list<string> $topics Collected topics.
     * @param-out list<string> $topics
     */
    private static function collect(TopicNode $node, array $levels, int $depth, array &$topics): void
    {
        if ($depth === count($levels)) {
            if ($node->topic !== null) {
                $topics[] = $node->topic;
            }
            return;
        }

        $level = $levels[$depth];

        if ($level === '#') {
            // '#' also matches the parent level, so 'sport/#' matches 'sport'.
            if ($node->topic !== null) {
                $topics[] = $node->topic;
            }
            self::collectDescendants($node, $depth === 0, $topics);
            return;
        }

        if ($level === '+') {
            foreach ($node->children as $key => $child) {
                // A wildcard at the first level does not match '$' topics (MQTT-4.7.2-1).
                if ($depth === 0 && str_starts_with((string) $key, '$')) {
                    continue;
                }
                self::collect($child, $levels, $depth + 1, $topics);
            }
            return;
        }

        $child = $node->children[$level] ?? null;
        if ($child !== null) {
            self::collect($child, $levels, $depth + 1, $topics);
        }
    }

    /**
     * @param list<string> $topics
     * @param-out list<string> $topics
     */
    private static function collectDescendants(TopicNode $node, bool $skipDollar, array &$topics): void
    {
        foreach ($node->children as $key => $child) {
            if ($skipDollar && str_starts_with((string) $key, '$')) {
                continue;
            }
            if ($child->topic !== null) {
                $topics[] = $child->topic;
            }
            self::collectDescendants($child, false, $topics);
        }
    }
}
