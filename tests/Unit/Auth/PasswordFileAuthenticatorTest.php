<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Auth;

use PhpMqtt\Broker\Auth\PasswordFileAuthenticator;
use PHPUnit\Framework\TestCase;

/**
 * The credential check an operator gets by pointing the broker at a password file.
 */
final class PasswordFileAuthenticatorTest extends TestCase
{
    private const string PASSWORD = 'correct horse battery staple';

    /** @return array<string, string> */
    private function users(): array
    {
        return ['alice' => password_hash(self::PASSWORD, PASSWORD_DEFAULT)];
    }

    public function testCorrectCredentialsAreAccepted(): void
    {
        $authenticator = new PasswordFileAuthenticator($this->users());

        self::assertTrue($authenticator->authenticate('alice', 'alice', self::PASSWORD));
    }

    public function testWrongPasswordIsRejected(): void
    {
        $authenticator = new PasswordFileAuthenticator($this->users());

        self::assertFalse($authenticator->authenticate('alice', 'alice', 'wrong'));
    }

    public function testUnknownUserIsRejected(): void
    {
        $authenticator = new PasswordFileAuthenticator($this->users());

        self::assertFalse($authenticator->authenticate('mallory', 'mallory', self::PASSWORD));
    }

    public function testAnonymousConnectionIsRejectedWhenAPasswordFileIsConfigured(): void
    {
        $authenticator = new PasswordFileAuthenticator($this->users());

        self::assertFalse($authenticator->authenticate('alice', null, null));
        self::assertFalse($authenticator->authenticate('alice', 'alice', null));
        self::assertFalse($authenticator->authenticate('alice', null, self::PASSWORD));
    }

    public function testPasswordContainingNullByteIsRejectedRatherThanThrowing(): void
    {
        $authenticator = new PasswordFileAuthenticator($this->users());

        // password_hash() throws on a NUL byte but password_verify() does not, so a
        // hostile password must fail the login rather than escape into the event loop.
        self::assertFalse($authenticator->authenticate('alice', 'alice', "bad\0password"));
    }

    public function testTopicAuthorizationIsOpenBecauseAclsAreNotInThisLayer(): void
    {
        $authenticator = new PasswordFileAuthenticator($this->users());

        self::assertTrue($authenticator->canSubscribe('alice', 'alice', 'any/#'));
        self::assertTrue($authenticator->canPublish('alice', 'alice', 'any/topic'));
    }

    public function testClientIdIsBoundToTheAccountByDefault(): void
    {
        $authenticator = new PasswordFileAuthenticator($this->users());

        self::assertTrue($authenticator->canUseClientId('alice', 'alice'));
        self::assertTrue($authenticator->canUseClientId('alice-sensor', 'alice'));
        self::assertFalse($authenticator->canUseClientId('bob', 'alice'));

        // A prefix match must not let "alice" claim "alicex".
        self::assertFalse($authenticator->canUseClientId('alicex', 'alice'));
    }

    public function testBoundClientIdRejectsAnAnonymousUsername(): void
    {
        $authenticator = new PasswordFileAuthenticator($this->users());

        self::assertFalse($authenticator->canUseClientId('alice', null));
    }

    public function testBindingCanBeDisabledForClientsWhoseIdIsUnrelatedToTheirAccount(): void
    {
        $authenticator = new PasswordFileAuthenticator($this->users(), bindClientIdToUsername: false);

        self::assertTrue($authenticator->canUseClientId('esp-living-room', 'alice'));
        self::assertTrue($authenticator->canUseClientId('anything', null));
    }

    public function testALongerAccountOwnsTheClientIdsThatStartWithIt(): void
    {
        $authenticator = new PasswordFileAuthenticator([
            'alice' => password_hash('a', PASSWORD_DEFAULT, ['cost' => 4]),
            'alice-bob' => password_hash('b', PASSWORD_DEFAULT, ['cost' => 4]),
        ]);

        // "alice" owns "alice-*", but "alice-bob" is an account of its own: without the
        // longest-owner rule, alice could evict alice-bob and inherit its sessions.
        self::assertFalse($authenticator->canUseClientId('alice-bob', 'alice'));
        self::assertFalse($authenticator->canUseClientId('alice-bob-phone', 'alice'));
        self::assertTrue($authenticator->canUseClientId('alice-bob-phone', 'alice-bob'));
        self::assertTrue($authenticator->canUseClientId('alice-phone', 'alice'));
        self::assertTrue($authenticator->canUseClientId('alice', 'alice'));
    }

    public function testARepeatedSuccessfulLoginSkipsThePasswordHash(): void
    {
        $verifications = 0;
        $authenticator = new PasswordFileAuthenticator(
            users: $this->users(),
            passwordVerifier: static function (string $password, string $hash) use (&$verifications): bool {
                $verifications++;

                return password_verify($password, $hash);
            },
        );

        // bcrypt is deliberately slow and runs on the only event-loop thread: a fleet
        // reconnecting after a restart used to block the broker for minutes.
        self::assertTrue($authenticator->authenticate('alice', 'alice', self::PASSWORD));
        self::assertTrue($authenticator->authenticate('alice-2', 'alice', self::PASSWORD));

        self::assertSame(1, $verifications);
    }

    public function testTheCacheNeverAcceptsADifferentPassword(): void
    {
        $verifications = 0;
        $authenticator = new PasswordFileAuthenticator(
            users: $this->users(),
            passwordVerifier: static function (string $password, string $hash) use (&$verifications): bool {
                $verifications++;

                return password_verify($password, $hash);
            },
        );

        self::assertTrue($authenticator->authenticate('alice', 'alice', self::PASSWORD));
        self::assertFalse($authenticator->authenticate('alice', 'alice', 'guess'));
        self::assertFalse($authenticator->authenticate('alice', 'alice', self::PASSWORD . ' '));

        // A miss is verified properly, so the cache changes speed and nothing else.
        self::assertSame(3, $verifications);
    }
}
