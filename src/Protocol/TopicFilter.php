<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Protocol;

/**
 * Topic name and topic filter rules from the MQTT specification.
 *
 * This is deliberately the only place these rules live. Matching used to be
 * implemented twice — a trie for live routing and a separate iterative matcher for
 * retained delivery — and two engines guarding the same authorization boundary
 * drift apart, so a fix applied to one silently misses the other.
 */
final class TopicFilter
{
    public const string SHARED_PREFIX = '$share/';

    /**
     * A topic name is what a PUBLISH carries. It must not contain wildcards
     * (MQTT-3.3.2-2) and must not be empty (MQTT-4.7.3-1).
     */
    public static function isValidName(string $topicName): bool
    {
        if ($topicName === '') {
            return false;
        }

        return !str_contains($topicName, '+') && !str_contains($topicName, '#');
    }

    /**
     * A topic filter is what a SUBSCRIBE carries. Wildcards are allowed, but each
     * must occupy an entire level, and '#' must be the last level (MQTT-4.7.1-1,
     * MQTT-4.7.1-2, MQTT-4.7.1-3).
     */
    public static function isValidFilter(string $topicFilter): bool
    {
        if ($topicFilter === '') {
            return false;
        }

        $levels = explode('/', self::stripSharedPrefix($topicFilter));
        $lastIndex = count($levels) - 1;

        foreach ($levels as $index => $level) {
            // A wildcard character may only appear as the whole level.
            if ($level !== '+' && $level !== '#'
                && (str_contains($level, '+') || str_contains($level, '#'))
            ) {
                return false;
            }

            if ($level === '#' && $index !== $lastIndex) {
                return false;
            }
        }

        return true;
    }

    /**
     * A shared subscription is '$share/{group}/{filter}'. The group must be present
     * and must not itself contain a wildcard (MQTT-4.8.2-1, MQTT-4.8.2-2).
     */
    public static function isShared(string $topicFilter): bool
    {
        return str_starts_with($topicFilter, self::SHARED_PREFIX);
    }

    public static function isValidSharedFilter(string $topicFilter): bool
    {
        if (!self::isShared($topicFilter)) {
            return true;
        }

        $parts = explode('/', $topicFilter, 3);

        // Needs both a group and a filter: '$share/g' and '$share/' are malformed.
        if (count($parts) < 3 || $parts[1] === '' || $parts[2] === '') {
            return false;
        }

        return !str_contains($parts[1], '+') && !str_contains($parts[1], '#');
    }

    /**
     * The group name of a shared subscription, or null when not shared.
     */
    public static function sharedGroup(string $topicFilter): ?string
    {
        if (!self::isShared($topicFilter)) {
            return null;
        }

        $parts = explode('/', $topicFilter, 3);

        return count($parts) >= 3 ? $parts[1] : null;
    }

    /**
     * The filter with any '$share/{group}/' prefix removed. This is the form used for
     * matching; the full filter stays on the Subscription so it can be unsubscribed.
     */
    public static function stripSharedPrefix(string $topicFilter): string
    {
        if (!self::isShared($topicFilter)) {
            return $topicFilter;
        }

        $parts = explode('/', $topicFilter, 3);

        return count($parts) >= 3 ? $parts[2] : $topicFilter;
    }

    /**
     * Whether a topic name matches a filter.
     *
     * Topics beginning with '$' are excluded from a wildcard at the first level
     * (MQTT-4.7.2-1), so '#' does not expose '$SYS/...' to an ordinary subscriber.
     */
    public static function matches(string $topicName, string $topicFilter): bool
    {
        $filter = self::stripSharedPrefix($topicFilter);
        $topicLevels = explode('/', $topicName);
        $filterLevels = explode('/', $filter);
        $isDollarTopic = isset($topicName[0]) && $topicName[0] === '$';

        $topicCount = count($topicLevels);
        $filterCount = count($filterLevels);
        $ti = 0;

        for ($fi = 0; $fi < $filterCount; $fi++) {
            $filterLevel = $filterLevels[$fi];

            if ($filterLevel === '#') {
                if ($fi === 0 && $isDollarTopic) {
                    return false;
                }

                // '#' also matches the parent level, so 'sport/#' matches 'sport'.
                return true;
            }

            if ($ti >= $topicCount) {
                return false;
            }

            if ($filterLevel === '+') {
                if ($fi === 0 && $isDollarTopic) {
                    return false;
                }

                $ti++;
                continue;
            }

            if ($filterLevel !== $topicLevels[$ti]) {
                return false;
            }

            $ti++;
        }

        return $ti === $topicCount;
    }
}
