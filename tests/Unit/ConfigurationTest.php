<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit;

use PhpMqtt\Broker\Configuration;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase
{
    public function testCertificateIdentitiesNeedClientCertificates(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('tlsRequireClientCert');

        (new Configuration(tlsIdentityAsUsername: true))->validateTls();
    }

    public function testRequiringClientCertificatesNeedsTls(): void
    {
        // Without a server certificate the listener is plaintext, and "client
        // certificate required" was a setting nothing enforced.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('tlsCertPath');

        (new Configuration(tlsRequireClientCert: true, tlsClientCaPath: __FILE__))->validateTls();
    }

    public function testMutualTlsCapturesThePeerCertificate(): void
    {
        $context = (new Configuration(
            tlsCertPath: __FILE__,
            tlsRequireClientCert: true,
            tlsClientCaPath: __FILE__,
        ))->getSocketContext();

        self::assertTrue($context['tls']['capture_peer_cert'] ?? false);
    }
}
