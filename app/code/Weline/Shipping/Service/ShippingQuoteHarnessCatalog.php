<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

/**
 * E2E / DEV 运费 Quote 费率叠加（var 文件，避 CLI/Worker 缓存隔离）。
 *
 * JSON: { "config_version": "1", "rates": { "std": { "amount_minor": 1500, "label": "Standard", "currencies": ["CNY"] } } }
 */
final class ShippingQuoteHarnessCatalog
{
    private const FILE = 'shipping_quote_harness.json';

    /**
     * @param array<string, array{amount_minor:int,label?:string,currencies?:list<string>}> $rates
     */
    public static function put(array $rates, string $configVersion = '1'): void
    {
        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('unable to create shipping_quote_harness dir');
        }
        $payload = [
            'config_version' => trim($configVersion) !== '' ? trim($configVersion) : '1',
            'rates' => $rates,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents(self::path(), $json) === false) {
            throw new \RuntimeException('unable to write shipping_quote_harness');
        }
    }

    /**
     * @return array{config_version:string,rates:array<string,array{amount_minor:int,label?:string,currencies?:list<string>}>}|null
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
        $rates = is_array($decoded['rates'] ?? null) ? $decoded['rates'] : [];
        if ($rates === []) {
            return null;
        }

        return [
            'config_version' => (string)($decoded['config_version'] ?? '1'),
            'rates' => $rates,
        ];
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
        return rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'shipping_quote_harness';
    }

    private static function path(): string
    {
        return self::dir() . DIRECTORY_SEPARATOR . self::FILE;
    }
}
