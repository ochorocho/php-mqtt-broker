<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Message;

/**
 * One level of the retained-topic index.
 *
 * @internal Used by RetainedMessageStore only.
 */
final class TopicNode
{
    /** @var array<array-key, TopicNode> children by level; numeric levels become int keys */
    public array $children = [];

    /** The topic that ends at this node, if one is retained here. */
    public ?string $topic = null;

    public function isEmpty(): bool
    {
        return $this->topic === null && $this->children === [];
    }
}
