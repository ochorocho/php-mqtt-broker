<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Auth;

use PhpMqtt\Broker\Auth\AuthenticatorInterface;
use PhpMqtt\Broker\Auth\AuthenticatorLoader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Loading a custom authenticator fails at startup with a legible message, rather
 * than at the first CONNECT with a type error.
 */
final class AuthenticatorLoaderTest extends TestCase
{
    private function fixture(string $name): string
    {
        return __DIR__ . '/Fixtures/' . $name;
    }

    public function testLoadsAnAuthenticatorReturnedByTheFile(): void
    {
        $authenticator = AuthenticatorLoader::load($this->fixture('valid-auth.php'));

        self::assertInstanceOf(AuthenticatorInterface::class, $authenticator);
        self::assertTrue($authenticator->authenticate('c', 'fixture', 'pw'));
        self::assertFalse($authenticator->authenticate('c', 'someone-else', 'pw'));
    }

    public function testFileThatDefinesButNeverReturnsIsReportedAsInt(): void
    {
        $this->expectException(RuntimeException::class);
        // require() on such a file yields int(1); saying so points at the real mistake.
        $this->expectExceptionMessageMatches('/returned int/');
        AuthenticatorLoader::load($this->fixture('defines-but-returns-nothing.php'));
    }

    public function testFileReturningTheWrongObjectNamesTheTypeItGot(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/returned stdClass/');
        AuthenticatorLoader::load($this->fixture('returns-wrong-object.php'));
    }

    public function testErrorNamesTheExpectedInterfaceAndTheFix(): void
    {
        try {
            AuthenticatorLoader::load($this->fixture('returns-wrong-object.php'));
            self::fail('Expected a RuntimeException');
        } catch (RuntimeException $e) {
            self::assertStringContainsString(AuthenticatorInterface::class, $e->getMessage());
            self::assertStringContainsString('return new YourAuthenticator();', $e->getMessage());
        }
    }

    public function testMissingFileIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not readable/');
        AuthenticatorLoader::load($this->fixture('no-such-file-' . bin2hex(random_bytes(4)) . '.php'));
    }
}
