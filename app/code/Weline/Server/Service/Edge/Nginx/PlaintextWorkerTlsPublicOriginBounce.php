<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

use Weline\Framework\App\Env;
use Weline\Server\Dispatcher\SniParser;

/**
 * When a browser (HTTPS-First / HSTS) opens https://{public}:{workerPort}/,
 * the plaintext Worker must terminate TLS briefly and 308 to the managed Nginx
 * public HTTPS origin. RST alone yields ERR_CONNECTION_RESET and never 跟跳.
 */
final class PlaintextWorkerTlsPublicOriginBounce
{
    public static function isTlsHandshakePrefix(string $bytes): bool
    {
        return $bytes !== '' && \ord($bytes[0]) === 0x16;
    }

    /**
     * @return array{local_cert:non-empty-string,local_pk:non-empty-string}|null
     */
    public static function resolveCertificatePair(string $sniOrHost): ?array
    {
        $host = \strtolower(\trim($sniOrHost));
        if ($host === '' || \str_contains($host, "\0")) {
            return null;
        }

        $map = self::loadCertificateMap();
        if ($map === []) {
            return null;
        }

        $entry = $map[$host] ?? null;
        if (!\is_array($entry)) {
            foreach ($map as $pattern => $candidate) {
                if (!\is_string($pattern) || !\is_array($candidate) || !\str_starts_with($pattern, '*.')) {
                    continue;
                }
                $suffix = \substr($pattern, 1); // ".example.com"
                if ($suffix !== '' && (\str_ends_with($host, $suffix) || $host === \substr($pattern, 2))) {
                    $entry = $candidate;
                    break;
                }
            }
        }
        if (!\is_array($entry)) {
            return null;
        }

        $cert = \trim((string)($entry['cert'] ?? $entry['local_cert'] ?? ''));
        $key = \trim((string)($entry['key'] ?? $entry['local_pk'] ?? $entry['privkey'] ?? ''));
        if ($cert === '' || $key === '' || !\is_file($cert) || !\is_file($key)) {
            return null;
        }

        return ['local_cert' => $cert, 'local_pk' => $key];
    }

    public static function preferredHostFromPublicOrigin(string $publicOrigin): string
    {
        $publicOrigin = \trim($publicOrigin);
        if ($publicOrigin === '') {
            return '';
        }
        try {
            $host = \parse_url($publicOrigin, PHP_URL_HOST);
        } catch (\ValueError) {
            return '';
        }

        return \is_string($host) ? \strtolower(\trim($host, '[]')) : '';
    }

    public static function sniFromClientHelloPeek(string $peek): string
    {
        if ($peek === '' || !self::isTlsHandshakePrefix($peek)) {
            return '';
        }
        try {
            $sni = SniParser::extractSNI($peek);
        } catch (\Throwable) {
            return '';
        }

        return \is_string($sni) ? \strtolower(\trim($sni)) : '';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function loadCertificateMap(): array
    {
        $path = Env::VAR_DIR . 'server' . \DIRECTORY_SEPARATOR . 'ssl_certificate_map.json';
        if (!\is_file($path)) {
            return [];
        }
        try {
            $raw = \file_get_contents($path);
        } catch (\Throwable) {
            return [];
        }
        if (!\is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }
}
