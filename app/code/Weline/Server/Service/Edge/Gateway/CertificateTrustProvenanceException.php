<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * The certificate bytes may be well formed, but their WLS trust provenance is
 * absent, inconsistent, or unsuitable for the requested serving profile.
 */
final class CertificateTrustProvenanceException extends \RuntimeException
{
}
