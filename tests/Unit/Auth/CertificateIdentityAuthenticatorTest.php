<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Auth;

use PhpMqtt\Broker\Auth\CertificateIdentityAuthenticator;
use PhpMqtt\Broker\Broker;
use PhpMqtt\Broker\Configuration;
use PhpMqtt\Broker\Tests\Unit\Broker\FakeServer;
use PHPUnit\Framework\TestCase;

final class CertificateIdentityAuthenticatorTest extends TestCase
{
    public function testAnIdentityIsRequired(): void
    {
        $authenticator = new CertificateIdentityAuthenticator();

        self::assertTrue($authenticator->authenticate('device-1', 'device-1', null));
        self::assertFalse($authenticator->authenticate('device-1', null, null));
    }

    public function testClientIdsAreBoundToTheIdentity(): void
    {
        $authenticator = new CertificateIdentityAuthenticator();

        self::assertTrue($authenticator->canUseClientId('device-1', 'device-1'));
        self::assertTrue($authenticator->canUseClientId('device-1-diag', 'device-1'));
        self::assertFalse($authenticator->canUseClientId('device-2', 'device-1'));
        self::assertFalse($authenticator->canUseClientId('device-1', null));
    }

    public function testTheBrokerRefusesItWithoutCertificateIdentities(): void
    {
        // Outside identity mode the username is whatever CONNECT says, and this
        // authenticator would let anyone in who sends one.
        $broker = new Broker(
            config: new Configuration(),
            server: new FakeServer(),
            authenticator: new CertificateIdentityAuthenticator(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('tlsIdentityAsUsername');
        $broker->start();
    }
}
