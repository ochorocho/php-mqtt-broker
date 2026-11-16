<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;

/**
 * The shipped binaries, run as an operator would. Every case here exits before a
 * listener is bound, so no port is opened.
 */
final class CliTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mqtt-cli-test-' . bin2hex(random_bytes(4));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->directory);
    }

    public function testTheBrokerRefusesToStartWithoutAnyAuthentication(): void
    {
        // An unconfigured broker used to accept anyone on every interface.
        [$exit, , $stderr] = $this->execute('bin/mqtt-broker', ['--port=1']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('--allow-anonymous', $stderr);
    }

    public function testAllowAnonymousCannotBeCombinedWithAuthentication(): void
    {
        [$exit, , $stderr] = $this->execute('bin/mqtt-broker', ['--allow-anonymous', '--password-file=/nonexistent']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('--allow-anonymous', $stderr);
    }

    public function testCertificateIdentitiesNeedMutualTls(): void
    {
        [$exit, , $stderr] = $this->execute('bin/mqtt-broker', ['--tls-identity-as-username']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('tlsRequireClientCert', $stderr);
    }

    public function testCertificateIdentitiesAreNotAnonymous(): void
    {
        [$exit, , $stderr] = $this->execute('bin/mqtt-broker', ['--tls-identity-as-username', '--allow-anonymous']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('--allow-anonymous', $stderr);
    }

    public function testAPassphraseOnTheCommandLineWarns(): void
    {
        // Every user on the host can read it from the process list.
        [, , $stderr] = $this->execute('bin/mqtt-broker', ['--tls-passphrase=secret']);

        self::assertStringContainsString('process list', $stderr);
        self::assertStringContainsString('--tls-passphrase-file', $stderr);
    }

    public function testThePassphraseCanBeReadFromAFile(): void
    {
        $file = $this->directory . '/passphrase';
        file_put_contents($file, "secret\n");
        chmod($file, 0600);

        // Gets as far as the certificate, so the passphrase file itself was accepted.
        [$exit, , $stderr] = $this->execute('bin/mqtt-broker', [
            '--tls-passphrase-file=' . $file,
            '--tls-cert=' . $this->directory . '/missing.crt',
            '--allow-anonymous',
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('certificate is not readable', $stderr);
        self::assertStringNotContainsString('passphrase', $stderr);
    }

    public function testAnUnreadablePassphraseFileIsAnError(): void
    {
        [$exit, , $stderr] = $this->execute('bin/mqtt-broker', [
            '--tls-passphrase-file=' . $this->directory . '/missing',
            '--allow-anonymous',
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('passphrase file is not readable', $stderr);
    }

    public function testAPassphraseAndAPassphraseFileAreNotBothAccepted(): void
    {
        [$exit, , $stderr] = $this->execute('bin/mqtt-broker', [
            '--tls-passphrase=secret',
            '--tls-passphrase-file=' . $this->directory . '/passphrase',
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('cannot be combined', $stderr);
    }

    public function testMqttPasswdWritesTheRequestedBcryptCost(): void
    {
        $file = $this->directory . '/passwd';

        [$exit] = $this->execute('bin/mqtt-passwd', ['-b', '--cost=5', $file, 'alice', 'secret']);

        self::assertSame(0, $exit);
        $hash = explode(':', trim((string) file_get_contents($file)), 2)[1];
        self::assertSame(5, password_get_info($hash)['options']['cost'] ?? null);
        self::assertTrue(password_verify('secret', $hash));
    }

    public function testMqttPasswdRejectsAnOutOfRangeCost(): void
    {
        [$exit, , $stderr] = $this->execute('bin/mqtt-passwd', ['-b', '--cost=3', $this->directory . '/passwd', 'alice', 'secret']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('cost', $stderr);
    }

    public function testMqttPasswdRefusesUsernamesThatAreTopicSyntax(): void
    {
        // Authorizers commonly scope topics by username ("alice/#"): a username that
        // is itself a level separator or a wildcard escapes that scope.
        foreach (['a/b', '+', 'a#', "a\tb"] as $username) {
            [$exit, , $stderr] = $this->execute('bin/mqtt-passwd', ['-b', $this->directory . '/passwd', $username, 'secret']);

            self::assertSame(1, $exit, $username);
            self::assertStringContainsString('Username', $stderr);
        }
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string, string}
     */
    private function execute(string $script, array $arguments): array
    {
        $command = array_merge([PHP_BINARY, dirname(__DIR__, 3) . '/' . $script], $arguments);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        // A regression that lets the broker start would otherwise hang the suite.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = $stderr = '';
        $deadline = microtime(true) + 10.0;
        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            usleep(20_000);
        }

        $status = proc_get_status($process);
        if ($status['running']) {
            proc_terminate($process, 9);
            self::fail(sprintf('%s did not exit within 10 s; stderr: %s', $script, $stderr));
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return [$status['exitcode'], $stdout, $stderr];
    }
}
