<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Handler;

use PhpMqtt\Broker\Auth\AuthenticatorInterface;

/**
 * An authenticator whose answers the test controls, recording what it was asked.
 *
 * Each check can also be made to throw, so tests can assert that a failing backend
 * denies access rather than granting it or escaping into the event loop.
 */
final class RecordingAuthenticator implements AuthenticatorInterface
{
    /** @var list<string> */
    public array $calls = [];

    /**
     * @param list<string> $deniedPublishTopics
     * @param list<string> $deniedSubscribeFilters
     * @param list<string> $deniedClientIds
     */
    public function __construct(
        public bool $authenticateResult = true,
        public array $deniedPublishTopics = [],
        public array $deniedSubscribeFilters = [],
        public array $deniedClientIds = [],
        public ?string $throwOn = null,
    ) {
    }

    public function authenticate(string $clientId, ?string $username, ?string $password): bool
    {
        $this->calls[] = 'authenticate:' . $clientId;
        $this->maybeThrow('authenticate');

        return $this->authenticateResult;
    }

    public function canSubscribe(string $clientId, string $topicFilter): bool
    {
        $this->calls[] = 'canSubscribe:' . $topicFilter;
        $this->maybeThrow('canSubscribe');

        return !in_array($topicFilter, $this->deniedSubscribeFilters, true);
    }

    public function canPublish(string $clientId, string $topicName): bool
    {
        $this->calls[] = 'canPublish:' . $topicName;
        $this->maybeThrow('canPublish');

        return !in_array($topicName, $this->deniedPublishTopics, true);
    }

    public function canUseClientId(string $clientId, ?string $username): bool
    {
        $this->calls[] = 'canUseClientId:' . $clientId;
        $this->maybeThrow('canUseClientId');

        return !in_array($clientId, $this->deniedClientIds, true);
    }

    private function maybeThrow(string $check): void
    {
        if ($this->throwOn === $check) {
            throw new \RuntimeException('authentication backend unavailable');
        }
    }
}
