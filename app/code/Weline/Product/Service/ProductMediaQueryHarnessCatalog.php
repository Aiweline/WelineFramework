<?php

declare(strict_types=1);

namespace Weline\Product\Service;

/**
 * E2E / DEV Product Media COW 夹具标记（var 文件，避 CLI/Worker 缓存隔离）。
 *
 * @phpstan-type HarnessState array{
 *   run_id: string,
 *   website_id: int,
 *   product_ids: list<int>,
 *   media_ids: list<int>
 * }
 */
final class ProductMediaQueryHarnessCatalog
{
    private const FILE = 'state.json';

    /**
     * @param HarnessState $state
     */
    public static function put(array $state): void
    {
        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('unable to create product_media_query_harness dir');
        }
        $payload = [
            'run_id' => trim((string)($state['run_id'] ?? '')),
            'website_id' => (int)($state['website_id'] ?? 0),
            'product_ids' => array_values(array_map('intval', is_array($state['product_ids'] ?? null) ? $state['product_ids'] : [])),
            'media_ids' => array_values(array_map('intval', is_array($state['media_ids'] ?? null) ? $state['media_ids'] : [])),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents(self::path(), $json) === false) {
            throw new \RuntimeException('unable to write product_media_query_harness');
        }
    }

    /**
     * @return HarnessState|null
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
        if (!is_array($decoded)) {
            return null;
        }

        return [
            'run_id' => (string)($decoded['run_id'] ?? ''),
            'website_id' => (int)($decoded['website_id'] ?? 0),
            'product_ids' => array_values(array_map('intval', is_array($decoded['product_ids'] ?? null) ? $decoded['product_ids'] : [])),
            'media_ids' => array_values(array_map('intval', is_array($decoded['media_ids'] ?? null) ? $decoded['media_ids'] : [])),
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
        return rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'product_media_query_harness';
    }

    private static function path(): string
    {
        return self::dir() . DIRECTORY_SEPARATOR . self::FILE;
    }
}
