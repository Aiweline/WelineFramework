<?php

declare(strict_types=1);

namespace Weline\Inventory\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Model\Warehouse;

/**
 * 后台本地仓搜索（供 Taglib / theme:search-select）。
 */
final class WarehouseSelectSearchService
{
    /**
     * @return list<array{value:string,label:string}>
     */
    public function search(string $q, ?int $websiteId = null, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        /** @var Warehouse $model */
        $model = ObjectManager::getInstance(Warehouse::class);
        $model->clear()->where(Warehouse::schema_fields_ENABLED, 1);
        // website_id=0 合法；仅显式传入时按站过滤。
        if ($websiteId !== null) {
            $model->where(Warehouse::schema_fields_WEBSITE_ID, max(0, $websiteId));
        }
        $rows = $model->order(Warehouse::schema_fields_ID, 'ASC')->limit(500)->select()->fetchArray();
        if (!is_array($rows)) {
            return [];
        }
        $needle = mb_strtolower(trim($q), 'UTF-8');
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string)(int)($row[Warehouse::schema_fields_ID] ?? 0);
            if ($id === '0') {
                continue;
            }
            $code = trim((string)($row[Warehouse::schema_fields_WAREHOUSE_CODE] ?? ''));
            $name = trim((string)($row[Warehouse::schema_fields_NAME] ?? ''));
            $label = $code !== '' ? ($code . ($name !== '' ? ' — ' . $name : '')) : ($name !== '' ? $name : $id);
            if ($needle !== '') {
                $hay = mb_strtolower($id . ' ' . $code . ' ' . $name . ' ' . $label, 'UTF-8');
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
