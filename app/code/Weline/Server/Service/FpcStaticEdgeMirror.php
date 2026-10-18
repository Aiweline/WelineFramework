<?php

declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\Http\Fpc\FpcStaticDiskStore;

/**
 * After WLS resolves a static file outside pub/, mirror it into the static FPC
 * disk tree so managed Nginx can try_files it on the next request.
 */
final class FpcStaticEdgeMirror
{
    public static function mirrorAfterResolve(string $rawRequest, string $uriPath, string $absoluteFile): void
    {
        if ($uriPath === '' || $absoluteFile === '') {
            return;
        }
        if (!FpcStaticDiskStore::shouldMirrorResolvedFile($absoluteFile)) {
            return;
        }
        $host = self::requestHost($rawRequest);
        if ($host === '') {
            return;
        }
        try {
            // publishFromFile content-hash heals stale live leaves when origin is reached
            // (Nginx _wb_nc / _weline_dev bypass + cold miss). Best-effort only.
            FpcStaticDiskStore::publishFromFile($host, $uriPath, $absoluteFile);
        } catch (\Throwable) {
            // Edge mirror is best-effort; never fail the static response.
        }
    }

    private static function requestHost(string $rawRequest): string
    {
        if (\preg_match('/(?:^|\r\n)Host:\s*([^\r\n]+)/i', $rawRequest, $match) !== 1) {
            return '';
        }
        // Keep host[:port] exactly as nginx $http_host → $wls_fpc_html_host.
        return \strtolower(\trim((string)$match[1]));
    }
}
