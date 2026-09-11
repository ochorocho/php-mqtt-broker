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

        self::assertTrue($authenticator->canSubscribe('alice', 'any/#'));
        self::assertTrue($authenticator->canPublish('alice', 'any/topic'));
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
}
