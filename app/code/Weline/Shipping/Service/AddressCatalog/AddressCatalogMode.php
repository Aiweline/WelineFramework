<?php
declare(strict_types=1);

namespace Weline\Shipping\Service\AddressCatalog;

/**
 * catalog_mode：首次成功导入 address-catalog 后置真，Ensure 禁止再写 JSON 包。
 */
final class AddressCatalogMode
{
    private const FLAG_REL = 'var/shipping/address-catalog-mode.flag';

    public static function flagPath(): string
    {
        return BP . self::FLAG_REL;
    }

    public static function isEnabled(): bool
    {
        $path = self::flagPath();
        return is_file($path) && trim((string)@file_get_contents($path)) !== '';
    }

    public static function enable(string $reason = 'catalog_import'): void
    {
        $path = self::flagPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $path,
            json_encode([
                'enabled' => true,
                'reason' => $reason,
                'at' => date('c'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            LOCK_EX
        );
    }

    public static function disable(): void
    {
        $path = self::flagPath();
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
