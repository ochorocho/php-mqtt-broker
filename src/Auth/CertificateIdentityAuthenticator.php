<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Auth;

/**
 * For a broker that identifies clients by their TLS certificate alone.
 *
 * Only meaningful with Configuration::$tlsIdentityAsUsername, where the username is the
 * common name of a certificate the handshake verified: then a username is proof, and
 * this authenticator accepts it. Outside that mode the username is whatever CONNECT
 * says, and this would let in anyone who sends one - so Broker::start() refuses it.
 *
 * Client IDs are bound to the identity the way PasswordFileAuthenticator binds them to
 * an account: "device-1" may connect as "device-1" or "device-1-<anything>".
 */
final class CertificateIdentityAuthenticator implements AuthenticatorInterface
{
    public function authenticate(string $clientId, ?string $username, ?string $password): bool
    {
        return $username !== null;
    }

    public function canUseClientId(string $clientId, ?string $username): bool
    {
        return $username !== null
            && ($clientId === $username || str_starts_with($clientId, $username . '-'));
    }

    public function canSubscribe(string $clientId, ?string $username, string $topicFilter): bool
    {
        return true;
    }

    public function canPublish(string $clientId, ?string $username, string $topicName): bool
    {
        return true;
    }
}
