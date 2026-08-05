<?php

declare(strict_types=1);

namespace Weline\Cart\Service;

/**
 * E2E / DEV 用 Offer 目录叠加。
 * 使用 var 文件而非 w_cache，避免 CLI 夹具与 WLS Worker 缓存命名空间隔离。
 */
final class CartV2HarnessCatalog
{
    private const DIR_NAME = 'cart_v2_harness';

    /**
     * @param array<string, mixed> $row
     */
    public static function put(string $globalOfferUuid, array $row): void
    {
        $uuid = trim($globalOfferUuid);
        if ($uuid === '') {
            throw new \InvalidArgumentException('global_offer_uuid required');
        }
        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('unable to create cart_v2_harness dir');
        }
        $path = self::pathFor($uuid);
        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($path, $json) === false) {
            throw new \RuntimeException('unable to write harness offer ' . $uuid);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $globalOfferUuid): ?array
    {
        $uuid = trim($globalOfferUuid);
        if ($uuid === '') {
            return null;
        }
        $path = self::pathFor($uuid);
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public static function delete(string $globalOfferUuid): void
    {
        $uuid = trim($globalOfferUuid);
        if ($uuid === '') {
            return;
        }
        $path = self::pathFor($uuid);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private static function dir(): string
    {
        return rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . self::DIR_NAME;
    }

    private static function pathFor(string $uuid): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $uuid) ?? '';
        if ($safe === '') {
            $safe = hash('sha256', $uuid);
        }

        return self::dir() . DIRECTORY_SEPARATOR . $safe . '.json';
    }
}
