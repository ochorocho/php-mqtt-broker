<?php

declare(strict_types=1);

/**
 * The most common mistake: the file defines an authenticator but never returns an
 * instance, so require() yields int(1).
 */
final class FixtureAuthenticatorThatIsNeverReturned
{
    public function authenticate(string $clientId, ?string $username, ?string $password): bool
    {
        return true;
    }
}
