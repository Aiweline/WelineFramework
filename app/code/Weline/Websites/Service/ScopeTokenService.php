<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Websites\Service\Value\ScopeTokenVerification;

final class ScopeTokenService
{
    public const VERSION = 'v1';
    public const AUDIENCE = 'weline.storefront.v1';
    public const TTL_SECONDS = 1800;
    public const RENEW_BEFORE_SECONDS = 300;
    public const CLOCK_SKEW_SECONDS = \Weline\Framework\Service\Query\Value\FrontendWorkerScopeBinding::CLOCK_SKEW_SECONDS;
    private const MAX_TOKEN_BYTES = 4096;
    private const DOMAIN = "weline-scope-token\0";
    private const KID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D';

    public function __construct(private readonly ScopeTokenKeyring $keyring)
    {
    }

    public function issue(ScopeIdentity $scope, string $host, ?int $now = null): string
    {
        if ($scope->scopeKind !== ScopeIdentity::KIND_CHANNEL) {
            throw new \InvalidArgumentException(__('Storefront Scope Token 必须使用完整 Channel Scope'));
        }
        $host = $this->normalizeHost($host);
        $now ??= time();
        if ($now <= 0 || $now > PHP_INT_MAX - self::TTL_SECONDS) {
            throw new \RuntimeException(__('Scope Token 时钟无效'));
        }
        $active = $this->keyring->active();
        $json = $this->encodeCanonicalPayload(
            $scope,
            $host,
            $now,
            $now,
            $now + self::TTL_SECONDS,
        );
        $body = self::b64($json);
        $signingInput = self::signingInput($active['kid'], $body);
        $signature = self::b64(hash_hmac('sha256', $signingInput, $active['secret'], true));
        return self::VERSION . '.' . $active['kid'] . '.' . $body . '.' . $signature;
    }

    public function verify(
        string $token,
        string $host,
        ScopeIdentity $trustedScope,
        ?int $now = null,
    ): ScopeTokenVerification {
        $verification = $this->verifyCandidate($token, $host, $now);
        if ($verification->scope === null
            || ($verification->status !== ScopeTokenVerification::STATUS_VALID
                && $verification->status !== ScopeTokenVerification::STATUS_EXPIRED)) {
            return $verification;
        }

        if (!$verification->scope->equals($trustedScope)) {
            $reason = $verification->scope->storeMode !== $trustedScope->storeMode
                ? 'store_mode_mismatch'
                : ($verification->scope->contextVersion !== $trustedScope->contextVersion
                    ? 'context_version_mismatch'
                    : 'scope_mismatch');
            return ScopeTokenVerification::rejected(
                ScopeTokenVerification::STATUS_CONTEXT_CONFLICT,
                $reason,
                $verification->tokenFingerprint,
                $verification->kid,
            );
        }

        return $verification;
    }

    public function verifyCandidate(
        string $token,
        string $host,
        ?int $now = null,
    ): ScopeTokenVerification {
        $fingerprint = substr(hash('sha256', $token), 0, 16);
        $now ??= time();
        if ($now <= 0
            || $token === ''
            || $token !== trim($token)
            || strlen($token) > self::MAX_TOKEN_BYTES) {
            return ScopeTokenVerification::rejected(
                ScopeTokenVerification::STATUS_INVALID,
                'malformed',
                $fingerprint,
            );
        }
        $parts = explode('.', $token);
        if (count($parts) !== 4 || $parts[0] !== self::VERSION) {
            return ScopeTokenVerification::rejected(ScopeTokenVerification::STATUS_INVALID, 'malformed', $fingerprint);
        }
        [$version, $kid, $body, $signature] = $parts;
        $signatureBytes = self::unb64($signature);
        $payloadJson = self::unb64($body);
        if (preg_match(self::KID_PATTERN, $kid) !== 1
            || !is_string($signatureBytes)
            || strlen($signatureBytes) !== 32
            || !is_string($payloadJson)) {
            return ScopeTokenVerification::rejected(ScopeTokenVerification::STATUS_INVALID, 'malformed', $fingerprint, $kid);
        }

        try {
            $key = $this->keyring->verification($kid, $now);
        } catch (\Throwable) {
            return ScopeTokenVerification::rejected(
                ScopeTokenVerification::STATUS_SERVICE_UNAVAILABLE,
                'keyring_unavailable',
                $fingerprint,
                $kid,
            );
        }
        if ($key === null) {
            return ScopeTokenVerification::rejected(ScopeTokenVerification::STATUS_INVALID, 'unknown_kid', $fingerprint, $kid);
        }
        $expected = self::b64(hash_hmac('sha256', self::signingInput($kid, $body), $key['secret'], true));
        if (!hash_equals($expected, $signature)) {
            return ScopeTokenVerification::rejected(ScopeTokenVerification::STATUS_INVALID, 'bad_signature', $fingerprint, $kid);
        }

        try {
            $payload = json_decode($payloadJson, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return ScopeTokenVerification::rejected(ScopeTokenVerification::STATUS_INVALID, 'malformed_claims', $fingerprint, $kid);
        }
        if (!$this->hasExactClaims($payload)) {
            return ScopeTokenVerification::rejected(ScopeTokenVerification::STATUS_INVALID, 'malformed_claims', $fingerprint, $kid);
        }
        $iat = $payload['iat'];
        $nbf = $payload['nbf'];
        $exp = $payload['exp'];
        if (!is_int($iat) || !is_int($nbf) || !is_int($exp)
            || $iat < 1
            || $iat > PHP_INT_MAX - self::TTL_SECONDS
            || $nbf !== $iat
            || $exp - $iat !== self::TTL_SECONDS
            || $iat > $now + self::CLOCK_SKEW_SECONDS
            || $nbf > $now + self::CLOCK_SKEW_SECONDS) {
            return ScopeTokenVerification::rejected(ScopeTokenVerification::STATUS_INVALID, 'invalid_time_claims', $fingerprint, $kid);
        }
        if ($key['status'] === 'verify_only') {
            if ($key['signing_not_after'] > PHP_INT_MAX - self::TTL_SECONDS) {
                return ScopeTokenVerification::rejected(
                    ScopeTokenVerification::STATUS_SERVICE_UNAVAILABLE,
                    'keyring_rotation_window_invalid',
                    $fingerprint,
                    $kid,
                );
            }
            $minimumVerificationWindow = $key['signing_not_after'] + self::TTL_SECONDS;
            if ($key['verify_until'] < $minimumVerificationWindow) {
                return ScopeTokenVerification::rejected(
                    ScopeTokenVerification::STATUS_SERVICE_UNAVAILABLE,
                    'keyring_rotation_window_invalid',
                    $fingerprint,
                    $kid,
                );
            }
            if ($iat > $key['signing_not_after'] || $exp > $key['verify_until']) {
                return ScopeTokenVerification::rejected(
                    ScopeTokenVerification::STATUS_INVALID,
                    'retired_key_time_conflict',
                    $fingerprint,
                    $kid,
                );
            }
        }

        try {
            $scope = ScopeIdentity::fromArray($payload);
            $expectedHost = $this->normalizeHost($host);
            $claimedHost = $this->normalizeHost($payload['host']);
        } catch (\Throwable) {
            return ScopeTokenVerification::rejected(ScopeTokenVerification::STATUS_INVALID, 'malformed_claims', $fingerprint, $kid);
        }
        if ($scope->scopeKind !== ScopeIdentity::KIND_CHANNEL || !hash_equals($claimedHost, $payload['host'])) {
            return ScopeTokenVerification::rejected(ScopeTokenVerification::STATUS_INVALID, 'malformed_claims', $fingerprint, $kid);
        }
        foreach ($scope->toArray() as $claim => $canonicalValue) {
            if (!array_key_exists($claim, $payload) || $payload[$claim] !== $canonicalValue) {
                return ScopeTokenVerification::rejected(
                    ScopeTokenVerification::STATUS_INVALID,
                    'non_canonical_scope_claims',
                    $fingerprint,
                    $kid,
                );
            }
        }
        if (!hash_equals(self::AUDIENCE, (string)$payload['aud'])) {
            return ScopeTokenVerification::rejected(ScopeTokenVerification::STATUS_CONTEXT_CONFLICT, 'audience_mismatch', $fingerprint, $kid);
        }
        if (!hash_equals($expectedHost, $claimedHost)) {
            return ScopeTokenVerification::rejected(ScopeTokenVerification::STATUS_CONTEXT_CONFLICT, 'host_mismatch', $fingerprint, $kid);
        }
        try {
            $canonicalPayload = $this->encodeCanonicalPayload($scope, $claimedHost, $iat, $nbf, $exp);
        } catch (\Throwable) {
            return ScopeTokenVerification::rejected(
                ScopeTokenVerification::STATUS_INVALID,
                'malformed_claims',
                $fingerprint,
                $kid,
            );
        }
        if (!hash_equals($canonicalPayload, $payloadJson)) {
            return ScopeTokenVerification::rejected(
                ScopeTokenVerification::STATUS_INVALID,
                'non_canonical_payload',
                $fingerprint,
                $kid,
            );
        }
        if ($exp <= $now) {
            return ScopeTokenVerification::expired($scope, $kid, $iat, $exp, $claimedHost, $fingerprint);
        }
        return ScopeTokenVerification::valid($scope, $kid, $iat, $exp, $claimedHost, $fingerprint);
    }

    public function shouldRenew(ScopeTokenVerification $verification, ?int $now = null): bool
    {
        if (!$verification->isValid() || $verification->expiresAt === null || $verification->kid === null) {
            return false;
        }
        $now ??= time();
        if ($now <= 0 || $verification->expiresAt <= $now) {
            return false;
        }
        try {
            $active = $this->keyring->active();
        } catch (\Throwable) {
            return false;
        }
        return $verification->kid !== $active['kid']
            || ($verification->expiresAt - $now) <= self::RENEW_BEFORE_SECONDS;
    }

    public function renew(
        ScopeTokenVerification $verification,
        ScopeIdentity $trustedScope,
        string $host,
        ?int $now = null,
    ): ?string {
        $now ??= time();
        try {
            $normalizedHost = $this->normalizeHost($host);
        } catch (\Throwable) {
            return null;
        }
        if (!$verification->isValid()
            || $verification->scope === null
            || $verification->host === null
            || !hash_equals($verification->host, $normalizedHost)
            || !$verification->scope->equals($trustedScope)
            || !$this->shouldRenew($verification, $now)) {
            return null;
        }
        return $this->issue($trustedScope, $normalizedHost, $now);
    }

    private function hasExactClaims(mixed $payload): bool
    {
        if (!is_array($payload) || array_is_list($payload)) {
            return false;
        }
        $expected = [
            'aud', 'channel_code', 'context_version', 'exp', 'host', 'iat', 'nbf',
            'scope_kind', 'store_code', 'store_mode', 'website_code', 'website_id',
        ];
        $actual = array_keys($payload);
        sort($expected);
        sort($actual);
        if ($actual !== $expected) {
            return false;
        }
        foreach (['aud', 'channel_code', 'context_version', 'host', 'scope_kind', 'store_code', 'store_mode', 'website_code'] as $field) {
            if (!is_string($payload[$field])) {
                return false;
            }
        }
        return is_int($payload['website_id']);
    }

    private function encodeCanonicalPayload(
        ScopeIdentity $scope,
        string $host,
        int $issuedAt,
        int $notBefore,
        int $expiresAt,
    ): string {
        return json_encode(
            $scope->toArray() + [
                'host' => $host,
                'aud' => self::AUDIENCE,
                'iat' => $issuedAt,
                'nbf' => $notBefore,
                'exp' => $expiresAt,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    private function normalizeHost(string $host): string
    {
        $authority = strtolower(trim($host));
        if ($authority === ''
            || strlen($authority) > 261
            || preg_match('/[\x00-\x20\x7F\/\\@?#]/', $authority) === 1) {
            throw new \InvalidArgumentException(__('Scope Token Host 无效'));
        }

        if (str_starts_with($authority, '[')) {
            if (preg_match('/^\[([0-9a-f:.]+)\](?::([0-9]{1,5}))?$/D', $authority, $matches) !== 1
                || filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new \InvalidArgumentException(__('Scope Token Host 无效'));
            }
            $port = $this->normalizePort($matches[2] ?? '');
            return '[' . $matches[1] . ']' . ($port === '' ? '' : ':' . $port);
        }

        if (substr_count($authority, ':') > 1
            || preg_match('/^([^:]+)(?::([0-9]{1,5}))?$/D', $authority, $matches) !== 1) {
            throw new \InvalidArgumentException(__('Scope Token Host 无效'));
        }
        $hostname = rtrim($matches[1], '.');
        if (!$this->isValidHostname($hostname)) {
            throw new \InvalidArgumentException(__('Scope Token Host 无效'));
        }
        $port = $this->normalizePort($matches[2] ?? '');
        return $hostname . ($port === '' ? '' : ':' . $port);
    }

    private function isValidHostname(string $hostname): bool
    {
        if ($hostname === '' || strlen($hostname) > 253) {
            return false;
        }
        if (filter_var($hostname, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return true;
        }
        if (preg_match('/^[0-9.]+$/D', $hostname) === 1) {
            return false;
        }
        foreach (explode('.', $hostname) as $label) {
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label) !== 1) {
                return false;
            }
        }
        return true;
    }

    private function normalizePort(string $port): string
    {
        if ($port === '') {
            return '';
        }
        $number = (int)$port;
        if ($number < 1 || $number > 65535) {
            throw new \InvalidArgumentException(__('Scope Token Host 端口无效'));
        }
        return (string)$number;
    }

    private static function signingInput(string $kid, string $body): string
    {
        return self::DOMAIN . self::VERSION . "\0" . $kid . "\0" . $body;
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $encoded): ?string
    {
        if ($encoded === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $encoded) !== 1) {
            return null;
        }
        $padding = (4 - strlen($encoded) % 4) % 4;
        $decoded = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', $padding), true);
        if (!is_string($decoded) || !hash_equals(self::b64($decoded), $encoded)) {
            return null;
        }
        return $decoded;
    }
}
