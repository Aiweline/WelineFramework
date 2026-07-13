<?php
declare(strict_types=1);

namespace Weline\Server\Api\Tls;

/**
 * Stable certificate-request challenge identifiers accepted by Server Query.
 */
final class CertificateChallenge
{
    public const DNS_01 = 'dns01';

    private function __construct()
    {
    }
}
