<?php

declare(strict_types=1);

namespace Weline\Captcha\Service;

/**
 * Resolve ISO3166-1 alpha-2 country from trusted reverse-proxy headers.
 * Unknown / missing → XX (no embedded IP geolocation database in v1).
 */
final class ClientGeoResolver
{
    public const UNKNOWN = 'XX';

    public function __construct(private readonly CaptchaConfig $config)
    {
    }

    /**
     * @param array<string, mixed> $server Typically $_SERVER-like map
     */
    public function resolveFromServer(array $server): string
    {
        foreach ($this->config->geoHeaders() as $header) {
            $value = $this->headerValue($server, $header);
            $code = $this->normalizeCountryCode($value);
            if ($code !== self::UNKNOWN) {
                return $code;
            }
        }
        return self::UNKNOWN;
    }

    public function normalizeCountryCode(string $raw): string
    {
        $code = \strtoupper(\trim($raw));
        if ($code === '' || $code === 'XX' || $code === 'T1' || $code === 'ZZ') {
            return self::UNKNOWN;
        }
        if (\preg_match('/\A[A-Z]{2}\z/D', $code) !== 1) {
            return self::UNKNOWN;
        }
        return $code;
    }

    /**
     * @param array<string, mixed> $server
     */
    private function headerValue(array $server, string $header): string
    {
        $header = \trim($header);
        if ($header === '') {
            return '';
        }
        $candidates = [
            $header,
            'HTTP_' . \strtoupper(\str_replace('-', '_', $header)),
            \strtoupper(\str_replace('-', '_', $header)),
        ];
        foreach ($candidates as $key) {
            if (!\array_key_exists($key, $server)) {
                continue;
            }
            $value = \trim((string)$server[$key]);
            if ($value !== '') {
                // Some proxies send "CN,XX" — take the first token.
                $parts = \preg_split('/[\s,;]+/', $value, 2) ?: [];
                return (string)($parts[0] ?? $value);
            }
        }
        return '';
    }
}
