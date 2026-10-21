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

    private const SECRET_FILE = 'security_probe_secret';

    private static ?string $secretCache = null;

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
        $secret = self::resolveSecret();
        if ($secret === '') {
            throw new \RuntimeException('WLS security probe secret is unavailable.');
        }
        $body = self::base64UrlEncode((string)\json_encode($payload, \JSON_UNESCAPED_SLASHES));
        $sig = \hash_hmac('sha256', $body, $secret, true);
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
        $secret = self::resolveSecret();
        if ($secret === '') {
            return false;
        }
        $expected = self::base64UrlEncode(
            \hash_hmac('sha256', $body, $secret, true)
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

    /**
     * Resolve the probe-token signing secret.
     *
     * Priority: explicit `wls.security_probe_secret`, then `crypt.key`, then a
     * per-project random secret persisted under `var/server`. The project path
     * is never used as key material; a missing/unreadable secret fails closed.
     */
    private static function resolveSecret(): string
    {
        if (self::$secretCache !== null) {
            return self::$secretCache;
        }
        try {
            $configured = Env::getInstance()->getConfig('wls.security_probe_secret');
            if (\is_string($configured) && $configured !== '') {
                return self::$secretCache = $configured;
            }
            $crypt = Env::getInstance()->getConfig('crypt.key');
            if (\is_string($crypt) && $crypt !== '') {
                return self::$secretCache = \hash(
                    'sha256',
                    $crypt . '|wls-security-probe',
                    true,
                );
            }
            $persisted = self::resolvePersistedSecret();
            if ($persisted !== '') {
                return self::$secretCache = $persisted;
            }
        } catch (\Throwable) {
            // Fail closed below.
        }

        return '';
    }

    private static function resolvePersistedSecret(): string
    {
        try {
            $directory = \Weline\Framework\App\Env::varDir() . 'server';
        } catch (\Throwable) {
            return '';
        }
        if (!\is_dir($directory)) {
            @\mkdir($directory, 0700, true);
        }
        $path = \rtrim($directory, '/\\') . \DIRECTORY_SEPARATOR . self::SECRET_FILE;

        // Exclusive create avoids a cross-process race producing two secrets.
        $handle = @\fopen($path, 'x');
        if (\is_resource($handle)) {
            try {
                $secret = \random_bytes(32);
                \fwrite($handle, \bin2hex($secret));
                \fflush($handle);
            } finally {
                \fclose($handle);
                @\chmod($path, 0600);
            }
            return $secret;
        }

        $raw = @\file_get_contents($path);
        if (!\is_string($raw)) {
            return '';
        }
        $hex = \strtolower(\trim($raw));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $hex) !== 1) {
            return '';
        }
        $decoded = \hex2bin($hex);

        return \is_string($decoded) ? $decoded : '';
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
