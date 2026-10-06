<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

/**
 * @deprecated Prefer PlaintextWorkerTlsPublicOriginBounce — RST alone becomes
 *             ERR_CONNECTION_RESET under HSTS/HTTPS-First and never 跟跳.
 */
final class PlaintextWorkerTlsClientHelloReject
{
    public static function isTlsHandshakePrefix(string $bytes): bool
    {
        return PlaintextWorkerTlsPublicOriginBounce::isTlsHandshakePrefix($bytes);
    }
}
