<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Auth;

use PhpMqtt\Broker\Auth\PasswordFile;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The parser decides whether a broker starts, so every malformed input must be
 * reported precisely rather than skipped.
 */
final class PasswordFileTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->tempFiles = [];
    }

    private function writeFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mqtt-passwd-');
        self::assertIsString($path);
        $this->tempFiles[] = $path;
        file_put_contents($path, $contents);

        return $path;
    }

    private function hash(string $password = 'secret'): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public function testParsesEveryUserInTheFile(): void
    {
        $alice = $this->hash('alice-pw');
        $bob = $this->hash('bob-pw');
        $path = $this->writeFile("alice:$alice\nbob:$bob\n");

        self::assertSame(['alice' => $alice, 'bob' => $bob], PasswordFile::parse($path));
    }

    public function testSkipsCommentsAndBlankLines(): void
    {
        $hash = $this->hash();
        $path = $this->writeFile("# operators\n\n   \nalice:$hash\n\n");

        self::assertSame(['alice' => $hash], PasswordFile::parse($path));
    }

    public function testHashContainingSeparatorCharactersSurvivesParsing(): void
    {
        // A bcrypt hash contains '$' and '/', and could contain ':'. Splitting on
        // every ':' instead of the first would corrupt it.
        $hash = $this->hash();
        $path = $this->writeFile("alice:$hash\n");

        self::assertSame($hash, PasswordFile::parse($path)['alice']);
    }

    public function testMalformedLineIsReportedWithItsLineNumber(): void
    {
        $hash = $this->hash();
        $path = $this->writeFile("alice:$hash\nnocolonhere\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/line 2/');
        PasswordFile::parse($path);
    }

    public function testPlaintextPasswordIsRejectedAsNotAHash(): void
    {
        $path = $this->writeFile("alice:plaintext\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not a recognised hash/');
        PasswordFile::parse($path);
    }

    public function testEmptyUsernameIsRejected(): void
    {
        $hash = $this->hash();
        $path = $this->writeFile(":$hash\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/empty username/');
        PasswordFile::parse($path);
    }

    public function testDuplicateUsernameIsRejectedRatherThanSilentlyResolved(): void
    {
        $first = $this->hash('first');
        $second = $this->hash('second');
        $path = $this->writeFile("alice:$first\nalice:$second\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/duplicate username/');
        PasswordFile::parse($path);
    }

    public function testMissingFileIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not readable/');
        PasswordFile::parse(sys_get_temp_dir() . '/does-not-exist-' . bin2hex(random_bytes(4)));
    }

    public function testFileWithoutUsersIsRejected(): void
    {
        $path = $this->writeFile("# nobody here\n\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no users/');
        PasswordFile::parse($path);
    }

    public function testFormatRoundTripsThroughParse(): void
    {
        $users = ['alice' => $this->hash('a'), 'bob' => $this->hash('b')];
        $path = $this->writeFile(PasswordFile::format($users));

        self::assertSame($users, PasswordFile::parse($path));
    }
}
