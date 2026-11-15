<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Server;

/**
 * Optional: a transport that can name the client from a verified certificate.
 *
 * Kept apart from ConnectionStream so a transport without TLS need not implement it.
 */
interface PeerCertificateStream
{
    /**
     * The common name of the client certificate the TLS handshake verified, or null
     * when there is none (or it carries no single common name).
     */
    public function getPeerCertificateCommonName(): ?string;
}
