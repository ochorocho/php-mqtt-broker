<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Auth;

use RuntimeException;

/**
 * Reads and writes the broker's credential file.
 *
 * One `username:hash` per line, mirroring Mosquitto's passwd file so operators
 * recognise the format. Parsing is shared with bin/mqtt-passwd so the broker and
 * the tool that writes the file can never disagree about it.
 */
final class PasswordFile
{
    /**
     * Parse a credential file into a username => hash map.
     *
     * Every problem is fatal rather than skippable: a file that silently loses a
     * malformed line would deny one client for reasons invisible to the operator.
     *
     * @return array<string, string>
     * @throws RuntimeException If the file cannot be read or any line is unusable.
     */
    public static function parse(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(sprintf('Password file is not readable: %s', $path));
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Password file could not be read: %s', $path));
        }

        $users = [];
        foreach (explode("\n", $contents) as $index => $rawLine) {
            $line = trim($rawLine);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $lineNumber = $index + 1;

            // Limit 2: a bcrypt hash contains '$' and may contain ':', so only the
            // first separator delimits the username.
            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                throw new RuntimeException(sprintf(
                    'Password file %s line %d: expected "username:hash"',
                    $path,
                    $lineNumber,
                ));
            }

            [$username, $hash] = $parts;

            if ($username === '') {
                throw new RuntimeException(sprintf(
                    'Password file %s line %d: empty username',
                    $path,
                    $lineNumber,
                ));
            }

            if (password_get_info($hash)['algo'] === null) {
                throw new RuntimeException(sprintf(
                    'Password file %s line %d: password for "%s" is not a recognised hash. '
                        . 'Use bin/mqtt-passwd to write this file.',
                    $path,
                    $lineNumber,
                    $username,
                ));
            }

            // Last-wins would silently pick one of two credentials for the same
            // account, which is exactly the kind of surprise a credential store
            // must not spring on an operator.
            if (array_key_exists($username, $users)) {
                throw new RuntimeException(sprintf(
                    'Password file %s line %d: duplicate username "%s"',
                    $path,
                    $lineNumber,
                    $username,
                ));
            }

            $users[$username] = $hash;
        }

        if ($users === []) {
            throw new RuntimeException(sprintf(
                'Password file %s contains no users; the broker would reject every client',
                $path,
            ));
        }

        return $users;
    }

    /**
     * Serialise a username => hash map back to file contents.
     *
     * @param array<string, string> $users
     */
    public static function format(array $users): string
    {
        $lines = '';
        foreach ($users as $username => $hash) {
            $lines .= $username . ':' . $hash . "\n";
        }

        return $lines;
    }
}
