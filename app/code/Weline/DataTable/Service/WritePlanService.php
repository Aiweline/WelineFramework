<?php

declare(strict_types=1);

namespace Weline\DataTable\Service;

use Weline\Framework\App\Exception;

/** Issues and consumes short-lived, one-time write-plan tokens. */
final class WritePlanService
{
    private const TTL_SECONDS = 300;
    private const CACHE_PREFIX = 'datatable_write_plan_v1_';

    /** @return array<string,mixed> */
    public function issue(array $payload, array $plan): array
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = time() + self::TTL_SECONDS;
        w_cache('default')->set($this->cacheKey($token), [
            'digest' => $this->payloadDigest($payload),
            'expires_at' => $expiresAt,
            'plan' => $plan,
        ], self::TTL_SECONDS);

        return $plan + [
            'plan_token' => $token,
            'expires_at' => gmdate('c', $expiresAt),
            'expires_in' => self::TTL_SECONDS,
        ];
    }

    /** @return array<string,mixed> */
    public function consume(string $token, array $payload): array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            throw new Exception(__('DataTable write plan token is invalid.'));
        }
        $key = $this->cacheKey($token);
        $stored = w_cache('default')->get($key);
        w_cache('default')->delete($key);
        if (!is_array($stored) || (int)($stored['expires_at'] ?? 0) < time()) {
            throw new Exception(__('DataTable write plan expired or was already used.'));
        }
        $expected = (string)($stored['digest'] ?? '');
        if ($expected === '' || !hash_equals($expected, $this->payloadDigest($payload))) {
            throw new Exception(__('DataTable write payload no longer matches the confirmed plan.'));
        }

        return is_array($stored['plan'] ?? null) ? $stored['plan'] : [];
    }

    public function payloadDigest(array $payload): string
    {
        $canonical = $this->canonicalize($payload);
        $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        return hash('sha256', $json);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }

    private function cacheKey(string $token): string
    {
        return self::CACHE_PREFIX . hash('sha256', $token);
    }
}
