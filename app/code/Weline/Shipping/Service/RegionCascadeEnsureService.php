<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Shipping\Service\AddressCatalog\AddressCatalogMode;

/**
 * 级联补齐入口（历史 JSON 包路径已移除）。
 * 权威数据仅 address-catalog tsv.gz → DB（shipping:addresscatalog:import）。
 * catalog_mode 下本服务对写路径一律 no-op。
 */
final class RegionCascadeEnsureService
{
    private const CATALOG_DIR = __DIR__ . '/../data/address-catalog';

    /**
     * 模块是否固化了该国 address-catalog（省份 TSV）。
     * 无包不等于现实无省/州，只表示本仓未收录下级区划。
     */
    public function hasPack(string $countryCode): bool
    {
        return $this->hasCatalogCoverage($countryCode);
    }

    public function hasCatalogCoverage(string $countryCode): bool
    {
        $countryCode = strtoupper(trim($countryCode));
        if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
            return false;
        }
        $path = self::CATALOG_DIR . '/' . $countryCode . '/provinces.tsv.gz';

        return is_file($path);
    }

    /**
     * @deprecated JSON region packs removed; always null.
     * @return null
     */
    public function loadPack(string $countryCode): ?array
    {
        return null;
    }

    /**
     * @return array{imported:int,skipped:bool,reason:string,country_code:string}
     */
    public function ensureCountry(string $countryCode): array
    {
        $countryCode = strtoupper(trim($countryCode));
        if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
            return ['imported' => 0, 'skipped' => true, 'reason' => 'invalid_country', 'country_code' => $countryCode];
        }

        if (defined('BP') && AddressCatalogMode::isEnabled()) {
            return [
                'imported' => 0,
                'skipped' => true,
                'reason' => 'catalog_mode',
                'country_code' => $countryCode,
            ];
        }

        // JSON 包已删除：非 catalog_mode 也不再从文件灌库。
        return [
            'imported' => 0,
            'skipped' => true,
            'reason' => $this->hasCatalogCoverage($countryCode) ? 'use_addresscatalog_import' : 'catalog_not_covered',
            'country_code' => $countryCode,
        ];
    }
}
