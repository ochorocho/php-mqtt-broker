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

    /** Per-process key for the success cache, so its entries are useless off-process. */
    private readonly string $cacheKey;

    /**
     * username => HMAC of the last password that verified for it.
     *
     * bcrypt is deliberately slow and runs on the broker's only thread, so a fleet
     * reconnecting after a restart used to block every client for minutes. A known-good
     * password is checked once; afterwards a keyed hash comparison suffices. Bounded by
     * the number of accounts, one entry each.
     *
     * @var array<string, string>
     */
    private array $verified = [];

    /** @var \Closure(string, string): bool */
    private readonly \Closure $passwordVerifier;

    /**
     * @param array<string, string> $users username => password_hash() output
     * @param bool $bindClientIdToUsername Require the client ID to belong to the
     *        authenticated account. On by default: without it, any valid account can
     *        evict another client and inherit its session.
     * @param (\Closure(string, string): bool)|null $passwordVerifier Defaults to
     *        password_verify(); replaceable so tests can observe when hashing happens.
     */
    public function __construct(
        private readonly array $users,
        private readonly bool $bindClientIdToUsername = true,
        ?\Closure $passwordVerifier = null,
    ) {
        $this->dummyHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $this->cacheKey = random_bytes(32);
        $this->passwordVerifier = $passwordVerifier ?? password_verify(...);
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

        $mac = hash_hmac('sha256', $username . "\0" . $password, $this->cacheKey, true);
        if (isset($this->verified[$username]) && hash_equals($this->verified[$username], $mac)) {
            return true;
        }

        // Always verify, even for an unknown user. Returning early here would make a
        // valid username measurably slower than an invalid one and enumerate accounts.
        $hash = $this->users[$username] ?? $this->dummyHash;

        if (!($this->passwordVerifier)($password, $hash) || !isset($this->users[$username])) {
            return false;
        }

        $this->verified[$username] = $mac;

        return true;
    }

    public function canSubscribe(string $clientId, ?string $username, string $topicFilter): bool
    {
        return true;
    }

    public function canPublish(string $clientId, ?string $username, string $topicName): bool
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

        // "alice" owns "alice" and "alice-<anything>", so one account can run several
        // devices. The most specific account wins: if "alice-bob" exists, it owns
        // "alice-bob" and "alice-bob-*", which "alice" would otherwise also match.
        return $this->ownerOf($clientId) === $username;
    }

    private function ownerOf(string $clientId): ?string
    {
        $owner = null;
        foreach (array_keys($this->users) as $candidate) {
            $candidate = (string) $candidate;
            $owns = $clientId === $candidate || str_starts_with($clientId, $candidate . '-');
            if ($owns && ($owner === null || strlen($candidate) > strlen($owner))) {
                $owner = $candidate;
            }
        }

        return $owner;
    }
}
