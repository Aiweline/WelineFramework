<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Websites\Api\ScopeMaintenanceRepositoryInterface;

/**
 * Signed, durable, generation-bound maintenance preview tokens.
 */
final class MaintenancePreviewTokenService
{
    public const VERSION = 'v1';
    public const AUDIENCE = 'weline.maintenance.preview.v1';
    public const TTL_SECONDS = 300;
    public const CLOCK_SKEW_SECONDS = 60;

    private const PREFIX = 'mpt';
    private const DOMAIN = "weline-maintenance-preview\0";
    private const MAX_TOKEN_BYTES = 4096;
    private const KID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D';

    public function __construct(
        private readonly ScopeTokenKeyring $keyring,
        private readonly ScopeMaintenanceRepositoryInterface $repository,
    ) {
    }

    public function issue(
        ScopeIdentity $scope,
        ?int $ttl = null,
        ?int $now = null,
        string $actor = 'system',
    ): string {
        $now ??= time();
        $ttl ??= self::TTL_SECONDS;
        if ($now < 1 || $ttl < 30 || $ttl > 3600 || $now > PHP_INT_MAX - $ttl) {
            throw new \InvalidArgumentException('maintenance_preview_ttl_invalid');
        }
        $state = $this->repository->status($scope);
        if (!$state['enabled'] || $state['generation'] < 1) {
            throw new \RuntimeException('maintenance_preview_not_available');
        }
        $active = $this->keyring->active();
        $tokenId = self::b64(random_bytes(16));
        $expiresAt = $now + $ttl;
        $body = self::b64($this->canonicalPayload(
            $scope,
            $tokenId,
            $state['generation'],
            $now,
            $expiresAt,
        ));
        $signature = self::b64(hash_hmac(
            'sha256',
            $this->signingInput($active['kid'], $body),
            $active['secret'],
            true,
        ));
        $token = self::PREFIX . '.' . self::VERSION . '.' . $active['kid'] . '.' . $body . '.' . $signature;
        $this->repository->registerToken(
            $scope,
            hash('sha256', $token),
            $active['kid'],
            $state['generation'],
            $now,
            $expiresAt,
            $actor,
        );
        return $token;
    }

    public function verify(string $token, ScopeIdentity $scope, ?int $now = null): bool
    {
        $now ??= time();
        if ($now < 1
            || $token === ''
            || $token !== trim($token)
            || strlen($token) > self::MAX_TOKEN_BYTES) {
            return false;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 5
            || $parts[0] !== self::PREFIX
            || $parts[1] !== self::VERSION) {
            return false;
        }
        [, , $kid, $body, $signature] = $parts;
        $signatureBytes = self::unb64($signature);
        $payloadJson = self::unb64($body);
        if (preg_match(self::KID_PATTERN, $kid) !== 1
            || !is_string($signatureBytes)
            || strlen($signatureBytes) !== 32
            || !is_string($payloadJson)) {
            return false;
        }

        $key = $this->keyring->verification($kid, $now);
        if ($key === null) {
            return false;
        }
        $expected = self::b64(hash_hmac(
            'sha256',
            $this->signingInput($kid, $body),
            $key['secret'],
            true,
        ));
        if (!hash_equals($expected, $signature)) {
            return false;
        }

        try {
            $claims = json_decode($payloadJson, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return false;
        }
        if (!$this->hasExactClaims($claims)) {
            return false;
        }
        $issuedAt = $claims['iat'];
        $expiresAt = $claims['exp'];
        $generation = $claims['generation'];
        if (!is_int($issuedAt)
            || !is_int($expiresAt)
            || !is_int($generation)
            || $issuedAt < 1
            || $generation < 1
            || $issuedAt > $now + self::CLOCK_SKEW_SECONDS
            || $expiresAt <= $issuedAt
            || $expiresAt - $issuedAt > 3600
            || $expiresAt <= $now
            || $claims['readonly'] !== true
            || !is_string($claims['token_id'])
            || preg_match('/^[A-Za-z0-9_-]{22}$/D', $claims['token_id']) !== 1) {
            return false;
        }
        if ($key['status'] === 'verify_only'
            && ($issuedAt > $key['signing_not_after'] || $expiresAt > $key['verify_until'])) {
            return false;
        }
        try {
            $claimedScope = ScopeIdentity::fromArray($claims);
        } catch (\Throwable) {
            return false;
        }
        if (!$claimedScope->equals($scope)
            || !hash_equals(self::AUDIENCE, $claims['aud'])
            || !hash_equals(
                $this->canonicalPayload(
                    $claimedScope,
                    $claims['token_id'],
                    $generation,
                    $issuedAt,
                    $expiresAt,
                ),
                $payloadJson,
            )) {
            return false;
        }

        $state = $this->repository->status($scope);
        if (!$state['enabled'] || $state['generation'] !== $generation) {
            return false;
        }
        $tokenHash = hash('sha256', $token);
        $row = $this->repository->tokenStatus($tokenHash);
        return $row !== null
            && hash_equals($scope->canonicalKey(), $row['scope_key'])
            && hash_equals($tokenHash, $row['token_hash'])
            && hash_equals($kid, $row['kid'])
            && $row['generation'] === $generation
            && $row['issued_at'] === $issuedAt
            && $row['expires_at'] === $expiresAt
            && !$row['revoked'];
    }

    public function revoke(string $token, ?int $now = null, string $actor = 'system'): bool
    {
        if ($token === '' || $token !== trim($token) || strlen($token) > self::MAX_TOKEN_BYTES) {
            return false;
        }
        return $this->repository->revokeToken(
            hash('sha256', $token),
            $now ?? time(),
            $actor,
        );
    }

    public function revokeAllForScope(
        ScopeIdentity $scope,
        ?int $now = null,
        string $actor = 'system',
    ): void {
        $this->repository->revokeAllForScope($scope, $now ?? time(), $actor);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function auditForScope(ScopeIdentity $scope): array
    {
        return $this->repository->auditForScope($scope);
    }

    private function canonicalPayload(
        ScopeIdentity $scope,
        string $tokenId,
        int $generation,
        int $issuedAt,
        int $expiresAt,
    ): string {
        return json_encode(
            $scope->toArray() + [
                'aud' => self::AUDIENCE,
                'token_id' => $tokenId,
                'generation' => $generation,
                'readonly' => true,
                'iat' => $issuedAt,
                'exp' => $expiresAt,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    private function signingInput(string $kid, string $body): string
    {
        return self::DOMAIN . self::VERSION . "\0" . $kid . "\0" . $body;
    }

    private function hasExactClaims(mixed $claims): bool
    {
        if (!is_array($claims) || array_is_list($claims)) {
            return false;
        }
        $expected = [
            'aud', 'channel_code', 'context_version', 'exp', 'generation', 'iat',
            'readonly', 'scope_kind', 'store_code', 'store_mode', 'token_id',
            'website_code', 'website_id',
        ];
        $actual = array_keys($claims);
        sort($expected);
        sort($actual);
        return $actual === $expected
            && is_string($claims['aud'])
            && is_string($claims['scope_kind'])
            && is_string($claims['context_version']);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $encoded): string|false
    {
        if ($encoded === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $encoded) !== 1) {
            return false;
        }
        $padding = (4 - strlen($encoded) % 4) % 4;
        $raw = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', $padding), true);
        if (!is_string($raw) || !hash_equals(self::b64($raw), $encoded)) {
            return false;
        }
        return $raw;
    }
}
