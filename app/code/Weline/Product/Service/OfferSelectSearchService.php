<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Shard\Offer;

/**
 * 后台 Offer 搜索（供 Taglib / theme:search-select）。
 */
final class OfferSelectSearchService
{
    /**
     * @return list<array{value:string,label:string}>
     */
    public function search(string $q, int $websiteId, int $limit = 30): array
    {
        if ($websiteId < 0) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        try {
            /** @var Offer $model */
            $model = ObjectManager::create(Offer::class, [], false);
            $model->forWebsite($websiteId);
            $rows = $model->clear()
                ->order(Offer::schema_fields_ID, 'ASC')
                ->limit(500)
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($rows)) {
            return [];
        }
        $needle = mb_strtolower(trim($q), 'UTF-8');
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string)(int)($row[Offer::schema_fields_ID] ?? 0);
            if ($id === '0') {
                continue;
            }
            $sku = trim((string)($row[Offer::schema_fields_SKU] ?? ''));
            $status = trim((string)($row[Offer::schema_fields_STATUS] ?? ''));
            $label = $sku !== ''
                ? ('#' . $id . ' ' . $sku . ($status !== '' ? ' · ' . $status : ''))
                : ('#' . $id . ($status !== '' ? ' · ' . $status : ''));
            if ($needle !== '') {
                $hay = mb_strtolower($id . ' ' . $sku . ' ' . $status . ' ' . $label, 'UTF-8');
                if (!str_contains($hay, $needle)) {
                    continue;
                }
            }
            $out[] = ['value' => $id, 'label' => $label];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
