<?php

declare(strict_types=1);

namespace Weline\Inventory\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Model\Reservation;
use Weline\Inventory\Model\Warehouse;
use Weline\Inventory\Model\WarehouseQuota;

/**
 * Warehouse country→province→warehouse tree, immutable codes, seed & delete guards.
 */
final class WarehouseHierarchyService
{
    public const ERROR_CODE_IMMUTABLE = 'inventory_warehouse_code_immutable';
    public const ERROR_HAS_INVENTORY = 'inventory_warehouse_has_inventory';
    public const ERROR_HAS_CHILDREN = 'inventory_warehouse_has_children';

    /** @var (\Closure(): Warehouse)|null */
    private readonly ?\Closure $warehouseFactory;
    /** @var (\Closure(): WarehouseQuota)|null */
    private readonly ?\Closure $quotaFactory;
    /** @var (\Closure(): Reservation)|null */
    private readonly ?\Closure $reservationFactory;

    /**
     * @param (callable(): Warehouse)|null $warehouseFactory
     * @param (callable(): WarehouseQuota)|null $quotaFactory
     * @param (callable(): Reservation)|null $reservationFactory
     */
    public function __construct(
        ?callable $warehouseFactory = null,
        ?callable $quotaFactory = null,
        ?callable $reservationFactory = null,
        private readonly ?WarehouseAuthorizationService $authorizations = null,
    ) {
        $this->warehouseFactory = $warehouseFactory !== null ? \Closure::fromCallable($warehouseFactory) : null;
        $this->quotaFactory = $quotaFactory !== null ? \Closure::fromCallable($quotaFactory) : null;
        $this->reservationFactory = $reservationFactory !== null ? \Closure::fromCallable($reservationFactory) : null;
    }

    /**
     * Global e-commerce fulfillment hubs that commonly operate real warehouses.
     *
     * @return list<array{country_code:string,name:string,provinces:list<array{region_code:string,name:string}>}>
     */
    public static function defaultCatalog(): array
    {
        return [
            ['country_code' => 'CN', 'name' => '中国', 'provinces' => [
                ['region_code' => 'GD', 'name' => '广东'],
                ['region_code' => 'ZJ', 'name' => '浙江'],
                ['region_code' => 'JS', 'name' => '江苏'],
                ['region_code' => 'SH', 'name' => '上海'],
            ]],
            ['country_code' => 'US', 'name' => '美国', 'provinces' => [
                ['region_code' => 'CA', 'name' => '加利福尼亚'],
                ['region_code' => 'NJ', 'name' => '新泽西'],
                ['region_code' => 'TX', 'name' => '得克萨斯'],
            ]],
            ['country_code' => 'GB', 'name' => '英国', 'provinces' => []],
            ['country_code' => 'DE', 'name' => '德国', 'provinces' => []],
            ['country_code' => 'JP', 'name' => '日本', 'provinces' => []],
            ['country_code' => 'AU', 'name' => '澳大利亚', 'provinces' => []],
            ['country_code' => 'SG', 'name' => '新加坡', 'provinces' => []],
            ['country_code' => 'CA', 'name' => '加拿大', 'provinces' => []],
            ['country_code' => 'FR', 'name' => '法国', 'provinces' => []],
            ['country_code' => 'KR', 'name' => '韩国', 'provinces' => []],
            ['country_code' => 'NL', 'name' => '荷兰', 'provinces' => []],
            ['country_code' => 'AE', 'name' => '阿联酋', 'provinces' => []],
            // 对齐常见货源履约原点（含 CJ 远程仓覆盖国）
            ['country_code' => 'BR', 'name' => '巴西', 'provinces' => []],
            ['country_code' => 'ES', 'name' => '西班牙', 'provinces' => []],
            ['country_code' => 'MX', 'name' => '墨西哥', 'provinces' => []],
            ['country_code' => 'NG', 'name' => '尼日利亚', 'provinces' => []],
            ['country_code' => 'PH', 'name' => '菲律宾', 'provinces' => []],
            ['country_code' => 'RO', 'name' => '罗马尼亚', 'provinces' => []],
            ['country_code' => 'TH', 'name' => '泰国', 'provinces' => []],
            ['country_code' => 'VN', 'name' => '越南', 'provinces' => []],
        ];
    }

    public static function normalizeSegment(string $segment): string
    {
        $segment = strtoupper(trim($segment));
        $segment = preg_replace('/[^A-Z0-9]+/', '-', $segment) ?? '';
        $segment = trim($segment, '-');
        if ($segment === '' || strlen($segment) > 32) {
            throw new \InvalidArgumentException(__('仓库码片段无效'));
        }

        return $segment;
    }

    public static function buildCode(?string $parentCode, string $segment): string
    {
        $segment = self::normalizeSegment($segment);
        $parent = strtoupper(trim((string)$parentCode));
        if ($parent === '') {
            return $segment;
        }
        if (str_starts_with($segment, $parent . '-')) {
            return self::normalizeSegment($segment);
        }

        return self::normalizeSegment($parent . '-' . $segment);
    }

    public static function assertCodeImmutable(string $existingCode, string $incomingCode): void
    {
        if (strtoupper(trim($existingCode)) !== strtoupper(trim($incomingCode))) {
            throw new \InvalidArgumentException('仓库码一旦确定不可修改，请删除后重建');
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function orderAsTree(array $rows): array
    {
        $byParent = [];
        foreach ($rows as $row) {
            $parentId = (int)($row[Warehouse::schema_fields_PARENT_ID] ?? 0);
            $byParent[$parentId][] = $row;
        }
        foreach ($byParent as &$children) {
            usort($children, static function (array $a, array $b): int {
                $ka = (string)($a[Warehouse::schema_fields_WAREHOUSE_CODE] ?? '');
                $kb = (string)($b[Warehouse::schema_fields_WAREHOUSE_CODE] ?? '');
                return strcmp($ka, $kb);
            });
        }
        unset($children);

        $out = [];
        $walk = static function (int $parentId, int $depth) use (&$walk, &$out, $byParent): void {
            foreach ($byParent[$parentId] ?? [] as $row) {
                $row['_depth'] = $depth;
                $out[] = $row;
                $walk((int)($row[Warehouse::schema_fields_ID] ?? 0), $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }

    /**
     * @return array{created:int,skipped:int}
     */
    public function ensureDefaultTree(int $websiteId, string $mode = Warehouse::MODE_NORMAL): array
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException(__('website_id 不能为负'));
        }
        $created = 0;
        $skipped = 0;
        foreach (self::defaultCatalog() as $country) {
            $countryCode = self::normalizeSegment($country['country_code']);
            $countryRow = $this->findOrCreateNode(
                $websiteId,
                0,
                Warehouse::NODE_COUNTRY,
                $countryCode,
                (string)$country['name'],
                $mode,
                $countryCode,
                null,
            );
            $countryRow['_created'] ? $created++ : $skipped++;
            $countryId = (int)$countryRow[Warehouse::schema_fields_ID];
            $provinces = $country['provinces'];
            if ($provinces === []) {
                $leaf = $this->findOrCreateNode(
                    $websiteId,
                    $countryId,
                    Warehouse::NODE_WAREHOUSE,
                    self::buildCode($countryCode, 'WH01'),
                    (string)$country['name'] . ' 默认仓',
                    $mode,
                    $countryCode,
                    null,
                );
                $leaf['_created'] ? $created++ : $skipped++;
                continue;
            }
            foreach ($provinces as $index => $province) {
                $region = self::normalizeSegment($province['region_code']);
                $provinceCode = self::buildCode($countryCode, $region);
                $provinceRow = $this->findOrCreateNode(
                    $websiteId,
                    $countryId,
                    Warehouse::NODE_PROVINCE,
                    $provinceCode,
                    (string)$province['name'],
                    $mode,
                    $countryCode,
                    $region,
                );
                $provinceRow['_created'] ? $created++ : $skipped++;
                if ($index === 0) {
                    $leaf = $this->findOrCreateNode(
                        $websiteId,
                        (int)$provinceRow[Warehouse::schema_fields_ID],
                        Warehouse::NODE_WAREHOUSE,
                        self::buildCode($provinceCode, 'WH01'),
                        (string)$province['name'] . ' 默认仓',
                        $mode,
                        $countryCode,
                        $region,
                    );
                    $leaf['_created'] ? $created++ : $skipped++;
                }
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * @return array<string,mixed>
     */
    public function createChild(
        int $websiteId,
        int $parentId,
        string $nodeKind,
        string $segment,
        string $name,
        string $mode = Warehouse::MODE_NORMAL,
        string $warehouseType = Warehouse::TYPE_PHYSICAL,
        ?string $countryCode = null,
        ?string $regionCode = null,
    ): array {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException(__('website_id 不能为负'));
        }
        $nodeKind = strtolower(trim($nodeKind));
        if (!in_array($nodeKind, Warehouse::NODE_KINDS, true)) {
            throw new \InvalidArgumentException(__('仓库节点类型无效'));
        }
        $parentCode = null;
        $parentCountry = $countryCode;
        $parentRegion = $regionCode;
        if ($parentId > 0) {
            $parent = $this->loadWarehouse($parentId);
            if ($parent === null || (int)$parent[Warehouse::schema_fields_WEBSITE_ID] !== $websiteId) {
                throw new \InvalidArgumentException(__('父仓库不存在'));
            }
            $parentCode = (string)$parent[Warehouse::schema_fields_WAREHOUSE_CODE];
            $parentCountry = $parentCountry ?: (string)($parent[Warehouse::schema_fields_COUNTRY_CODE] ?? '');
            $parentRegion = $parentRegion ?: (string)($parent[Warehouse::schema_fields_REGION_CODE] ?? '');
            $parentKind = (string)($parent[Warehouse::schema_fields_NODE_KIND] ?? Warehouse::NODE_WAREHOUSE);
            if ($nodeKind === Warehouse::NODE_PROVINCE && $parentKind !== Warehouse::NODE_COUNTRY) {
                throw new \InvalidArgumentException(__('省份只能挂在国家节点下'));
            }
            if ($nodeKind === Warehouse::NODE_WAREHOUSE && !in_array($parentKind, [Warehouse::NODE_COUNTRY, Warehouse::NODE_PROVINCE], true)) {
                throw new \InvalidArgumentException(__('仓库只能挂在国家或省份节点下'));
            }
            if ($nodeKind === Warehouse::NODE_COUNTRY) {
                throw new \InvalidArgumentException(__('国家节点必须是顶级'));
            }
        } elseif ($nodeKind !== Warehouse::NODE_COUNTRY) {
            throw new \InvalidArgumentException(__('非国家节点必须指定父仓库'));
        }

        if ($nodeKind === Warehouse::NODE_COUNTRY) {
            $code = self::normalizeSegment($segment);
            $countryCode = $code;
            $regionCode = null;
        } elseif ($nodeKind === Warehouse::NODE_PROVINCE) {
            $region = self::normalizeSegment($segment);
            $code = self::buildCode($parentCode, $region);
            $countryCode = self::normalizeSegment((string)$parentCountry);
            $regionCode = $region;
        } else {
            $code = self::buildCode($parentCode, $segment);
            $countryCode = $parentCountry !== null && $parentCountry !== ''
                ? self::normalizeSegment($parentCountry)
                : null;
            $regionCode = $parentRegion !== null && $parentRegion !== ''
                ? self::normalizeSegment($parentRegion)
                : null;
        }

        $auth = $this->authorizations ?? ObjectManager::getInstance(WarehouseAuthorizationService::class);
        return $auth->createWarehouse([
            Warehouse::schema_fields_WEBSITE_ID => $websiteId,
            Warehouse::schema_fields_PARENT_ID => $parentId,
            Warehouse::schema_fields_NODE_KIND => $nodeKind,
            Warehouse::schema_fields_WAREHOUSE_CODE => $code,
            Warehouse::schema_fields_NAME => trim($name),
            Warehouse::schema_fields_MODE => $mode,
            Warehouse::schema_fields_WAREHOUSE_TYPE => $nodeKind === Warehouse::NODE_WAREHOUSE
                ? $warehouseType
                : Warehouse::TYPE_LOGICAL,
            Warehouse::schema_fields_COUNTRY_CODE => $countryCode,
            Warehouse::schema_fields_REGION_CODE => $regionCode,
            Warehouse::schema_fields_IS_DEFAULT_LOGICAL => 0,
            Warehouse::schema_fields_ENABLED => 1,
            Warehouse::schema_fields_IS_SEED => 0,
        ]);
    }

    public function deleteWarehouse(int $websiteId, int $warehouseId): void
    {
        if ($websiteId < 0 || $warehouseId <= 0) {
            throw new \InvalidArgumentException(__('删除仓库参数无效'));
        }
        $row = $this->loadWarehouse($warehouseId);
        if ($row === null || (int)$row[Warehouse::schema_fields_WEBSITE_ID] !== $websiteId) {
            throw new \InvalidArgumentException(__('仓库不存在'));
        }
        if ((int)($row[Warehouse::schema_fields_IS_SEED] ?? 0) === 1) {
            throw new \RuntimeException((string)__('系统种子仓不允许删除'));
        }
        if ($this->hasChildren($websiteId, $warehouseId)) {
            throw new \RuntimeException(__('请先删除子仓库'));
        }
        if ($this->hasBlockingInventory($websiteId, $warehouseId)) {
            throw new \RuntimeException(__('仓库仍有库存或预占，不能删除'));
        }
        $model = $this->newWarehouse();
        $model->clear()
            ->where(Warehouse::schema_fields_ID, $warehouseId)
            ->where(Warehouse::schema_fields_WEBSITE_ID, $websiteId)
            ->delete();
    }

    public function hasBlockingInventory(int $websiteId, int $warehouseId): bool
    {
        $quota = $this->newQuota();
        $quotaRows = $quota->clear()
            ->where(WarehouseQuota::schema_fields_WEBSITE_ID, $websiteId)
            ->where(WarehouseQuota::schema_fields_WAREHOUSE_ID, $warehouseId)
            ->select()
            ->fetchArray();
        foreach ((array)$quotaRows as $quotaRow) {
            if ((int)($quotaRow[WarehouseQuota::schema_fields_QTY_MINOR] ?? 0) > 0) {
                return true;
            }
        }

        $reservation = $this->newReservation();
        $reservationRows = $reservation->clear()
            ->where(Reservation::schema_fields_WEBSITE_ID, $websiteId)
            ->where(Reservation::schema_fields_WAREHOUSE_ID, $warehouseId)
            ->where(Reservation::schema_fields_STATE, Reservation::STATE_RESERVED)
            ->select()
            ->fetchArray();

        return is_array($reservationRows) && $reservationRows !== [];
    }

    /**
     * @return array<string,mixed>
     */
    private function findOrCreateNode(
        int $websiteId,
        int $parentId,
        string $nodeKind,
        string $code,
        string $name,
        string $mode,
        ?string $countryCode,
        ?string $regionCode,
    ): array {
        $existing = $this->findByCode($websiteId, $code);
        if ($existing !== null) {
            if ((int)($existing[Warehouse::schema_fields_IS_SEED] ?? 0) !== 1) {
                $this->markSeed((int)$existing[Warehouse::schema_fields_ID], $websiteId);
                $existing[Warehouse::schema_fields_IS_SEED] = 1;
            }
            $existing['_created'] = false;
            return $existing;
        }
        $auth = $this->authorizations ?? ObjectManager::getInstance(WarehouseAuthorizationService::class);
        $row = $auth->createWarehouse([
            Warehouse::schema_fields_WEBSITE_ID => $websiteId,
            Warehouse::schema_fields_PARENT_ID => $parentId,
            Warehouse::schema_fields_NODE_KIND => $nodeKind,
            Warehouse::schema_fields_WAREHOUSE_CODE => $code,
            Warehouse::schema_fields_NAME => $name,
            Warehouse::schema_fields_MODE => $mode,
            Warehouse::schema_fields_WAREHOUSE_TYPE => $nodeKind === Warehouse::NODE_WAREHOUSE
                ? Warehouse::TYPE_PHYSICAL
                : Warehouse::TYPE_LOGICAL,
            Warehouse::schema_fields_COUNTRY_CODE => $countryCode,
            Warehouse::schema_fields_REGION_CODE => $regionCode,
            Warehouse::schema_fields_IS_DEFAULT_LOGICAL => 0,
            Warehouse::schema_fields_ENABLED => 1,
            Warehouse::schema_fields_IS_SEED => 1,
        ]);
        $row['_created'] = true;
        return $row;
    }

    private function markSeed(int $warehouseId, int $websiteId): void
    {
        if ($warehouseId <= 0) {
            return;
        }
        $model = $this->newWarehouse();
        $model->clear()
            ->where(Warehouse::schema_fields_ID, $warehouseId)
            ->where(Warehouse::schema_fields_WEBSITE_ID, $websiteId)
            ->update([
                Warehouse::schema_fields_IS_SEED => 1,
                Warehouse::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
            ])
            ->fetch();
    }

    /** @return array<string,mixed>|null */
    private function findByCode(int $websiteId, string $code): ?array
    {
        $model = $this->newWarehouse();
        $rows = $model->clear()
            ->where(Warehouse::schema_fields_WEBSITE_ID, $websiteId)
            ->where(Warehouse::schema_fields_WAREHOUSE_CODE, $code)
            ->select()
            ->fetchArray();
        $row = $rows[0] ?? null;
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function loadWarehouse(int $warehouseId): ?array
    {
        $model = $this->newWarehouse();
        $rows = $model->clear()
            ->where(Warehouse::schema_fields_ID, $warehouseId)
            ->select()
            ->fetchArray();
        $row = $rows[0] ?? null;
        return is_array($row) ? $row : null;
    }

    private function hasChildren(int $websiteId, int $warehouseId): bool
    {
        $model = $this->newWarehouse();
        $rows = $model->clear()
            ->where(Warehouse::schema_fields_WEBSITE_ID, $websiteId)
            ->where(Warehouse::schema_fields_PARENT_ID, $warehouseId)
            ->limit(1)
            ->select()
            ->fetchArray();
        return is_array($rows) && $rows !== [];
    }

    private function newWarehouse(): Warehouse
    {
        return $this->warehouseFactory
            ? ($this->warehouseFactory)()
            : ObjectManager::getInstance(Warehouse::class);
    }

    private function newQuota(): WarehouseQuota
    {
        return $this->quotaFactory
            ? ($this->quotaFactory)()
            : ObjectManager::getInstance(WarehouseQuota::class);
    }

    private function newReservation(): Reservation
    {
        return $this->reservationFactory
            ? ($this->reservationFactory)()
            : ObjectManager::getInstance(Reservation::class);
    }
}
