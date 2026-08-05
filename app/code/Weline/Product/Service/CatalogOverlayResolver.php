<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Product\Api\ResolvedScopeValue;

/**
 * Pure overlay resolver for same-Website Store → Website fallback.
 *
 * Search order (per REQ-006 / DEC-008):
 * 1. Store scope: target locale → locale fallbacks
 * 2. Website scope (store_id=0): target locale → locale fallbacks
 * First explicit wins; first cleared returns empty and stops ALL remaining fallback.
 */
final class CatalogOverlayResolver
{
    public const WEBSITE_STORE_ID = 0;

    /**
     * @param list<array{
     *   store_id:int,
     *   locale?:string,
     *   cleared:bool,
     *   value?:mixed,
     *   is_required?:bool
     * }> $rows  Same attribute/entity rows already loaded for this website shard
     * @param list<string> $localeFallback  Ordered locales after the primary (exclude primary)
     */
    public function resolveAttribute(
        array $rows,
        int $storeId,
        string $locale = '',
        array $localeFallback = [''],
    ): ResolvedScopeValue {
        if ($storeId < 0) {
            throw new \InvalidArgumentException(__('store_id 不能为负数：%{1}', [$storeId]));
        }

        $locale = trim($locale);
        $locales = $this->localeChain($locale, $localeFallback);
        $storeLayers = $storeId === self::WEBSITE_STORE_ID
            ? [self::WEBSITE_STORE_ID]
            : [$storeId, self::WEBSITE_STORE_ID];

        $index = $this->indexRows($rows);

        foreach ($storeLayers as $layerStoreId) {
            foreach ($locales as $loc) {
                $key = $this->rowKey($layerStoreId, $loc);
                if (!isset($index[$key])) {
                    continue;
                }
                $row = $index[$key];
                if (!empty($row['cleared'])) {
                    $diagnostic = !empty($row['is_required'])
                        ? 'cleared_at_scope'
                        : 'cleared_at_scope';
                    return ResolvedScopeValue::cleared($layerStoreId, $loc, $diagnostic);
                }
                if (array_key_exists('value', $row)) {
                    return ResolvedScopeValue::explicit($row['value'], $layerStoreId, $loc);
                }
            }
        }

        return ResolvedScopeValue::unresolved();
    }

    /**
     * @param list<array{store_id:int, cleared:bool, value?:mixed}> $rows
     */
    public function resolvePrice(array $rows, int $storeId): ResolvedScopeValue
    {
        if ($storeId < 0) {
            throw new \InvalidArgumentException(__('store_id 不能为负数：%{1}', [$storeId]));
        }

        $storeLayers = $storeId === self::WEBSITE_STORE_ID
            ? [self::WEBSITE_STORE_ID]
            : [$storeId, self::WEBSITE_STORE_ID];

        $byStore = [];
        foreach ($rows as $row) {
            $byStore[(int)$row['store_id']] = $row;
        }

        foreach ($storeLayers as $layerStoreId) {
            if (!isset($byStore[$layerStoreId])) {
                continue;
            }
            $row = $byStore[$layerStoreId];
            if (!empty($row['cleared'])) {
                return ResolvedScopeValue::cleared($layerStoreId, '', 'price_cleared_at_scope');
            }
            if (array_key_exists('value', $row)) {
                return ResolvedScopeValue::explicit($row['value'], $layerStoreId);
            }
        }

        return ResolvedScopeValue::unresolved();
    }

    /**
     * Required attribute cleared at resolved scope blocks publish/sellability.
     *
     * @param list<array{
     *   store_id:int,
     *   locale?:string,
     *   cleared:bool,
     *   value?:mixed,
     *   is_required?:bool
     * }> $rows
     * @return list<string> diagnostic codes
     */
    public function publishDiagnostics(
        array $rows,
        int $storeId,
        string $locale = '',
        array $localeFallback = [''],
    ): array {
        $resolved = $this->resolveAttribute($rows, $storeId, $locale, $localeFallback);
        if (!$resolved->isCleared()) {
            return [];
        }
        $required = false;
        foreach ($rows as $row) {
            if ((int)$row['store_id'] === $resolved->resolvedStoreId
                && trim((string)($row['locale'] ?? '')) === $resolved->resolvedLocale
                && !empty($row['is_required'])
            ) {
                $required = true;
                break;
            }
        }
        return $required ? ['cleared_at_scope'] : [];
    }

    /**
     * @param list<string> $localeFallback
     * @return list<string>
     */
    private function localeChain(string $locale, array $localeFallback): array
    {
        $chain = [];
        if ($locale !== '') {
            $chain[] = $locale;
        }
        foreach ($localeFallback as $fb) {
            $fb = trim((string)$fb);
            if (!in_array($fb, $chain, true)) {
                $chain[] = $fb;
            }
        }
        if ($chain === []) {
            $chain[] = '';
        }
        return $chain;
    }

    /**
     * @param list<array{store_id:int, locale?:string, cleared:bool, value?:mixed, is_required?:bool}> $rows
     * @return array<string, array{store_id:int, locale?:string, cleared:bool, value?:mixed, is_required?:bool}>
     */
    private function indexRows(array $rows): array
    {
        $index = [];
        foreach ($rows as $row) {
            $key = $this->rowKey((int)$row['store_id'], trim((string)($row['locale'] ?? '')));
            $index[$key] = $row;
        }
        return $index;
    }

    private function rowKey(int $storeId, string $locale): string
    {
        return $storeId . "\0" . $locale;
    }
}
