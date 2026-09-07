<?php
declare(strict_types=1);

namespace Weline\Shipping\Service\AddressCatalog;

final class AddressCatalogPaths
{
    public static function catalogRoot(): string
    {
        return dirname(__DIR__, 2) . '/data/address-catalog';
    }

    public static function manifestPath(): string
    {
        return self::catalogRoot() . '/MANIFEST.json';
    }

    public static function countriesPath(): string
    {
        return self::catalogRoot() . '/countries.tsv.gz';
    }

    public static function countryDir(string $countryCode): string
    {
        return self::catalogRoot() . '/' . strtoupper($countryCode);
    }

    public static function layerFile(string $countryCode, string $layer): string
    {
        return self::countryDir($countryCode) . '/' . $layer . '.tsv.gz';
    }
}
