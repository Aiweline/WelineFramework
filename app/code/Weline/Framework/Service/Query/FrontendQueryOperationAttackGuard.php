<?php
declare(strict_types=1);

namespace Weline\Framework\Service\Query;

use Weline\Framework\Env\WelineEnv;

/**
 * Enforces per-operation attack / rate-limit rules declared on Query descriptors.
 */
final class FrontendQueryOperationAttackGuard
{
    private const STORE_DIR = BP . 'var' . DS . 'cache' . DS . 'frontend_worker' . DS . 'operation_attack' . DS;

    /**
     * @param array<string, mixed> $descriptor
     */
    public function assertAllowed(string $provider, string $operation, array $descriptor): void
    {
        $attack = \is_array($descriptor['attack'] ?? null) ? $descriptor['attack'] : null;
        if ($attack === null || ($attack['enabled'] ?? true) === false) {
            return;
        }

        [$max, $window] = $this->parseRateLimit((string)($attack['rate_limit'] ?? ''));
        if ($max < 1 || $window < 1.0) {
            return;
        }

        $subject = WelineEnv::getClientIp();
        if ($subject === '' || $subject === '0.0.0.0') {
            $subject = 'unknown';
        }

        $key = \hash('sha256', $provider . '.' . $operation . '|' . $subject);
        $now = \microtime(true);
        $cutoff = $now - $window;
        $timestamps = \array_values(\array_filter(
            $this->readTimestamps($key),
            static fn (float $timestamp): bool => $timestamp >= $cutoff,
        ));

        if (\count($timestamps) >= $max) {
            $challenge = \strtolower(\trim((string)($attack['challenge'] ?? 'managed')));
            throw new FrontendQueryException(
                $challenge === 'human' ? 'operation_attack_detected' : 'operation_rate_limited',
                (string)__('请求过于频繁，请稍后再试。'),
                429,
            );
        }

        $timestamps[] = $now;
        $this->writeTimestamps($key, $timestamps);
    }

    /**
     * @return array{0:int,1:float}
     */
    private function parseRateLimit(string $raw): array
    {
        if (!\preg_match('/^(\d+)\/(\d+)([smhd])?$/', \trim($raw), $matches)) {
            return [0, 0.0];
        }

        $max = (int)$matches[1];
        $amount = (int)$matches[2];
        $window = match ($matches[3] ?? 's') {
            's' => (float)$amount,
            'm' => (float)($amount * 60),
            'h' => (float)($amount * 3600),
            'd' => (float)($amount * 86400),
            default => (float)($amount * 60),
        };

        return [$max, \max(1.0, $window)];
    }

    /**
     * @return list<float>
     */
    private function readTimestamps(string $key): array
    {
        $path = self::STORE_DIR . $key . '.json';
        if (!\is_file($path)) {
            return [];
        }

        $raw = @\file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }

        try {
            $decoded = \json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!\is_array($decoded)) {
            return [];
        }

        $timestamps = [];
        foreach ($decoded as $value) {
            if (\is_numeric($value)) {
                $timestamps[] = (float)$value;
            }
        }

        return $timestamps;
    }

    /**
     * @param list<float> $timestamps
     */
    private function writeTimestamps(string $key, array $timestamps): void
    {
        if (!\is_dir(self::STORE_DIR) && !@\mkdir(self::STORE_DIR, 0775, true) && !\is_dir(self::STORE_DIR)) {
            return;
        }

        $path = self::STORE_DIR . $key . '.json';
        try {
            @\file_put_contents($path, \json_encode($timestamps, JSON_THROW_ON_ERROR), LOCK_EX);
        } catch (\JsonException) {
            // Best-effort guard storage; missing persistence must not break reads.
        }
    }
}
