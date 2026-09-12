<?php

declare(strict_types=1);

namespace Weline\Dropship\Controller\Backend;

use Weline\Dropship\Model\DropshipScopeWarehouseMap;
use Weline\Dropship\Service\DropshipChannelManager;
use Weline\Dropship\Service\DropshipRemoteWarehouseService;
use Weline\Dropship\Service\DropshipWarehouseMapService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Service\SystemConfigTargetScopeService;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

#[Acl('Weline_Dropship::commerce:dropship:warehouses', '仓与国家映射', 'map', '范围级仓映射', 'Weline_Dropship::commerce:dropship:group')]
class Warehouse extends BackendController
{
    #[Acl('Weline_Dropship::commerce:dropship:warehouses_index', '查看仓映射', 'map', '查看仓与国家映射')]
    public function index(): string
    {
        $resolved = $this->resolveWorkScope(false);
        $storageScope = (string)($resolved['storage_scope'] ?? 'default.__website__.default');

        /** @var DropshipChannelManager $channels */
        $channels = ObjectManager::getInstance(DropshipChannelManager::class);
        $channels->registerAllProviders();
        $providers = [];
        foreach ($channels->getProviders() as $provider) {
            $meta = $provider->getDisplayMetadata();
            $providers[] = [
                'code' => $provider->getCode(),
                'title' => (string)($meta['title'] ?? $provider->getCode()),
            ];
        }

        $providerCode = trim((string)$this->request->getGet('provider_code', ''));
        if ($providerCode === '' && $providers !== []) {
            $codes = array_map(static fn(array $p): string => (string)($p['code'] ?? ''), $providers);
            $providerCode = \in_array('cj', $codes, true) ? 'cj' : (string)($providers[0]['code'] ?? '');
        }

        /** @var ScopeIdentity $identity */
        $identity = $resolved['identity'];
        $claims = $identity->toArray();
        $websiteId = $claims['website_id'];
        $storeId = 0;
        if ($websiteId !== null) {
            $websiteId = (int)$websiteId;
            $storeId = $this->resolveMapStoreId($resolved, $websiteId);
        }

        /** @var DropshipWarehouseMapService $svc */
        $svc = ObjectManager::getInstance(DropshipWarehouseMapService::class);
        $coverage = [
            'stats' => ['remote_total' => 0, 'bound' => 0, 'gap' => 0],
            'rows' => [],
        ];
        if ($providerCode !== '' && $websiteId !== null) {
            $coverage = $svc->coverageBoard($providerCode, $websiteId, $storeId);
        }

        $gapFilter = strtolower(trim((string)$this->request->getGet('gap', 'all')));
        if (!\in_array($gapFilter, ['all', 'bound', 'gap'], true)) {
            $gapFilter = 'all';
        }
        $page = max(1, (int)$this->request->getGet('page', 1));
        $pageSize = (int)$this->request->getGet('page_size', 10);
        if ($pageSize < 5) {
            $pageSize = 5;
        }
        if ($pageSize > 50) {
            $pageSize = 50;
        }
        $filteredRows = (array)($coverage['rows'] ?? []);
        if ($gapFilter === 'bound') {
            $filteredRows = array_values(array_filter(
                $filteredRows,
                static fn(array $row): bool => !empty($row['bound']),
            ));
        } elseif ($gapFilter === 'gap') {
            $filteredRows = array_values(array_filter(
                $filteredRows,
                static fn(array $row): bool => empty($row['bound']),
            ));
        }
        $total = count($filteredRows);
        $pageCount = max(1, (int)ceil($total / $pageSize));
        if ($page > $pageCount) {
            $page = $pageCount;
        }
        $coverage['rows'] = array_slice($filteredRows, ($page - 1) * $pageSize, $pageSize);

        $providerSummaries = $svc->providerSummaries(
            $providers,
            $websiteId === null ? null : (int)$websiteId,
            $storeId,
        );

        $this->assign('page_title', __('货源履约仓对接'));
        $this->assign('providers', $providers);
        $this->assign('provider_summaries', $providerSummaries);
        $this->assign('selected_provider', $providerCode);
        $this->assign('selected_scope', $storageScope);
        $this->assign('coverage', $coverage);
        $this->assign('coverage_ready', $providerCode !== '' && $websiteId !== null);
        $this->assign('gap_filter', $gapFilter);
        $this->assign('pagination', [
            'page' => $page,
            'page_size' => $pageSize,
            'page_count' => $pageCount,
            'total' => $total,
        ]);

        return $this->fetch();
    }

    #[Acl('Weline_Dropship::commerce:dropship:warehouses_save', '保存仓映射', 'map', '保存仓与国家映射')]
    public function postSave(): string
    {
        $wantsJson = $this->wantsJsonResponse();
        $providerCode = trim((string)$this->request->getPost('provider_code', ''));
        $country = strtoupper(trim((string)$this->request->getPost('remote_country_code', '')));
        $storageId = trim((string)$this->request->getPost('remote_storage_id', ''));
        $localWarehouseId = (int)$this->request->getPost('local_warehouse_id', 0);

        $fail = function (string $message) use ($wantsJson, $providerCode): string {
            if ($wantsJson) {
                $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');

                return (string)json_encode(
                    ['success' => false, 'message' => $message],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                );
            }
            $this->getMessage()->error($message);

            return $this->redirect($this->request->getUrlBuilder()->getBackendUrl('dropship/backend/warehouse/index', [
                'provider_code' => $providerCode,
            ]));
        };

        try {
            $resolved = $this->resolveWorkScope(true);
        } catch (\Throwable $e) {
            return $fail((string)__('作用范围无效：%{1}', $e->getMessage()));
        }

        /** @var ScopeIdentity $identity */
        $identity = $resolved['identity'];
        $claims = $identity->toArray();
        $websiteId = $claims['website_id'];
        if ($websiteId === null) {
            return $fail((string)__('仓映射需要选择具体网站范围，不能使用全局'));
        }
        $websiteId = (int)$websiteId;
        $channelCode = trim((string)($claims['channel_code'] ?? ($resolved['channel_code'] ?? '')));
        if ($channelCode === 'default') {
            $channelCode = '';
        }

        try {
            $storeId = $this->resolveMapStoreId($resolved, $websiteId);
        } catch (\InvalidArgumentException $e) {
            return $fail((string)__('未找到店铺：%{1}', $e->getMessage()));
        }

        if ($providerCode === '' || $country === '' || $localWarehouseId <= 0) {
            return $fail((string)__('请选择本地仓'));
        }

        $data = [
            DropshipScopeWarehouseMap::schema_fields_PROVIDER_CODE => $providerCode,
            DropshipScopeWarehouseMap::schema_fields_WEBSITE_ID => $websiteId,
            DropshipScopeWarehouseMap::schema_fields_STORE_ID => $storeId,
            DropshipScopeWarehouseMap::schema_fields_CHANNEL => $channelCode,
            DropshipScopeWarehouseMap::schema_fields_REMOTE_COUNTRY_CODE => $country,
            DropshipScopeWarehouseMap::schema_fields_REMOTE_STORAGE_ID => $storageId,
            DropshipScopeWarehouseMap::schema_fields_LOCAL_WAREHOUSE_ID => $localWarehouseId,
            DropshipScopeWarehouseMap::schema_fields_ENABLED => 1,
            DropshipScopeWarehouseMap::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
        ];
        /** @var DropshipScopeWarehouseMap $model */
        $model = ObjectManager::getInstance(DropshipScopeWarehouseMap::class);
        $existing = $model->clear()
            ->where(DropshipScopeWarehouseMap::schema_fields_PROVIDER_CODE, $data[DropshipScopeWarehouseMap::schema_fields_PROVIDER_CODE])
            ->where(DropshipScopeWarehouseMap::schema_fields_WEBSITE_ID, $data[DropshipScopeWarehouseMap::schema_fields_WEBSITE_ID])
            ->where(DropshipScopeWarehouseMap::schema_fields_STORE_ID, $data[DropshipScopeWarehouseMap::schema_fields_STORE_ID])
            ->where(DropshipScopeWarehouseMap::schema_fields_REMOTE_COUNTRY_CODE, $data[DropshipScopeWarehouseMap::schema_fields_REMOTE_COUNTRY_CODE])
            ->find()->fetch();
        if ($existing && $existing->getId()) {
            $existing->setData($data)->save();
        } else {
            $data[DropshipScopeWarehouseMap::schema_fields_CREATED_AT] = date('Y-m-d H:i:s');
            $model->clear()->setData($data)->save();
        }

        $okMessage = (string)__('已绑定本地仓');
        if ($wantsJson) {
            $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');

            return (string)json_encode(
                [
                    'success' => true,
                    'message' => $okMessage,
                    'data' => [
                        'country_code' => $country,
                        'local_warehouse_id' => $localWarehouseId,
                        'remote_storage_id' => $storageId,
                    ],
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        }

        $this->getMessage()->success($okMessage);

        return $this->redirect($this->request->getUrlBuilder()->getBackendUrl('dropship/backend/warehouse/index', [
            'target_scope' => (string)($resolved['storage_scope'] ?? ''),
            'provider_code' => $providerCode,
        ]));
    }

    #[Acl('Weline_Dropship::commerce:dropship:warehouses_remote_search', '搜索远程仓', 'map', '按供应商搜索远程仓')]
    public function remoteSearch(): string
    {
        $providerCode = trim((string)$this->request->getGet('provider_code', ''));
        $q = trim((string)$this->request->getGet('q', ''));
        $country = strtoupper(trim((string)$this->request->getGet('country_code', '')));
        $limit = (int)$this->request->getGet('limit', 50);
        /** @var DropshipRemoteWarehouseService $svc */
        $svc = ObjectManager::getInstance(DropshipRemoteWarehouseService::class);
        $data = $svc->list($providerCode, [
            'q' => $q,
            'limit' => $limit,
            'country_code' => $country,
        ]);
        $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');

        return (string)json_encode(
            ['success' => true, 'data' => $data],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    #[Acl('Weline_Dropship::commerce:dropship:warehouses_remote_pull', '拉取远程仓', 'map', '从供应商拉取远程仓')]
    public function postRemotePull(): string
    {
        $providerCode = trim((string)$this->request->getPost('provider_code', ''));
        $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');
        if ($providerCode === '') {
            return (string)json_encode(
                [
                    'success' => false,
                    'data' => [],
                    'message' => (string)__('请先选择供应商'),
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        }
        /** @var DropshipRemoteWarehouseService $svc */
        $svc = ObjectManager::getInstance(DropshipRemoteWarehouseService::class);
        try {
            $data = $svc->pull($providerCode, []);
        } catch (\Throwable $e) {
            return (string)json_encode(
                [
                    'success' => false,
                    'data' => [],
                    'message' => (string)__('拉取失败：%{1}', $e->getMessage()),
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        }

        return (string)json_encode(
            [
                'success' => $data !== [],
                'data' => $data,
                'message' => $data === []
                    ? (string)__('未拉取到远程仓或供应商不支持')
                    : (string)__('已拉取远程仓（%{1}）', (string)count($data)),
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    #[Acl('Weline_Dropship::commerce:dropship:warehouses_sync_pairs', '按国家自动配对', 'map', '本地物理仓与远程仓按国家配对')]
    public function postSyncCountryPairs(): string
    {
        $providerCode = trim((string)$this->request->getPost('provider_code', ''));
        $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');
        if ($providerCode === '') {
            return (string)json_encode(
                [
                    'success' => false,
                    'message' => (string)__('请先选择供应商'),
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        }

        try {
            $resolved = $this->resolveWorkScope(true);
        } catch (\Throwable $e) {
            return (string)json_encode(
                [
                    'success' => false,
                    'message' => (string)__('作用范围无效：%{1}', $e->getMessage()),
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        }

        /** @var ScopeIdentity $identity */
        $identity = $resolved['identity'];
        $claims = $identity->toArray();
        $websiteId = $claims['website_id'];
        if ($websiteId === null) {
            return (string)json_encode(
                [
                    'success' => false,
                    'message' => (string)__('仓映射需要选择具体网站范围，不能使用全局'),
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        }
        $websiteId = (int)$websiteId;
        $channelCode = trim((string)($claims['channel_code'] ?? ($resolved['channel_code'] ?? '')));
        if ($channelCode === 'default') {
            $channelCode = '';
        }

        try {
            $storeId = $this->resolveMapStoreId($resolved, $websiteId);
        } catch (\InvalidArgumentException $e) {
            return (string)json_encode(
                [
                    'success' => false,
                    'message' => (string)__('未找到店铺：%{1}', $e->getMessage()),
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        }

        try {
            /** @var DropshipWarehouseMapService $svc */
            $svc = ObjectManager::getInstance(DropshipWarehouseMapService::class);
            $result = $svc->syncCountryPairs($providerCode, $websiteId, $storeId, $channelCode);
        } catch (\Throwable $e) {
            return (string)json_encode(
                [
                    'success' => false,
                    'message' => (string)__('配对失败：%{1}', $e->getMessage()),
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        }

        $created = (int)($result['created'] ?? 0);
        $updated = (int)($result['updated'] ?? 0);
        $skipped = (array)($result['skipped'] ?? []);

        return (string)json_encode(
            [
                'success' => ($created + $updated) > 0,
                'data' => $result,
                'message' => (string)__(
                    '按国家配对完成：新增 %{1}，更新 %{2}，跳过 %{3}',
                    (string)$created,
                    (string)$updated,
                    (string)count($skipped),
                ),
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * 解析工作范围：禁止把空的 website_code/store_code/channel_code 伪造成「已提交分段键」，
     * 否则会盖掉 target_scope（如 default.__website__.default）并落到 Global。
     *
     * @return array{
     *   kind:string,
     *   website_code:string,
     *   store_code:string,
     *   channel_code:string,
     *   store_mode:string,
     *   storage_scope:string,
     *   identity:ScopeIdentity
     * }
     */
    private function resolveWorkScope(bool $fromPost): array
    {
        /** @var SystemConfigTargetScopeService $targetScopeService */
        $targetScopeService = ObjectManager::getInstance(SystemConfigTargetScopeService::class);

        $target = trim((string)($fromPost
            ? $this->request->getPost('target_scope', '')
            : $this->request->getGet('target_scope', '')));
        $scope = trim((string)($fromPost
            ? $this->request->getPost('scope', '')
            : $this->request->getGet('scope', '')));

        $input = [];
        if ($target !== '') {
            $input['target_scope'] = $target;
        }
        if ($scope !== '') {
            $input['scope'] = $scope;
        }

        // 禁止写入空分段键：resolveFromInput 把「存在的空 website_code」当作 Global，会盖掉 target_scope。
        foreach (['website_code', 'store_code', 'channel_code'] as $key) {
            $raw = $fromPost ? $this->request->getPost($key, null) : $this->request->getGet($key, null);
            if ($raw === null) {
                continue;
            }
            $value = trim((string)$raw);
            if ($value === '') {
                continue;
            }
            $input[$key] = $value;
        }

        if ($input === [] && !$fromPost) {
            // 变体 A 默认落到默认网站，避免 Global 空态
            $input['target_scope'] = 'default.__website__.default';
        }

        return $targetScopeService->resolveFromInput($input, false);
    }

    /**
     * 网站级范围（含默认站）映射存 store_id=0；仅 store/channel 级才解析具体店铺 ID。
     *
     * @param array{kind?:string,store_code?:string,identity?:ScopeIdentity} $resolved
     */
    private function resolveMapStoreId(array $resolved, int $websiteId): int
    {
        $kind = strtolower(trim((string)($resolved['kind'] ?? '')));
        if ($kind === SystemConfigTargetScopeService::KIND_WEBSITE || $kind === SystemConfigTargetScopeService::KIND_GLOBAL) {
            return 0;
        }

        /** @var ScopeIdentity|null $identity */
        $identity = $resolved['identity'] ?? null;
        $storeCode = trim((string)($resolved['store_code'] ?? ''));
        if ($storeCode === '' && $identity instanceof ScopeIdentity) {
            $storeCode = trim((string)($identity->toArray()['store_code'] ?? ''));
        }
        if ($storeCode === '' || $storeCode === 'default' || $storeCode === '__store__') {
            // 网站哨兵/未选店：与库内 website 级映射对齐为 0
            if ($kind === '' || $kind === SystemConfigTargetScopeService::KIND_WEBSITE) {
                return 0;
            }
        }

        if ($kind !== SystemConfigTargetScopeService::KIND_STORE
            && $kind !== SystemConfigTargetScopeService::KIND_CHANNEL
        ) {
            return 0;
        }

        if ($storeCode === '' || $storeCode === 'default' || $storeCode === '__store__') {
            /** @var StoreCatalogInterface $storeCatalog */
            $storeCatalog = ObjectManager::getInstance(StoreCatalogInterface::class);
            $default = $storeCatalog->defaultStore($websiteId);

            return $default !== null ? (int)$default->id : 0;
        }

        /** @var StoreCatalogInterface $storeCatalog */
        $storeCatalog = ObjectManager::getInstance(StoreCatalogInterface::class);
        $summary = $storeCatalog->byCode($websiteId, $storeCode);
        if ($summary === null) {
            throw new \InvalidArgumentException($storeCode);
        }

        return (int)$summary->id;
    }

    private function wantsJsonResponse(): bool
    {
        if ((string)$this->request->getPost('ajax', '') === '1') {
            return true;
        }
        $accept = strtolower((string)$this->request->getHeader('Accept'));
        if (str_contains($accept, 'application/json')) {
            return true;
        }
        $requestedWith = strtolower((string)$this->request->getHeader('X-Requested-With'));

        return $requestedWith === 'xmlhttprequest';
    }
}
