<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Auth;

interface AuthenticatorInterface
{
    public function authenticate(string $clientId, ?string $username, ?string $password): bool;

    /**
     * Check if a topic filter is allowed for subscription.
     *
     * @param ?string $username The username this connection authenticated with, or null
     *                          for an anonymous one. Decide per account from this, not
     *                          from a clientId-to-username map kept elsewhere: such a
     *                          map is written during authenticate(), before the broker
     *                          has accepted the client ID, and a refused attempt can
     *                          overwrite it.
     * @param string $topicFilter The filter as sent, wildcards and any `$share/<group>/`
     *                            prefix included.
     */
    public function canSubscribe(string $clientId, ?string $username, string $topicFilter): bool;

    /**
     * Check if a topic is allowed for publishing.
     *
     * @param ?string $username As for canSubscribe().
     */
    public function canPublish(string $clientId, ?string $username, string $topicName): bool;

    /**
     * Check whether the authenticated credentials may use this client ID.
     *
     * Connecting with another client's ID evicts that client and, for persistent
     * sessions, inherits its subscriptions and queued messages. Bind the client ID
     * to the authenticated principal here to prevent takeover of another identity.
     *
     * Not called for an ID the broker assigned itself (an empty client ID): those are
     * random and belong to no account.
     */
    public function canUseClientId(string $clientId, ?string $username): bool;
}
