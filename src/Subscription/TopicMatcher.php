<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Subscription;

final class TopicMatcher
{
    /** @var array<string, mixed> */
    private array $root;

    public function __construct()
    {
        $this->root = ['children' => [], 'subscriptions' => []];
    }

    public function subscribe(string $topicFilter, Subscription $sub): void
    {
        $levels = explode('/', $topicFilter);
        $node = &$this->root;

        foreach ($levels as $level) {
            if (!isset($node['children'][$level])) {
                $node['children'][$level] = ['children' => [], 'subscriptions' => []];
            }
            $node = &$node['children'][$level];
        }

        $node['subscriptions'][$sub->clientId] = $sub;
    }

    public function unsubscribe(string $topicFilter, string $clientId): void
    {
        $levels = explode('/', $topicFilter);
        $node = &$this->root;

        foreach ($levels as $level) {
            if (!isset($node['children'][$level])) {
                return;
            }
            $node = &$node['children'][$level];
        }

        unset($node['subscriptions'][$clientId]);
    }

    /**
     * @return Subscription[]
     */
    public function match(string $topicName): array
    {
        $levels = explode('/', $topicName);
        $results = new SubscriptionCollector();

        $this->matchRecursive($this->root, $levels, 0, $results, $topicName);

        return $results->getAll();
    }

    public function removeClient(string $clientId): void
    {
        $this->removeClientRecursive($this->root, $clientId);
    }

    /**
     * @param array<string, mixed> $node
     * @param string[] $levels
     */
    private function matchRecursive(array $node, array $levels, int $depth, SubscriptionCollector $results, string $topicName): void
    {
        $isDollarTopic = isset($topicName[0]) && $topicName[0] === '$';

        if ($depth === count($levels)) {
            foreach ($node['subscriptions'] as $sub) {
                if ($sub instanceof Subscription) {
                    $results->add($sub);
                }
            }
            if (isset($node['children']['#']) && is_array($node['children']['#'])) {
                foreach ($node['children']['#']['subscriptions'] as $sub) {
                    if ($sub instanceof Subscription) {
                        $results->add($sub);
                    }
                }
            }
            return;
        }

        $currentLevel = $levels[$depth];

        if (isset($node['children'][$currentLevel]) && is_array($node['children'][$currentLevel])) {
            $this->matchRecursive($node['children'][$currentLevel], $levels, $depth + 1, $results, $topicName);
        }

        if (isset($node['children']['+']) && is_array($node['children']['+']) && !($isDollarTopic && $depth === 0)) {
            $this->matchRecursive($node['children']['+'], $levels, $depth + 1, $results, $topicName);
        }

        if (isset($node['children']['#']) && is_array($node['children']['#']) && !($isDollarTopic && $depth === 0)) {
            foreach ($node['children']['#']['subscriptions'] as $sub) {
                if ($sub instanceof Subscription) {
                    $results->add($sub);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private function removeClientRecursive(array &$node, string $clientId): void
    {
        unset($node['subscriptions'][$clientId]);

        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as &$child) {
                if (is_array($child)) {
                    $this->removeClientRecursive($child, $clientId);
                }
            }
        }
    }
}
