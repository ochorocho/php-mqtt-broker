<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Auth;

/**
 * Authenticates clients against a file of hashed credentials.
 *
 * Holds an already-parsed map rather than a path, so the class stays pure and its
 * tests need no filesystem; PasswordFile does the I/O, and fromFile() joins them.
 *
 * Topic authorization is deliberately not implemented here: a password file has no
 * syntax for per-topic rules, so every authenticated client may publish and
 * subscribe anywhere. Deployments needing ACLs supply their own AuthenticatorInterface
 * via the broker's --auth option.
 */
final class PasswordFileAuthenticator implements AuthenticatorInterface
{
    /**
     * A hash no password can match, used to spend the same time on an unknown
     * username as on a known one. Computed per process rather than hardcoded,
     * because a constant published in open source is a known value.
     */
    private readonly string $dummyHash;

    /**
     * @param array<string, string> $users username => password_hash() output
     * @param bool $bindClientIdToUsername Require the client ID to belong to the
     *        authenticated account. On by default: without it, any valid account can
     *        evict another client and inherit its session.
     */
    public function __construct(
        private readonly array $users,
        private readonly bool $bindClientIdToUsername = true,
    ) {
        $this->dummyHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    }

    /**
     * @throws \RuntimeException If the file cannot be read or is malformed.
     */
    public static function fromFile(string $path, bool $bindClientIdToUsername = true): self
    {
        return new self(
            users: PasswordFile::parse($path),
            bindClientIdToUsername: $bindClientIdToUsername,
        );
    }

    public function authenticate(string $clientId, ?string $username, ?string $password): bool
    {
        // Configuring a password file makes credentials mandatory; an anonymous
        // connection is not a client with an empty password.
        if ($username === null || $password === null) {
            return false;
        }

        // Always verify, even for an unknown user. Returning early here would make a
        // valid username measurably slower than an invalid one and enumerate accounts.
        $hash = $this->users[$username] ?? $this->dummyHash;

        return password_verify($password, $hash);
    }

    public function canSubscribe(string $clientId, string $topicFilter): bool
    {
        return true;
    }

    public function canPublish(string $clientId, string $topicName): bool
    {
        return true;
    }

    public function canUseClientId(string $clientId, ?string $username): bool
    {
        if (!$this->bindClientIdToUsername) {
            return true;
        }

        if ($username === null) {
            return false;
        }

        // "alice" owns "alice" and "alice-<anything>", so one account can still run
        // several devices without being able to claim another account's ID.
        return $clientId === $username || str_starts_with($clientId, $username . '-');
    }
}
