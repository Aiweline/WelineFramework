<?php

declare(strict_types=1);

namespace Weline\Product\Service;

/**
 * Deterministic EN→zh_Hans_CN catalog title localizer for FCDC dirt-bike SKUs.
 * Keeps brand/model/displacement tokens; only translates product-type phrases.
 */
final class ProductCatalogNameTranslator
{
    /** @var array<string, string> */
    private const PHRASE_MAP = [
        'Two-Stroke Dirt Bike' => '二冲程越野摩托',
        'Gasoline Dirt Bike' => '汽油越野摩托',
        'Electric Dirt Bike' => '电动越野摩托',
        'Gasoline Pit Bike' => '汽油迷你越野车',
        'Electric Pit Bike' => '电动迷你越野车',
        'Off-road Motorcycles' => '越野摩托车',
        'Gasoline ATVs' => '汽油全地形车',
        'Electric Dirt Bike Wholesale' => '电动越野摩托批发',
        'Gasoline Dirt Bike Supplier' => '汽油越野摩托供应商',
        'High-performance off road motor cycle' => '高性能越野摩托',
        'Cradle Version' => '摇篮架版',
    ];

    public function toZhHans(string $englishName): string
    {
        $name = trim($englishName);
        if ($name === '') {
            return '';
        }

        $translated = $name;
        foreach (self::PHRASE_MAP as $english => $chinese) {
            $translated = str_ireplace($english, $chinese, $translated);
        }

        return trim(preg_replace('/\s{2,}/u', ' ', $translated) ?? $translated);
    }

    public function needsTranslation(string $englishName, string $candidateZh): bool
    {
        $englishName = trim($englishName);
        $candidateZh = trim($candidateZh);
        if ($englishName === '' || $candidateZh === '' || $candidateZh === $englishName) {
            return true;
        }

        return $candidateZh !== $this->toZhHans($englishName);
    }
}
