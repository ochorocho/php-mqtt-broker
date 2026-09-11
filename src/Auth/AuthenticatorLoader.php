<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Auth;

use RuntimeException;

/**
 * Loads an operator-supplied AuthenticatorInterface from a PHP file.
 *
 * This is the escape hatch for anything the password file cannot express — a
 * database lookup, LDAP, or per-topic ACLs.
 *
 * The file is executed with the broker's privileges, so it must be owned by the
 * operator and not writable by the user the broker runs as. It is trusted to the
 * same degree as the broker binary itself.
 */
final class AuthenticatorLoader
{
    /**
     * @throws RuntimeException If the file is unreadable or does not return an authenticator.
     */
    public static function load(string $path): AuthenticatorInterface
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(sprintf('Authenticator file is not readable: %s', $path));
        }

        $result = require $path;

        if (!$result instanceof AuthenticatorInterface) {
            // A file that defines a class but forgets to return an instance yields
            // int(1) from require, so name what came back rather than let it fail
            // later as a type error far from the cause.
            throw new RuntimeException(sprintf(
                'Authenticator file %s returned %s, expected an instance of %s. '
                    . 'The file must end with: return new YourAuthenticator();',
                $path,
                get_debug_type($result),
                AuthenticatorInterface::class,
            ));
        }

        return $result;
    }
}
