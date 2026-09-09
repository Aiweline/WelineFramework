<?php

declare(strict_types=1);

namespace Weline\Server\Service\Security;

use Weline\Framework\App\Env;

/**
 * 面板安全探针一次性 token。
 *
 * 打开「安全 → 探针」页时签发；TTL 内可大量用于探针请求。
 * WorkerPolicyKernel 校验通过后：仍可返回 403/400，但不 ban IP。
 */
final class SecurityProbeTokenService
{
    public const HEADER_NAME = SecurityProbeCatalog::HEADER_NAME;

    public const LIVE_BAN_HEADER_NAME = SecurityProbeCatalog::LIVE_BAN_HEADER;

    private const PURPOSE_PROBE = 'wls_security_probe';

    private const PURPOSE_LIVE_BAN = 'wls_security_live_ban';

    private const DEFAULT_TTL_SECONDS = 900;

    private const MAX_TTL_SECONDS = 1800;

    /**
     * @return array{token:string,header:string,expires_at:int,ttl_seconds:int,issued_at:int}
     */
    public function issue(int $ttlSeconds = self::DEFAULT_TTL_SECONDS): array
    {
        return $this->issueWithPurpose(self::PURPOSE_PROBE, self::HEADER_NAME, $ttlSeconds);
    }

    /**
     * @return array{token:string,header:string,expires_at:int,ttl_seconds:int,issued_at:int}
     */
    public function issueLiveBan(int $ttlSeconds = self::DEFAULT_TTL_SECONDS): array
    {
        return $this->issueWithPurpose(self::PURPOSE_LIVE_BAN, self::LIVE_BAN_HEADER_NAME, $ttlSeconds);
    }

    /**
     * @return array{token:string,header:string,expires_at:int,ttl_seconds:int,issued_at:int}
     */
    private function issueWithPurpose(string $purpose, string $header, int $ttlSeconds): array
    {
        $ttlSeconds = \max(60, \min(self::MAX_TTL_SECONDS, $ttlSeconds));
        $issuedAt = \time();
        $expiresAt = $issuedAt + $ttlSeconds;
        $payload = [
            'v' => 1,
            'iat' => $issuedAt,
            'exp' => $expiresAt,
            'jti' => \bin2hex(\random_bytes(16)),
            'purpose' => $purpose,
        ];
        $body = self::base64UrlEncode((string)\json_encode($payload, \JSON_UNESCAPED_SLASHES));
        $sig = \hash_hmac('sha256', $body, self::resolveSecret(), true);
        $token = $body . '.' . self::base64UrlEncode($sig);

        return [
            'token' => $token,
            'header' => $header,
            'expires_at' => $expiresAt,
            'ttl_seconds' => $ttlSeconds,
            'issued_at' => $issuedAt,
        ];
    }

    public static function isValid(?string $token, ?int $now = null): bool
    {
        return self::isValidForPurpose($token, self::PURPOSE_PROBE, $now);
    }

    public static function isValidLiveBan(?string $token, ?int $now = null): bool
    {
        return self::isValidForPurpose($token, self::PURPOSE_LIVE_BAN, $now);
    }

    private static function isValidForPurpose(?string $token, string $purpose, ?int $now = null): bool
    {
        $token = \trim((string)$token);
        if ($token === '' || !\str_contains($token, '.')) {
            return false;
        }
        [$body, $sig] = \explode('.', $token, 2);
        if ($body === '' || $sig === '') {
            return false;
        }
        $expected = self::base64UrlEncode(
            \hash_hmac('sha256', $body, self::resolveSecret(), true)
        );
        if (!\hash_equals($expected, $sig)) {
            return false;
        }
        $json = self::base64UrlDecode($body);
        if ($json === null) {
            return false;
        }
        try {
            $payload = \json_decode($json, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return false;
        }
        if (!\is_array($payload)) {
            return false;
        }
        if (($payload['purpose'] ?? '') !== $purpose) {
            return false;
        }
        $exp = (int)($payload['exp'] ?? 0);
        $iat = (int)($payload['iat'] ?? 0);
        $now ??= \time();
        if ($exp < $now || $iat > ($now + 60) || ($exp - $iat) > self::MAX_TTL_SECONDS) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, string> $headers lower-or-mixed case HTTP headers
     */
    public static function headerHasValidToken(array $headers): bool
    {
        return self::headerHasValidFor($headers, self::HEADER_NAME, self::PURPOSE_PROBE);
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public static function headerHasValidLiveBanToken(array $headers): bool
    {
        return self::headerHasValidFor($headers, self::LIVE_BAN_HEADER_NAME, self::PURPOSE_LIVE_BAN);
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    private static function headerHasValidFor(array $headers, string $headerName, string $purpose): bool
    {
        $needle = \strtolower($headerName);
        foreach ($headers as $name => $value) {
            if (\strtolower((string)$name) !== $needle) {
                continue;
            }
            if (\is_array($value)) {
                $value = (string)($value[0] ?? '');
            }
            return self::isValidForPurpose((string)$value, $purpose);
        }

        return false;
    }

    private static function resolveSecret(): string
    {
        try {
            $configured = Env::getInstance()->getConfig('wls.security_probe_secret');
            if (\is_string($configured) && $configured !== '') {
                return $configured;
            }
            $crypt = Env::getInstance()->getConfig('crypt.key');
            if (\is_string($crypt) && $crypt !== '') {
                return \hash('sha256', $crypt . '|wls-security-probe', true);
            }
        } catch (\Throwable) {
            // Worker 热路径兜底：仍要有稳定密钥。
        }

        $seed = (\defined('BP') ? (string)BP : '') . '|wls-security-probe-fallback';
        return \hash('sha256', $seed, true);
    }

    private static function base64UrlEncode(string $raw): string
    {
        return \rtrim(\strtr(\base64_encode($raw), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $remainder = \strlen($value) % 4;
        if ($remainder > 0) {
            $value .= \str_repeat('=', 4 - $remainder);
        }
        $decoded = \base64_decode(\strtr($value, '-_', '+/'), true);
        return \is_string($decoded) ? $decoded : null;
    }
}
