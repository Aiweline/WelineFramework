<?php

declare(strict_types=1);

namespace Weline\Inventory\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Model\Warehouse;

/**
 * Display aliases for warehouse English codes (node_kind / mode / warehouse_type).
 * Prefers DB dictionary {@see WarehouseCodeLabelAdminService}; falls back to compile-time MAP.
 */
final class WarehouseCodeAlias
{
    /** @var array<string, array<string, string>> */
    private const MAP = [
        'node_kind' => [
            Warehouse::NODE_COUNTRY => '国家',
            Warehouse::NODE_PROVINCE => '省份',
            Warehouse::NODE_WAREHOUSE => '仓库',
        ],
        'mode' => [
            Warehouse::MODE_NORMAL => '正式',
            Warehouse::MODE_TEST => '测试',
        ],
        'warehouse_type' => [
            Warehouse::TYPE_LOGICAL => '逻辑仓',
            Warehouse::TYPE_PHYSICAL => '物理仓',
        ],
    ];

    public static function alias(string $group, string $code): string
    {
        $group = strtolower(trim($group));
        $code = strtolower(trim($code));
        try {
            $svc = ObjectManager::getInstance(WarehouseCodeLabelAdminService::class);
            $label = trim($svc->labelFor($group, $code));
            if ($label !== '' && strcasecmp($label, $code) !== 0) {
                return $label;
            }
        } catch (\Throwable) {
            // Fall back to static map.
        }
        $alias = self::MAP[$group][$code] ?? '';

        return $alias !== '' ? $alias : $code;
    }

    public static function label(string $group, string $code): string
    {
        $group = strtolower(trim($group));
        $code = strtolower(trim($code));
        try {
            $svc = ObjectManager::getInstance(WarehouseCodeLabelAdminService::class);
            $fromDb = trim($svc->labelFor($group, $code));
            if ($fromDb !== '' && strcasecmp($fromDb, $code) !== 0) {
                return $fromDb;
            }
        } catch (\Throwable) {
            // Fall back.
        }
        $fallback = self::MAP[$group][$code] ?? '';
        if ($fallback === '') {
            return $code;
        }

        return (string)\__($fallback);
    }

    /**
     * @return array{label:string,code:string}
     */
    public static function pair(string $group, string $code): array
    {
        $code = strtolower(trim($code));

        return [
            'label' => self::label($group, $code),
            'code' => $code,
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function defaultMap(): array
    {
        return self::MAP;
    }
}
