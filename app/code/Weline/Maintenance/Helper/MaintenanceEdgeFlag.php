<?php

declare(strict_types=1);

namespace Weline\Maintenance\Helper;

/**
 * Edge-visible maintenance flag for managed Nginx (and other zero-DB gates).
 *
 * When present, Nginx skips storefront html-disk FPC HIT and returns 418 → origin,
 * so FPM and WLS both serve the same published pub/errors/maintenance pages.
 */
final class MaintenanceEdgeFlag
{
    public const RELATIVE_PATH = 'var/maintenance/edge.on';

    public static function absolutePath(): string
    {
        $bp = \defined('BP') ? (string)BP : '';

        return \rtrim($bp, "/\\") . \DIRECTORY_SEPARATOR
            . \str_replace('/', \DIRECTORY_SEPARATOR, self::RELATIVE_PATH);
    }

    public static function isOn(): bool
    {
        return \is_file(self::absolutePath());
    }

    public static function enable(): bool
    {
        $path = self::absolutePath();
        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            return false;
        }

        return @\file_put_contents($path, "1\n") !== false;
    }

    public static function disable(): bool
    {
        $path = self::absolutePath();
        if (!\is_file($path)) {
            return true;
        }

        return @\unlink($path);
    }
}
