<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Dropship\Model\DropshipScopeWarehouseMap;
use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Model\Warehouse;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;

class DropshipWarehouseMapService
{
    public const ERROR_MISSING = 'dropship_scope_warehouse_map_missing';

    /**
     * @param array<string, mixed> $map
     */
    public static function remoteCountryCode(array $map): string
    {
        return (string)($map[DropshipScopeWarehouseMap::schema_fields_REMOTE_COUNTRY_CODE] ?? '');
    }

    /**
     * @param array<string, mixed> $map
     */
    public static function remoteStorageId(array $map): string
    {
        return (string)($map[DropshipScopeWarehouseMap::schema_fields_REMOTE_STORAGE_ID] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(string $providerCode, int $websiteId, int $storeId, string $countryCode = ''): array
    {
        /** @var DropshipScopeWarehouseMap $model */
        $model = ObjectManager::getInstance(DropshipScopeWarehouseMap::class);
        $query = $model->clear()
            ->where(DropshipScopeWarehouseMap::schema_fields_PROVIDER_CODE, $providerCode)
            ->where(DropshipScopeWarehouseMap::schema_fields_WEBSITE_ID, $websiteId)
            ->where(DropshipScopeWarehouseMap::schema_fields_STORE_ID, $storeId)
            ->where(DropshipScopeWarehouseMap::schema_fields_ENABLED, 1);
        $countryCode = strtoupper(trim($countryCode));
        if ($countryCode !== '') {
            $query->where(DropshipScopeWarehouseMap::schema_fields_REMOTE_COUNTRY_CODE, $countryCode);
        }
        $row = $query->find()->fetch();

        if (!$row || !$row->getId()) {
            throw new \RuntimeException(self::ERROR_MISSING);
        }

        return $row->getData();
    }

    /**
     * 按国家把本地物理仓与远程仓一对一配对（同国家交集；缺一侧跳过）。
     *
     * @return array{created:int,updated:int,skipped:list<string>}
     */
    public function syncCountryPairs(string $providerCode, int $websiteId, int $storeId, string $channel = ''): array
    {
        $providerCode = trim($providerCode);
        if ($providerCode === '') {
            throw new \InvalidArgumentException('provider_code_required');
        }
        /** @var DropshipRemoteWarehouseService $remoteSvc */
        $remoteSvc = ObjectManager::getInstance(DropshipRemoteWarehouseService::class);
        $remotes = $remoteSvc->list($providerCode, ['limit' => 200]);
        $remoteByCountry = [];
        foreach ($remotes as $remote) {
            if (!is_array($remote)) {
                continue;
            }
            $cc = strtoupper(trim((string)($remote['country_code'] ?? '')));
            $value = trim((string)($remote['value'] ?? ''));
            if ($cc === '' || $value === '') {
                continue;
            }
            if (!isset($remoteByCountry[$cc])) {
                $remoteByCountry[$cc] = $value;
            }
        }

        /** @var Warehouse $whModel */
        $whModel = ObjectManager::getInstance(Warehouse::class);
        $locals = $whModel->clear()
            ->where(Warehouse::schema_fields_NODE_KIND, Warehouse::NODE_WAREHOUSE)
            ->where(Warehouse::schema_fields_ENABLED, 1)
            ->select()
            ->fetchArray();
        $localByCountry = [];
        if (is_array($locals)) {
            foreach ($locals as $local) {
                if (!is_array($local)) {
                    continue;
                }
                $cc = strtoupper(trim((string)($local[Warehouse::schema_fields_COUNTRY_CODE] ?? '')));
                $id = (int)($local[Warehouse::schema_fields_ID] ?? 0);
                $wid = (int)($local['website_id'] ?? 0);
                if ($cc === '' || $id <= 0) {
                    continue;
                }
                $prev = $localByCountry[$cc] ?? null;
                if ($prev === null) {
                    $localByCountry[$cc] = ['id' => $id, 'website_id' => $wid];
                    continue;
                }
                $prevWid = (int)$prev['website_id'];
                if ($wid === $websiteId && $prevWid !== $websiteId) {
                    $localByCountry[$cc] = ['id' => $id, 'website_id' => $wid];
                } elseif ($prevWid !== $websiteId && $wid === 0 && $prevWid !== 0) {
                    $localByCountry[$cc] = ['id' => $id, 'website_id' => $wid];
                }
            }
        }

        $created = 0;
        $updated = 0;
        $skipped = [];
        foreach ($remoteByCountry as $cc => $remoteId) {
            if (!isset($localByCountry[$cc])) {
                $skipped[] = $cc . ':no_local';
                continue;
            }
            $result = $this->upsertMap(
                $providerCode,
                $websiteId,
                $storeId,
                $channel,
                $cc,
                $remoteId,
                (int)$localByCountry[$cc]['id'],
            );
            if ($result === 'created') {
                ++$created;
            } else {
                ++$updated;
            }
        }
        foreach ($localByCountry as $cc => $_) {
            if (!isset($remoteByCountry[$cc])) {
                $skipped[] = $cc . ':no_remote';
            }
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * 货源覆盖看板：远程履约原点 × 当前范围本地仓绑定。
     *
     * @return array{
     *   stats:array{remote_total:int,bound:int,gap:int},
     *   rows:list<array<string,mixed>>
     * }
     */
    public function coverageBoard(string $providerCode, int $websiteId, int $storeId): array
    {
        $providerCode = trim($providerCode);
        if ($providerCode === '') {
            return [
                'stats' => ['remote_total' => 0, 'bound' => 0, 'gap' => 0],
                'rows' => [],
            ];
        }

        /** @var DropshipRemoteWarehouseService $remoteSvc */
        $remoteSvc = ObjectManager::getInstance(DropshipRemoteWarehouseService::class);
        $remotes = $remoteSvc->list($providerCode, ['limit' => 200]);
        $remoteByCountry = [];
        foreach ($remotes as $remote) {
            if (!is_array($remote)) {
                continue;
            }
            $cc = strtoupper(trim((string)($remote['country_code'] ?? '')));
            $value = trim((string)($remote['value'] ?? ''));
            $label = trim((string)($remote['label'] ?? $value));
            if ($cc === '' || $value === '') {
                continue;
            }
            if (!isset($remoteByCountry[$cc])) {
                $remoteByCountry[$cc] = [
                    'remote_id' => $value,
                    'remote_label' => $label !== '' ? $label : $value,
                ];
            }
        }

        /** @var DropshipScopeWarehouseMap $model */
        $model = ObjectManager::getInstance(DropshipScopeWarehouseMap::class);
        $maps = $model->clear()
            ->where(DropshipScopeWarehouseMap::schema_fields_PROVIDER_CODE, $providerCode)
            ->where(DropshipScopeWarehouseMap::schema_fields_WEBSITE_ID, $websiteId)
            ->where(DropshipScopeWarehouseMap::schema_fields_STORE_ID, $storeId)
            ->where(DropshipScopeWarehouseMap::schema_fields_ENABLED, 1)
            ->select()
            ->fetchArray();
        $mapByCountry = [];
        if (is_array($maps) && $maps !== []) {
            foreach ($this->enrichForDisplay($maps) as $map) {
                $cc = strtoupper(trim((string)($map[DropshipScopeWarehouseMap::schema_fields_REMOTE_COUNTRY_CODE] ?? '')));
                if ($cc === '') {
                    continue;
                }
                $mapByCountry[$cc] = $map;
            }
        }

        $rows = [];
        $bound = 0;
        foreach ($remoteByCountry as $cc => $remote) {
            $map = $mapByCountry[$cc] ?? null;
            $isBound = is_array($map);
            if ($isBound) {
                ++$bound;
            }
            $rows[] = [
                'country_code' => $cc,
                'remote_id' => (string)$remote['remote_id'],
                'remote_label' => (string)($isBound
                    ? ($map['remote_label'] ?? $remote['remote_label'])
                    : $remote['remote_label']),
                'bound' => $isBound,
                'local_warehouse_id' => $isBound ? (int)($map[DropshipScopeWarehouseMap::schema_fields_LOCAL_WAREHOUSE_ID] ?? 0) : 0,
                'local_label' => $isBound ? (string)($map['local_label'] ?? '') : '',
                'website_label' => $isBound ? (string)($map['website_label'] ?? '') : '',
                'store_label' => $isBound ? (string)($map['store_label'] ?? '') : '',
            ];
        }
        usort($rows, static fn(array $a, array $b): int => ((string)$a['country_code']) <=> ((string)$b['country_code']));
        $total = count($rows);

        return [
            'stats' => [
                'remote_total' => $total,
                'bound' => $bound,
                'gap' => max(0, $total - $bound),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @param list<array{code?:string,title?:string}> $providers
     * @return list<array{code:string,title:string,remote_total:int,bound:int,gap:int}>
     */
    public function providerSummaries(array $providers, ?int $websiteId, int $storeId = 0): array
    {
        $out = [];
        foreach ($providers as $provider) {
            if (!is_array($provider)) {
                continue;
            }
            $code = trim((string)($provider['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $title = trim((string)($provider['title'] ?? $code));
            if ($websiteId === null) {
                /** @var DropshipRemoteWarehouseService $remoteSvc */
                $remoteSvc = ObjectManager::getInstance(DropshipRemoteWarehouseService::class);
                $remoteTotal = count($remoteSvc->list($code, ['limit' => 200]));
                $out[] = [
                    'code' => $code,
                    'title' => $title !== '' ? $title : $code,
                    'remote_total' => $remoteTotal,
                    'bound' => 0,
                    'gap' => $remoteTotal,
                ];
                continue;
            }
            $board = $this->coverageBoard($code, $websiteId, $storeId);
            $stats = $board['stats'];
            $out[] = [
                'code' => $code,
                'title' => $title !== '' ? $title : $code,
                'remote_total' => (int)($stats['remote_total'] ?? 0),
                'bound' => (int)($stats['bound'] ?? 0),
                'gap' => (int)($stats['gap'] ?? 0),
            ];
        }

        return $out;
    }

    private function upsertMap(
        string $providerCode,
        int $websiteId,
        int $storeId,
        string $channel,
        string $country,
        string $remoteStorageId,
        int $localWarehouseId,
    ): string {
        $now = date('Y-m-d H:i:s');
        $data = [
            DropshipScopeWarehouseMap::schema_fields_PROVIDER_CODE => $providerCode,
            DropshipScopeWarehouseMap::schema_fields_WEBSITE_ID => $websiteId,
            DropshipScopeWarehouseMap::schema_fields_STORE_ID => $storeId,
            DropshipScopeWarehouseMap::schema_fields_CHANNEL => $channel,
            DropshipScopeWarehouseMap::schema_fields_REMOTE_COUNTRY_CODE => $country,
            DropshipScopeWarehouseMap::schema_fields_REMOTE_STORAGE_ID => $remoteStorageId,
            DropshipScopeWarehouseMap::schema_fields_LOCAL_WAREHOUSE_ID => $localWarehouseId,
            DropshipScopeWarehouseMap::schema_fields_ENABLED => 1,
            DropshipScopeWarehouseMap::schema_fields_UPDATED_AT => $now,
        ];
        /** @var DropshipScopeWarehouseMap $model */
        $model = ObjectManager::getInstance(DropshipScopeWarehouseMap::class);
        $existing = $model->clear()
            ->where(DropshipScopeWarehouseMap::schema_fields_PROVIDER_CODE, $providerCode)
            ->where(DropshipScopeWarehouseMap::schema_fields_WEBSITE_ID, $websiteId)
            ->where(DropshipScopeWarehouseMap::schema_fields_STORE_ID, $storeId)
            ->where(DropshipScopeWarehouseMap::schema_fields_REMOTE_COUNTRY_CODE, $country)
            ->find()
            ->fetch();
        if ($existing && $existing->getId()) {
            $existing->setData($data)->save();

            return 'updated';
        }
        $data[DropshipScopeWarehouseMap::schema_fields_CREATED_AT] = $now;
        $model->clear()->setData($data)->save();

        return 'created';
    }

    /**
     * 管理端列表：附带可读展示字段（不改变落库字段）。
     *
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        /** @var DropshipScopeWarehouseMap $model */
        $model = ObjectManager::getInstance(DropshipScopeWarehouseMap::class);
        $rows = $model->clear()->select()->fetchArray();
        if (!is_array($rows) || $rows === []) {
            return [];
        }
        usort($rows, static function (array $a, array $b): int {
            $wa = (int)($a[DropshipScopeWarehouseMap::schema_fields_WEBSITE_ID] ?? 0);
            $wb = (int)($b[DropshipScopeWarehouseMap::schema_fields_WEBSITE_ID] ?? 0);
            if ($wa !== $wb) {
                return $wa <=> $wb;
            }
            $sa = (int)($a[DropshipScopeWarehouseMap::schema_fields_STORE_ID] ?? 0);
            $sb = (int)($b[DropshipScopeWarehouseMap::schema_fields_STORE_ID] ?? 0);
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }
            $ca = (string)($a[DropshipScopeWarehouseMap::schema_fields_REMOTE_COUNTRY_CODE] ?? '');
            $cb = (string)($b[DropshipScopeWarehouseMap::schema_fields_REMOTE_COUNTRY_CODE] ?? '');

            return $ca <=> $cb;
        });

        return $this->enrichForDisplay($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function enrichForDisplay(array $rows): array
    {
        $providerTitles = $this->providerTitleMap();
        $websiteLabels = $this->websiteLabelMap();
        $storeLabels = $this->storeLabelMap($rows);
        $localLabels = $this->localWarehouseLabelMap($rows);
        $remoteLabels = $this->remoteWarehouseLabelMap($rows);

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $provider = trim((string)($row[DropshipScopeWarehouseMap::schema_fields_PROVIDER_CODE] ?? ''));
            $websiteId = (int)($row[DropshipScopeWarehouseMap::schema_fields_WEBSITE_ID] ?? 0);
            $storeId = (int)($row[DropshipScopeWarehouseMap::schema_fields_STORE_ID] ?? 0);
            $channel = trim((string)($row[DropshipScopeWarehouseMap::schema_fields_CHANNEL] ?? ''));
            $remoteId = trim((string)($row[DropshipScopeWarehouseMap::schema_fields_REMOTE_STORAGE_ID] ?? ''));
            $localId = (int)($row[DropshipScopeWarehouseMap::schema_fields_LOCAL_WAREHOUSE_ID] ?? 0);
            $country = strtoupper(trim((string)($row[DropshipScopeWarehouseMap::schema_fields_REMOTE_COUNTRY_CODE] ?? '')));

            $row['provider_label'] = (string)($providerTitles[$provider] ?? $provider);
            $row['website_label'] = (string)($websiteLabels[$websiteId] ?? ('#' . $websiteId));
            $row['store_label'] = (string)($storeLabels[$storeId] ?? ('#' . $storeId));
            $row['channel_label'] = $channel !== '' ? $channel : '—';
            $row['remote_label'] = (string)($remoteLabels[$provider . "\0" . $remoteId] ?? ($remoteId !== '' ? $remoteId : '—'));
            $row['local_label'] = (string)($localLabels[$localId] ?? ('#' . $localId));
            $row['country_label'] = $country !== '' ? $country : '—';
            $row[DropshipScopeWarehouseMap::schema_fields_SHIPPING_ADDRESS_ID]
                = $this->resolveShippingAddressId($websiteId, $localId);
            $out[] = $row;
        }

        return $out;
    }

    /**
     * 权威读口：仓→发货地址走 Shipping WarehouseShippingOrigin（禁止长期双写 map 列）。
     */
    public function resolveShippingAddressId(int $websiteId, int $localWarehouseId): int
    {
        $websiteId = max(0, $websiteId);
        $localWarehouseId = max(0, $localWarehouseId);
        if ($localWarehouseId <= 0) {
            return 0;
        }
        if (!interface_exists(\Weline\Shipping\Api\WarehouseShippingOriginInterface::class)) {
            return 0;
        }
        try {
            /** @var \Weline\Shipping\Api\WarehouseShippingOriginInterface $origins */
            $origins = ObjectManager::getInstance(\Weline\Shipping\Api\WarehouseShippingOriginInterface::class);
            $id = $origins->findShippingAddressId($websiteId, $localWarehouseId);

            return $id !== null ? max(0, $id) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array<string, string>
     */
    private function providerTitleMap(): array
    {
        /** @var DropshipChannelManager $channels */
        $channels = ObjectManager::getInstance(DropshipChannelManager::class);
        $channels->registerAllProviders();
        $map = [];
        foreach ($channels->getProviders() as $provider) {
            $meta = $provider->getDisplayMetadata();
            $code = $provider->getCode();
            $title = trim((string)($meta['title'] ?? $code));
            $map[$code] = $title !== '' ? $title : $code;
        }

        return $map;
    }

    /**
     * @return array<int, string>
     */
    private function websiteLabelMap(): array
    {
        /** @var WebsiteCatalogInterface $catalog */
        $catalog = ObjectManager::getInstance(WebsiteCatalogInterface::class);
        $map = [];
        foreach ($catalog->all() as $site) {
            $name = trim((string)$site->name);
            $code = trim((string)$site->code);
            $label = $name !== '' ? $name : $code;
            if ($code !== '' && $name !== '' && !str_contains($name, $code)) {
                $label .= ' (' . $code . ')';
            }
            $map[(int)$site->id] = $label !== '' ? $label : ('#' . $site->id);
        }

        return $map;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function storeLabelMap(array $rows): array
    {
        /** @var StoreCatalogInterface $catalog */
        $catalog = ObjectManager::getInstance(StoreCatalogInterface::class);
        $map = [];
        foreach ($rows as $row) {
            $storeId = (int)($row[DropshipScopeWarehouseMap::schema_fields_STORE_ID] ?? 0);
            if ($storeId < 0 || isset($map[$storeId])) {
                continue;
            }
            $summary = $catalog->byId($storeId);
            if ($summary === null) {
                $map[$storeId] = '#' . $storeId;
                continue;
            }
            $name = trim((string)($summary->name ?? ''));
            $code = trim((string)($summary->code ?? ''));
            $label = $name !== '' ? $name : $code;
            if ($code !== '' && $name !== '' && $name !== $code) {
                $label .= ' (' . $code . ')';
            }
            $map[$storeId] = $label !== '' ? $label : ('#' . $storeId);
        }

        return $map;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function localWarehouseLabelMap(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = (int)($row[DropshipScopeWarehouseMap::schema_fields_LOCAL_WAREHOUSE_ID] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        /** @var Warehouse $model */
        $model = ObjectManager::getInstance(Warehouse::class);
        $map = [];
        foreach (array_keys($ids) as $id) {
            $wh = $model->clear()->where(Warehouse::schema_fields_ID, $id)->find()->fetch();
            if (!$wh || !$wh->getId()) {
                $map[$id] = '#' . $id;
                continue;
            }
            $code = trim((string)$wh->getData(Warehouse::schema_fields_WAREHOUSE_CODE));
            $name = trim((string)$wh->getData(Warehouse::schema_fields_NAME));
            $label = $code;
            if ($name !== '') {
                $label = $code !== '' ? ($code . ' — ' . $name) : $name;
            }
            $map[$id] = $label !== '' ? $label : ('#' . $id);
        }

        return $map;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, string> key = provider\\0remoteId
     */
    private function remoteWarehouseLabelMap(array $rows): array
    {
        $byProvider = [];
        foreach ($rows as $row) {
            $provider = trim((string)($row[DropshipScopeWarehouseMap::schema_fields_PROVIDER_CODE] ?? ''));
            $remoteId = trim((string)($row[DropshipScopeWarehouseMap::schema_fields_REMOTE_STORAGE_ID] ?? ''));
            if ($provider === '' || $remoteId === '') {
                continue;
            }
            $byProvider[$provider][$remoteId] = true;
        }
        if ($byProvider === []) {
            return [];
        }
        /** @var DropshipRemoteWarehouseService $remote */
        $remote = ObjectManager::getInstance(DropshipRemoteWarehouseService::class);
        $map = [];
        foreach ($byProvider as $provider => $ids) {
            $list = $remote->list($provider, ['limit' => 100]);
            foreach ($list as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $value = trim((string)($item['value'] ?? ''));
                if ($value === '' || empty($ids[$value])) {
                    continue;
                }
                $label = trim((string)($item['label'] ?? $value));
                $map[$provider . "\0" . $value] = $label !== '' ? $label : $value;
            }
            foreach (array_keys($ids) as $missing) {
                $key = $provider . "\0" . $missing;
                if (!isset($map[$key])) {
                    $map[$key] = $missing;
                }
            }
        }

        return $map;
    }
}
