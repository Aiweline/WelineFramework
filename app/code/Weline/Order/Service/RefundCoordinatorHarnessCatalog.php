<?php

declare(strict_types=1);

namespace Weline\Order\Service;

/**
 * E2E / DEV 退款协调状态叠加（var 文件，避 CLI/Worker 缓存隔离）。
 *
 * JSON = OrderRefundCoordinator 内存袋整包。
 */
final class RefundCoordinatorHarnessCatalog
{
    private const FILE = 'state.json';

    /**
     * @param array{
     *   orders: array<string, array<string, mixed>>,
     *   cases: array<string, array<string, mixed>>,
     *   payments: array<string, array<string, mixed>>,
     *   by_idem: array<string, string>,
     *   outbox: array<string, array<string, mixed>>,
     *   ledger: list<array<string, mixed>>,
     *   urgent: list<array<string, mixed>>,
     *   frozen_orders: array<string, true>
     * } $memory
     */
    public static function put(array $memory): void
    {
        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('unable to create refund_coordinator_harness dir');
        }
        $json = json_encode($memory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents(self::path(), $json) === false) {
            throw new \RuntimeException('unable to write refund_coordinator_harness');
        }
    }

    /**
     * @return array{
     *   orders: array<string, array<string, mixed>>,
     *   cases: array<string, array<string, mixed>>,
     *   payments: array<string, array<string, mixed>>,
     *   by_idem: array<string, string>,
     *   outbox: array<string, array<string, mixed>>,
     *   ledger: list<array<string, mixed>>,
     *   urgent: list<array<string, mixed>>,
     *   frozen_orders: array<string, true>
     * }|null
     */
    public static function load(): ?array
    {
        $path = self::path();
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['orders'], $decoded['cases'], $decoded['payments'])) {
            return null;
        }

        return [
            'orders' => is_array($decoded['orders'] ?? null) ? $decoded['orders'] : [],
            'cases' => is_array($decoded['cases'] ?? null) ? $decoded['cases'] : [],
            'payments' => is_array($decoded['payments'] ?? null) ? $decoded['payments'] : [],
            'by_idem' => is_array($decoded['by_idem'] ?? null) ? $decoded['by_idem'] : [],
            'outbox' => is_array($decoded['outbox'] ?? null) ? $decoded['outbox'] : [],
            'ledger' => is_array($decoded['ledger'] ?? null) ? array_values($decoded['ledger']) : [],
            'urgent' => is_array($decoded['urgent'] ?? null) ? array_values($decoded['urgent']) : [],
            'frozen_orders' => is_array($decoded['frozen_orders'] ?? null) ? $decoded['frozen_orders'] : [],
        ];
    }

    public static function isActive(): bool
    {
        return is_file(self::path());
    }

    public static function clear(): void
    {
        $path = self::path();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private static function dir(): string
    {
        return rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'refund_coordinator_harness';
    }

    private static function path(): string
    {
        return self::dir() . DIRECTORY_SEPARATOR . self::FILE;
    }
}
