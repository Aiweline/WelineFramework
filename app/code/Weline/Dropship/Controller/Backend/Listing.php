<?php

declare(strict_types=1);

namespace Weline\Dropship\Controller\Backend;

use Weline\Dropship\Api\Data\DropshipCatalogSnapshot;
use Weline\Dropship\Interface\DropshipCatalogBrowseProviderInterface;
use Weline\Dropship\Interface\DropshipCatalogProviderInterface;
use Weline\Dropship\Model\DropshipListing;
use Weline\Dropship\Service\DropshipChannelManager;
use Weline\Dropship\Service\DropshipCatalogCategoryCacheService;
use Weline\Dropship\Service\DropshipFacade;
use Weline\Dropship\Service\DropshipListedLocalDetailService;
use Weline\Dropship\Service\DropshipListingDeleteService;
use Weline\Dropship\Service\DropshipListingDraftService;
use Weline\Dropship\Service\DropshipPricingService;
use Weline\Dropship\Service\DropshipPublishService;
use Weline\Dropship\Service\DropshipSettings;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\State;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Inventory\Model\Warehouse;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Model\Shard\Product;
use Weline\SystemConfig\Service\SystemConfigTargetScopeService;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

#[Acl('Weline_Dropship::commerce:dropship:listings', '货源商品', 'tag', '货源商品管理', 'Weline_Dropship::commerce:dropship:group')]
class Listing extends BackendController
{
    #[Acl('Weline_Dropship::commerce:dropship:listings_index', '查看货源商品', 'tag', '查看货源商品')]
    public function index(): string
    {
        $tab = strtolower(trim((string)$this->request->getGet('tab', 'listed')));
        if ($tab === 'search') {
            $tab = 'pick';
        }
        if (!in_array($tab, ['listed', 'pick'], true)) {
            $tab = 'listed';
        }

        /** @var DropshipChannelManager $channels */
        $channels = ObjectManager::getInstance(DropshipChannelManager::class);
        $channels->registerAllProviders();
        $providerOptions = $this->providerOptions($channels);

        $this->assign('page_title', __('货源商品'));
        $this->assign('active_tab', $tab);
        $this->assign('provider_options', $providerOptions);
        $this->assignPublishScopeSelects();

        if ($tab === 'pick') {
            $this->assignPickState($channels, $providerOptions);
        } else {
            $this->assignListedState($providerOptions);
        }

        return $this->fetch();
    }

    #[Acl('Weline_Dropship::commerce:dropship:listings_search', '搜索远程货源', 'tag', '搜索远程货源商品')]
    public function search(): string
    {
        $params = [
            'tab' => 'pick',
            'provider_code' => (string)$this->request->getGet('provider_code', $this->request->getGet('provider', '')),
            'q' => (string)$this->request->getGet('q', ''),
            'country_code' => (string)$this->request->getGet('country_code', ''),
            'category_id' => (string)$this->request->getGet('category_id', ''),
            'page' => (string)$this->request->getGet('page', '1'),
        ];

        return $this->redirect($this->request->getUrlBuilder()->getBackendUrl('dropship/backend/listing/index', $params));
    }

    /**
     * 异步选品：分类 + 商品（不阻塞 index SSR）。
     */
    #[Acl('Weline_Dropship::commerce:dropship:listings_search', '异步浏览远程货源', 'tag', '异步浏览远程货源商品')]
    public function getBrowse(): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');
        // 禁止在 JSON 路径调用 registerAllProviders（会同步写库，易卡死并发选品请求）
        $payload = $this->buildBrowsePayload([
            'provider_code' => (string)$this->request->getGet('provider_code', $this->request->getGet('provider', '')),
            'q' => (string)$this->request->getGet('q', ''),
            'country_code' => (string)$this->request->getGet('country_code', ''),
            'category_id' => (string)$this->request->getGet('category_id', ''),
            'page' => (string)$this->request->getGet('page', '1'),
            'include_categories' => (string)$this->request->getGet('include_categories', '1') !== '0',
            'refresh_categories' => (string)$this->request->getGet('refresh_categories', '0') === '1',
        ]);

        return (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 已刊行手风琴：展开后再异步拉取本地产品详情/规格（ProductAdminRead）。
     */
    #[Acl('Weline_Dropship::commerce:dropship:listings_index', '查看已刊本地详情', 'tag', '异步查看已刊本地产品详情')]
    public function getLocalDetail(): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');
        $listingId = (int)$this->request->getGet('listing_id', 0);
        if ($listingId <= 0) {
            return (string)json_encode(['ok' => false, 'message' => 'listing_required'], JSON_UNESCAPED_UNICODE);
        }

        /** @var DropshipListing $model */
        $model = ObjectManager::getInstance(DropshipListing::class);
        $row = $model->clear()
            ->where(DropshipListing::schema_fields_ID, $listingId)
            ->find()
            ->fetch();
        if (!$row || !$row->getId()) {
            return (string)json_encode(['ok' => false, 'message' => 'listing_not_found'], JSON_UNESCAPED_UNICODE);
        }

        /** @var DropshipListedLocalDetailService $svc */
        $svc = ObjectManager::getInstance(DropshipListedLocalDetailService::class);
        $payload = $svc->build($row->getData());

        return (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    #[Acl('Weline_Dropship::commerce:dropship:listings_publish', '刊登货源商品', 'tag', '刊登到本地')]
    public function postPublish(): string
    {
        $providerCode = (string)$this->request->getPost('provider_code', '');
        $payload = (string)$this->request->getPost('snapshot_json', '{}');
        $snapshot = DropshipCatalogSnapshot::fromArray(json_decode($payload, true) ?: []);
        /** @var DropshipFacade $facade */
        $facade = ObjectManager::getInstance(DropshipFacade::class);
        try {
            $result = $facade->publishSnapshot($providerCode, $snapshot, [
                'website_id' => (int)$this->request->getPost('website_id', 0),
                'store_id' => (int)$this->request->getPost('store_id', 0),
                'channel' => (string)$this->request->getPost('channel', 'default'),
            ]);
            $this->getMessageManager()->addSuccess((string)__('已刊登 listing #%1', [$result['listing_id'] ?? 0]));
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($this->humanizePublishError($e->getMessage()));
        }

        return $this->redirect($this->request->getUrlBuilder()->getBackendUrl('dropship/backend/listing/index', [
            'tab' => 'listed',
        ]));
    }

    #[Acl('Weline_Dropship::commerce:dropship:listings_publish', '拉取到货源列表', 'tag', '拉取选品到货源列表')]
    public function postAdmitDraft(): string
    {
        $providerCode = trim((string)$this->request->getPost('provider_code', ''));
        $raw = (string)$this->request->getPost('snapshots_json', '[]');
        $snapshots = json_decode($raw, true);
        if (!is_array($snapshots)) {
            $snapshots = [];
        }
        /** @var DropshipListingDraftService $draft */
        $draft = ObjectManager::getInstance(DropshipListingDraftService::class);
        $result = $draft->admit($providerCode, $snapshots, [
            'country_code' => (string)$this->request->getPost('country_code', ''),
            'category_id' => (string)$this->request->getPost('category_id', ''),
            'category_path' => (string)$this->request->getPost('category_path', ''),
        ]);
        if (!empty($result['ok'])) {
            $this->getMessageManager()->addSuccess((string)__('已拉取 %{1} 条到货源列表', [(int)($result['admitted'] ?? 0)]));
        } else {
            $this->getMessageManager()->addError($this->humanizePublishError((string)($result['error'] ?? 'admit_failed')));
        }

        return $this->redirect($this->request->getUrlBuilder()->getBackendUrl('dropship/backend/listing/index', [
            'tab' => 'listed',
            'sources[]' => $providerCode,
        ]));
    }

    #[Acl('Weline_Dropship::commerce:dropship:listings_publish', '选择加入货源列表', 'tag', 'AJAX 选择加入货源列表')]
    public function postBasketSelect(): string
    {
        return $this->jsonBasketMutate(true);
    }

    #[Acl('Weline_Dropship::commerce:dropship:listings_publish', '取消移出货源列表', 'tag', 'AJAX 取消移出货源列表')]
    public function postBasketCancel(): string
    {
        return $this->jsonBasketMutate(false);
    }

    /**
     * @return string JSON
     */
    private function jsonBasketMutate(bool $select): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');
        $providerCode = trim((string)$this->request->getPost('provider_code', ''));
        $raw = (string)$this->request->getPost('snapshots_json', '[]');
        $snapshots = json_decode($raw, true);
        if (!is_array($snapshots)) {
            $snapshots = [];
        }
        /** @var DropshipListingDraftService $draft */
        $draft = ObjectManager::getInstance(DropshipListingDraftService::class);

        if ($select) {
            $result = $draft->admit($providerCode, $snapshots, [
                'country_code' => (string)$this->request->getPost('country_code', ''),
                'category_id' => (string)$this->request->getPost('category_id', ''),
                'category_path' => (string)$this->request->getPost('category_path', ''),
            ]);
            $spus = [];
            foreach ($snapshots as $row) {
                if (is_array($row)) {
                    $spu = trim((string)($row['external_spu'] ?? ''));
                    if ($spu !== '') {
                        $spus[] = $spu;
                    }
                }
            }
            $state = $draft->mapPullState($providerCode, $spus);

            return (string)json_encode([
                'success' => !empty($result['ok']),
                'message' => !empty($result['ok'])
                    ? (string)__('已选择 %{1} 条到货源列表', [(int)($result['admitted'] ?? 0)])
                    : $this->humanizePublishError((string)($result['error'] ?? 'admit_failed')),
                'admitted' => (int)($result['admitted'] ?? 0),
                'state' => $state,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $spus = [];
        foreach ($snapshots as $row) {
            if (is_array($row)) {
                $spu = trim((string)($row['external_spu'] ?? ''));
                if ($spu !== '') {
                    $spus[] = $spu;
                }
            } elseif (is_string($row) || is_numeric($row)) {
                $spu = trim((string)$row);
                if ($spu !== '') {
                    $spus[] = $spu;
                }
            }
        }
        $spuRaw = (string)$this->request->getPost('spus_json', '[]');
        $extra = json_decode($spuRaw, true);
        if (is_array($extra)) {
            foreach ($extra as $spu) {
                $spu = trim((string)$spu);
                if ($spu !== '') {
                    $spus[] = $spu;
                }
            }
        }
        $result = $draft->removeFromBasket($providerCode, $spus);
        $state = $draft->mapPullState($providerCode, $spus);

        return (string)json_encode([
            'success' => !empty($result['ok']),
            'message' => !empty($result['ok'])
                ? (string)__('已取消 %{1} 条货源列表项', [(int)($result['removed'] ?? 0)])
                : $this->humanizePublishError((string)($result['error'] ?? 'remove_failed')),
            'removed' => (int)($result['removed'] ?? 0),
            'skipped_locked' => (int)($result['skipped_locked'] ?? 0),
            'state' => $state,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    #[Acl('Weline_Dropship::commerce:dropship:listings_publish', '批量刊登选中', 'tag', '批量刊登货源商品')]
    public function postPublishSelected(): string
    {
        $providerCode = trim((string)$this->request->getPost('provider_code', ''));
        $listingIdsRaw = $this->request->getPost('listing_ids', []);
        $listingIds = is_array($listingIdsRaw)
            ? array_values(array_map('intval', $listingIdsRaw))
            : array_values(array_filter(array_map('intval', explode(',', (string)$listingIdsRaw))));
        $snapRaw = (string)$this->request->getPost('snapshots_json', '[]');
        $snapshots = json_decode($snapRaw, true);
        if (!is_array($snapshots)) {
            $snapshots = [];
        }

        try {
            $scope = $this->resolvePublishScopeFromRequest();
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($this->humanizePublishError($e->getMessage()));

            return $this->redirect($this->request->getUrlBuilder()->getBackendUrl('dropship/backend/listing/index', [
                'tab' => 'listed',
            ]));
        }

        /** @var DropshipListingDraftService $draft */
        $draft = ObjectManager::getInstance(DropshipListingDraftService::class);
        /** @var DropshipPublishService $publish */
        $publish = ObjectManager::getInstance(DropshipPublishService::class);
        $result = $draft->publishSelected($providerCode, $listingIds, $snapshots, $scope, $publish);

        if (!empty($result['ok'])) {
            $this->getMessageManager()->addSuccess((string)__('已刊登 %{1} 条', [(int)($result['published'] ?? 0)]));
        } else {
            $errors = (array)($result['errors'] ?? []);
            $msg = $errors !== [] ? (string)$errors[0] : 'publish_failed';
            $this->getMessageManager()->addError($this->humanizePublishError($msg));
        }

        return $this->redirect($this->request->getUrlBuilder()->getBackendUrl('dropship/backend/listing/index', [
            'tab' => 'listed',
        ]));
    }

    #[Acl('Weline_Dropship::commerce:dropship:listings_publish', '删除货源刊登', 'tag', '删除货源刊登记录')]
    public function postDeleteSelected(): string
    {
        $listingIdsRaw = $this->request->getPost('listing_ids', []);
        $listingIds = is_array($listingIdsRaw)
            ? array_values(array_map('intval', $listingIdsRaw))
            : array_values(array_filter(array_map('intval', explode(',', (string)$listingIdsRaw))));

        /** @var DropshipListingDeleteService $delete */
        $delete = ObjectManager::getInstance(DropshipListingDeleteService::class);
        $localAction = (string)$this->request->getPost('local_product_action', DropshipListingDeleteService::LOCAL_DISABLE);
        $result = $delete->deleteListings($listingIds, 0, $localAction);

        if (!empty($result['ok'])) {
            $action = (string)($result['local_product_action'] ?? DropshipListingDeleteService::LOCAL_DISABLE);
            $msg = match ($action) {
                DropshipListingDeleteService::LOCAL_KEEP => (string)__('已删除 %{1} 条刊登（本地商品已保留）', [(int)($result['deleted'] ?? 0)]),
                DropshipListingDeleteService::LOCAL_ARCHIVE => (string)__('已删除 %{1} 条刊登，并归档本地商品 %{2} 个', [
                    (int)($result['deleted'] ?? 0),
                    (int)($result['archived_products'] ?? 0),
                ]),
                default => (string)__('已删除 %{1} 条刊登，并停用本地商品 %{2} 个', [
                    (int)($result['deleted'] ?? 0),
                    (int)($result['disabled_products'] ?? 0),
                ]),
            };
            $this->getMessageManager()->addSuccess($msg);
        } else {
            $errors = (array)($result['errors'] ?? []);
            $msg = (string)($result['error'] ?? ($errors[0] ?? 'delete_failed'));
            $this->getMessageManager()->addError($this->humanizePublishError($msg));
        }

        return $this->redirect($this->request->getUrlBuilder()->getBackendUrl('dropship/backend/listing/index', [
            'tab' => 'listed',
        ]));
    }

    /**
     * 刊登弹窗：官方 &lt;w:scope&gt; 四级范围（Global/网站/店铺/渠道）。
     */
    private function assignPublishScopeSelects(): void
    {
        $this->assign('publishSelectedScope', 'default.__website__.default');
    }

    /**
     * @return array{website_id:int,store_id:int,channel:string,storage_scope:string}
     */
    private function resolvePublishScopeFromRequest(): array
    {
        /** @var SystemConfigTargetScopeService $targetScopeService */
        $targetScopeService = ObjectManager::getInstance(SystemConfigTargetScopeService::class);
        $input = [];
        $target = trim((string)$this->request->getPost('target_scope', ''));
        if ($target !== '') {
            $input['target_scope'] = $target;
        }
        foreach (['website_code', 'store_code', 'channel_code'] as $key) {
            $raw = $this->request->getPost($key, null);
            if ($raw === null) {
                continue;
            }
            $value = trim((string)$raw);
            if ($value === '') {
                continue;
            }
            $input[$key] = $value;
        }
        if ($input === []) {
            throw new \InvalidArgumentException((string)__('请选择刊登作用范围'));
        }

        $resolved = $targetScopeService->resolveFromInput($input, false);
        /** @var ScopeIdentity $identity */
        $identity = $resolved['identity'];
        $claims = $identity->toArray();
        $websiteId = $claims['website_id'] ?? null;
        if ($websiteId === null) {
            throw new \InvalidArgumentException((string)__('请选择具体网站范围（不能使用 Global）'));
        }
        $websiteId = (int)$websiteId;
        $storeId = $this->resolvePublishStoreId($resolved, $websiteId);
        $channel = trim((string)($resolved['channel_code'] ?? ($claims['channel_code'] ?? '')));
        if ($channel === '' || $channel === 'default' || $channel === '__channel__') {
            $channel = 'default';
        }

        return [
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'channel' => $channel,
            'storage_scope' => (string)($resolved['storage_scope'] ?? 'default.__website__.default'),
        ];
    }

    /**
     * 网站级范围映射存 store_id=0；仅 store/channel 级解析具体店铺。
     *
     * @param array{kind?:string,store_code?:string,identity?:ScopeIdentity} $resolved
     */
    private function resolvePublishStoreId(array $resolved, int $websiteId): int
    {
        $kind = strtolower(trim((string)($resolved['kind'] ?? '')));
        if ($kind === SystemConfigTargetScopeService::KIND_WEBSITE
            || $kind === SystemConfigTargetScopeService::KIND_GLOBAL
        ) {
            return 0;
        }

        /** @var ScopeIdentity|null $identity */
        $identity = $resolved['identity'] ?? null;
        $storeCode = trim((string)($resolved['store_code'] ?? ''));
        if ($storeCode === '' && $identity instanceof ScopeIdentity) {
            $storeCode = trim((string)($identity->toArray()['store_code'] ?? ''));
        }
        if ($storeCode === '' || $storeCode === 'default' || $storeCode === '__store__') {
            if ($kind === '' || $kind === SystemConfigTargetScopeService::KIND_WEBSITE) {
                return 0;
            }
        }

        if ($kind !== SystemConfigTargetScopeService::KIND_STORE
            && $kind !== SystemConfigTargetScopeService::KIND_CHANNEL
        ) {
            return 0;
        }

        /** @var StoreCatalogInterface $storeCatalog */
        $storeCatalog = ObjectManager::getInstance(StoreCatalogInterface::class);
        if ($storeCode === '' || $storeCode === 'default' || $storeCode === '__store__') {
            $default = $storeCatalog->defaultStore($websiteId);

            return $default !== null ? (int)$default->id : 0;
        }

        $summary = $storeCatalog->byCode($websiteId, $storeCode);
        if ($summary === null) {
            throw new \InvalidArgumentException((string)__('店铺不存在：%{1}', [$storeCode]));
        }

        return (int)$summary->id;
    }

    /**
     * @param list<array{code:string,title:string,capabilities?:array<string,mixed>}> $providerOptions
     */
    private function assignListedState(array $providerOptions): void
    {
        $sourcesRaw = $this->request->getGet('sources', '');
        if (is_array($sourcesRaw)) {
            $sources = array_values(array_filter(array_map(static fn ($v) => trim((string)$v), $sourcesRaw)));
        } else {
            $sources = array_values(array_filter(array_map('trim', explode(',', (string)$sourcesRaw))));
        }
        /** @var DropshipListing $model */
        $model = ObjectManager::getInstance(DropshipListing::class);
        $q = $model->clear();
        if ($sources !== []) {
            $q->where(DropshipListing::schema_fields_PROVIDER_CODE, $sources, 'IN');
        }
        $rows = $q->order(DropshipListing::schema_fields_ID, 'DESC')->limit(100)->select()->fetchArray();
        $listings = $this->enrichListedLocalMeta(is_array($rows) ? $rows : []);
        $warehouseNames = $this->warehouseNameMap($listings);
        $providerSelectOptions = [];
        foreach ($providerOptions as $opt) {
            $code = trim((string)($opt['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $title = trim((string)($opt['title'] ?? ''));
            $providerSelectOptions[] = [
                'value' => $code,
                'label' => $title !== '' ? $title : $code,
            ];
        }

        $this->assign('listings', $listings);
        $this->assign('warehouse_names', $warehouseNames);
        $this->assign('selected_sources', $sources);
        $this->assign('selectedSourcesCsv', implode(',', $sources));
        $this->assign('providerSelectOptionsJson', json_encode($providerSelectOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
        $this->assign('local_detail_url', $this->request->getUrlBuilder()->getBackendUrl('dropship/backend/listing/localDetail'));
        $this->assign('items', []);
        $this->assign('provider_code', '');
        $this->assign('keyword', '');
        $this->assign('country_code', 'US');
        $this->assign('category_id', '');
        $this->assign('categories', []);
        $this->assign('provider_capabilities', []);
        $this->assign('browse_enabled', false);
        $this->assign('browse_country_filter', false);
        $this->assign('search_page', 1);
        $this->assign('search_page_size', 20);
        $this->assign('search_has_more', false);
        $this->assign('search_error', '');
        $this->assign('search_attempted', false);
        $this->assign('search_empty_reason', 'idle');
        $this->assign('pull_state', []);
        $this->assign('browse_async', false);
        $this->assign('browse_url', '');
    }

    /**
     * SSR 只出壳：不调用远程 listCategories / searchProducts，避免卡页。
     *
     * @param list<array{code:string,title:string,capabilities?:array<string,mixed>}> $providerOptions
     */
    private function assignPickState(DropshipChannelManager $channels, array $providerOptions): void
    {
        $providerCode = trim((string)$this->request->getGet('provider_code', ''));
        if ($providerCode === '') {
            $providerCode = trim((string)$this->request->getGet('provider', ''));
        }
        if ($providerCode === '') {
            $providerCode = (string)($providerOptions[0]['code'] ?? '');
        }
        $keyword = trim((string)$this->request->getGet('q', ''));
        $countryCode = strtoupper(trim((string)$this->request->getGet('country_code', '')));
        if ($countryCode === '') {
            $countryCode = 'US';
        }
        $categoryId = trim((string)$this->request->getGet('category_id', ''));
        $page = max(1, (int)$this->request->getGet('page', 1));
        $pageSize = 20;

        $provider = $providerCode !== '' ? $channels->getProvider($providerCode) : null;
        $caps = is_object($provider) && method_exists($provider, 'getCapabilities')
            ? (array)$provider->getCapabilities()
            : [];
        $browseCap = !empty($caps['browse']) && $provider instanceof DropshipCatalogBrowseProviderInterface;
        $countryFilter = array_key_exists('browse_country_filter', $caps)
            ? (bool)$caps['browse_country_filter']
            : true;
        $doBrowse = $provider instanceof DropshipCatalogProviderInterface;

        $this->assign('listings', []);
        $this->assign('warehouse_names', []);
        $this->assign('selected_sources', []);
        $this->assign('items', []);
        $this->assign('pull_state', []);
        $this->assign('provider_code', $providerCode);
        $this->assign('keyword', $keyword);
        $this->assign('country_code', $countryCode);
        $this->assign('category_id', $categoryId);
        $this->assign('categories', []);
        $this->assign('provider_capabilities', $caps);
        $this->assign('browse_enabled', $browseCap);
        $this->assign('browse_country_filter', $countryFilter);
        $this->assign('search_page', $page);
        $this->assign('search_page_size', $pageSize);
        $this->assign('search_has_more', false);
        $this->assign('search_error', '');
        $this->assign('search_attempted', false);
        $this->assign('search_empty_reason', $doBrowse ? 'loading' : ($providerCode !== '' ? 'error' : 'idle'));
        $this->assign('browse_async', $doBrowse);
        $this->assign('browse_url', $this->request->getUrlBuilder()->getBackendUrl('dropship/backend/listing/browse', [
            'provider_code' => $providerCode,
            'q' => $keyword,
            'country_code' => $countryCode,
            'category_id' => $categoryId,
            'page' => (string)$page,
            'include_categories' => $browseCap ? '1' : '0',
        ]));
    }

    /**
     * @param array{
     *   provider_code?:string,
     *   q?:string,
     *   country_code?:string,
     *   category_id?:string,
     *   page?:string|int,
     *   include_categories?:bool,
     *   refresh_categories?:bool
     * } $input
     * @return array<string, mixed>
     */
    private function buildBrowsePayload(array $input): array
    {
        $providerCode = trim((string)($input['provider_code'] ?? ''));
        $keyword = trim((string)($input['q'] ?? ''));
        $countryCode = strtoupper(trim((string)($input['country_code'] ?? '')));
        if ($countryCode === '') {
            $countryCode = 'US';
        }
        $categoryId = trim((string)($input['category_id'] ?? ''));
        $page = max(1, (int)($input['page'] ?? 1));
        $pageSize = 20;
        $includeCategories = !empty($input['include_categories']);

        /** @var DropshipChannelManager $channels */
        $channels = ObjectManager::getInstance(DropshipChannelManager::class);
        $provider = $providerCode !== '' ? $channels->getProvider($providerCode) : null;
        $caps = is_object($provider) && method_exists($provider, 'getCapabilities')
            ? (array)$provider->getCapabilities()
            : [];
        $browseCap = !empty($caps['browse']) && $provider instanceof DropshipCatalogBrowseProviderInterface;
        $countryFilter = array_key_exists('browse_country_filter', $caps)
            ? (bool)$caps['browse_country_filter']
            : true;

        $locale = (string)State::getLangLocal();
        if ($locale === '') {
            $locale = (string)State::getLang();
        }

        $categories = [];
        $categoriesCache = 'bypass';
        if ($includeCategories && $browseCap && $provider instanceof DropshipCatalogBrowseProviderInterface) {
            $catQuery = ['locale' => $locale];
            if ($countryFilter) {
                $catQuery['country_code'] = $countryCode;
            }
            $forceRefresh = !empty($input['refresh_categories']);
            /** @var DropshipCatalogCategoryCacheService $catCache */
            $catCache = ObjectManager::getInstance(DropshipCatalogCategoryCacheService::class);
            $cached = $catCache->getCategories($provider, $providerCode, $catQuery, $countryFilter, $forceRefresh);
            $categories = $cached['categories'];
            $categoriesCache = (string)($cached['cache'] ?? 'miss');
        }

        $items = [];
        $searchError = '';
        $emptyReason = 'idle';
        $ok = true;
        if ($provider instanceof DropshipCatalogProviderInterface) {
            try {
                $query = [
                    'keyword' => $keyword,
                    'category_id' => $categoryId,
                    'page' => $page,
                    'size' => $pageSize,
                    'locale' => $locale,
                ];
                if ($countryFilter) {
                    $query['country_code'] = $countryCode;
                }
                $snapshots = $provider->searchProducts($query);
                foreach ($snapshots as $snapshot) {
                    if ($snapshot instanceof DropshipCatalogSnapshot) {
                        $row = $snapshot->toArray();
                    } elseif (is_array($snapshot)) {
                        $row = $snapshot;
                    } else {
                        continue;
                    }
                    $title = trim((string)($row['title'] ?? ''));
                    $spu = trim((string)($row['external_spu'] ?? ''));
                    if ($title === '' && $spu === '') {
                        continue;
                    }
                    $items[] = $row;
                }
                $emptyReason = $items === [] ? 'no_results' : 'has_results';
            } catch (\Throwable $e) {
                $ok = false;
                $searchError = $e->getMessage() !== ''
                    ? $e->getMessage()
                    : (string)__('远程选品失败，请检查货源凭证或稍后重试。');
                $emptyReason = 'error';
            }
        } elseif ($providerCode !== '') {
            $ok = false;
            $searchError = (string)__('当前供应商不支持远程目录选品。');
            $emptyReason = 'error';
        }

        $pullState = [];
        if ($providerCode !== '' && $items !== []) {
            /** @var DropshipListingDraftService $draft */
            $draft = ObjectManager::getInstance(DropshipListingDraftService::class);
            $spus = [];
            foreach ($items as $row) {
                $spu = trim((string)($row['external_spu'] ?? ''));
                if ($spu !== '') {
                    $spus[] = $spu;
                }
            }
            $pullState = $draft->mapPullState($providerCode, $spus);
            foreach ($items as $i => $row) {
                $spu = trim((string)($row['external_spu'] ?? ''));
                $st = $pullState[$spu] ?? ['pulled' => false, 'locked' => false, 'in_basket' => false, 'listing_id' => 0];
                $items[$i]['pull_locked'] = !empty($st['locked']);
                $items[$i]['pull_in_basket'] = !empty($st['in_basket']) && empty($st['locked']);
                $items[$i]['pull_pulled'] = !empty($st['pulled']);
                $items[$i]['listing_id'] = (int)($st['listing_id'] ?? 0);
            }
        }

        return [
            'success' => $ok,
            'provider_code' => $providerCode,
            'keyword' => $keyword,
            'country_code' => $countryCode,
            'category_id' => $categoryId,
            'page' => $page,
            'page_size' => $pageSize,
            'has_more' => count($items) >= $pageSize,
            'browse_enabled' => $browseCap,
            'browse_country_filter' => $countryFilter,
            'categories' => $categories,
            'categories_cache' => $categoriesCache,
            'items' => $items,
            'pull_state' => $pullState,
            'error' => $searchError,
            'empty_reason' => $emptyReason,
            'message' => $searchError,
        ];
    }

    /**
     * @return list<array{code:string,title:string,capabilities:array<string,mixed>}>
     */
    private function providerOptions(DropshipChannelManager $channels): array
    {
        /** @var DropshipSettings $settings */
        $settings = ObjectManager::getInstance(DropshipSettings::class);
        $options = [];
        foreach ($channels->getProviders() as $provider) {
            $code = $provider->getCode();
            if (!$settings->isPlatformEnabled($code)) {
                continue;
            }
            $meta = $provider->getDisplayMetadata();
            $options[] = [
                'code' => $code,
                'title' => (string)($meta['title'] ?? $code),
                'capabilities' => $provider->getCapabilities(),
            ];
        }
        usort($options, static function (array $a, array $b): int {
            return strcmp((string)$a['code'], (string)$b['code']);
        });

        return $options;
    }

    /**
     * 已刊登行补本地 SKU + 商品编辑深链（仅有 local_product_uuid 时）。
     *
     * @param list<array<string, mixed>> $listings
     * @return list<array<string, mixed>>
     */
    private function enrichListedLocalMeta(array $listings): array
    {
        if ($listings === []) {
            return [];
        }
        /** @var array<int, array<int, true>> $offerIdsByWebsite */
        $offerIdsByWebsite = [];
        /** @var array<int, array<string, true>> $uuidsByWebsite */
        $uuidsByWebsite = [];
        foreach ($listings as $row) {
            if (!is_array($row)) {
                continue;
            }
            $websiteId = (int)($row[DropshipListing::schema_fields_WEBSITE_ID] ?? 0);
            $offerId = (int)($row[DropshipListing::schema_fields_LOCAL_OFFER_ID] ?? 0);
            if ($offerId > 0) {
                $offerIdsByWebsite[$websiteId][$offerId] = true;
            }
            $uuid = trim((string)($row[DropshipListing::schema_fields_LOCAL_PRODUCT_UUID] ?? ''));
            if ($uuid !== '') {
                $uuidsByWebsite[$websiteId][$uuid] = true;
            }
        }
        /** @var array<string, string> $skuByKey */
        $skuByKey = [];
        foreach ($offerIdsByWebsite as $websiteId => $offerIds) {
            try {
                /** @var Offer $offer */
                $offer = ObjectManager::getInstance(Offer::class)->forWebsite((int)$websiteId);
                $rows = $offer->clear()
                    ->where(Offer::schema_fields_ID, array_map('intval', array_keys($offerIds)), 'IN')
                    ->select()
                    ->fetchArray();
                foreach (is_array($rows) ? $rows : [] as $orow) {
                    if (!is_array($orow)) {
                        continue;
                    }
                    $oid = (int)($orow[Offer::schema_fields_ID] ?? 0);
                    $sku = trim((string)($orow[Offer::schema_fields_SKU] ?? ''));
                    if ($oid > 0 && $sku !== '') {
                        $skuByKey[(int)$websiteId . ':' . $oid] = $sku;
                    }
                }
            } catch (\Throwable) {
                // 只读补齐失败不影响列表
            }
        }
        /** @var array<string, string> $statusByKey */
        $statusByKey = [];
        foreach ($uuidsByWebsite as $websiteId => $uuids) {
            try {
                /** @var Product $product */
                $product = ObjectManager::getInstance(Product::class)->forWebsite((int)$websiteId);
                $rows = $product->clear()
                    ->where(Product::schema_fields_GLOBAL_PRODUCT_UUID, array_keys($uuids), 'IN')
                    ->select()
                    ->fetchArray();
                foreach (is_array($rows) ? $rows : [] as $prow) {
                    if (!is_array($prow)) {
                        continue;
                    }
                    $u = trim((string)($prow[Product::schema_fields_GLOBAL_PRODUCT_UUID] ?? ''));
                    $st = strtolower(trim((string)($prow[Product::schema_fields_STATUS] ?? '')));
                    if ($u !== '' && $st !== '') {
                        $statusByKey[(int)$websiteId . ':' . $u] = $st;
                    }
                }
            } catch (\Throwable) {
                // 只读补齐失败不影响列表
            }
        }

        $out = [];
        /** @var DropshipPricingService $pricing */
        $pricing = ObjectManager::getInstance(DropshipPricingService::class);
        foreach ($listings as $row) {
            if (!is_array($row)) {
                continue;
            }
            $websiteId = (int)($row[DropshipListing::schema_fields_WEBSITE_ID] ?? 0);
            $offerId = (int)($row[DropshipListing::schema_fields_LOCAL_OFFER_ID] ?? 0);
            $uuid = trim((string)($row[DropshipListing::schema_fields_LOCAL_PRODUCT_UUID] ?? ''));
            $sync = strtolower(trim((string)($row[DropshipListing::schema_fields_SYNC_STATUS] ?? '')));
            $localSku = $offerId > 0 ? (string)($skuByKey[$websiteId . ':' . $offerId] ?? '') : '';
            $localProductStatus = $uuid !== '' ? (string)($statusByKey[$websiteId . ':' . $uuid] ?? '') : '';
            [$localProductStatusLabel, $localProductStatusTone] = DropshipListedLocalDetailService::productStatusPresentation(
                $localProductStatus
            );
            $editUrl = '';
            if ($uuid !== '' && $sync === DropshipListing::STATUS_ACTIVE) {
                $editUrl = (string)$this->request->getUrlBuilder()->getBackendUrl(
                    'weline_product/backend/catalog/edit-product',
                    [
                        'website_id' => (string)$websiteId,
                        'global_product_uuid' => $uuid,
                    ]
                );
            }
            $row['local_sku'] = $localSku;
            $row['local_edit_url'] = $editUrl;
            $row['local_product_status'] = $localProductStatus;
            $row['local_product_status_label'] = $localProductStatus !== '' ? $localProductStatusLabel : '';
            $row['local_product_status_tone'] = $localProductStatus !== '' ? $localProductStatusTone : 'muted';
            try {
                $row['economics'] = $pricing->economicsSnapshot(
                    (int)($row[DropshipListing::schema_fields_ORIGIN_PRICE_MINOR] ?? 0),
                    (int)($row[DropshipListing::schema_fields_ORIGIN_PRICE_PREV_MINOR] ?? 0),
                    (string)($row[DropshipListing::schema_fields_ORIGIN_CURRENCY] ?? 'USD'),
                    (int)($row[DropshipListing::schema_fields_SALE_PRICE_MINOR] ?? 0),
                    'CNY',
                    (string)($row[DropshipListing::schema_fields_PRICE_DIRECTION] ?? ''),
                );
            } catch (\Throwable) {
                $row['economics'] = null;
            }
            try {
                $saleCompare = $pricing->saleCompareInOriginCurrency(
                    (int)($row[DropshipListing::schema_fields_SALE_PRICE_MINOR] ?? 0),
                    'CNY',
                    (string)($row[DropshipListing::schema_fields_ORIGIN_CURRENCY] ?? 'USD'),
                );
                $row['sale_compare'] = $saleCompare;
            } catch (\Throwable) {
                $row['sale_compare'] = null;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $listings
     * @return array<int, string>
     */
    private function warehouseNameMap(array $listings): array
    {
        $ids = [];
        foreach ($listings as $row) {
            $id = (int)($row[DropshipListing::schema_fields_LOCAL_WAREHOUSE_ID] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }
        try {
            /** @var Warehouse $wh */
            $wh = ObjectManager::getInstance(Warehouse::class);
            $rows = $wh->clear()
                ->where(Warehouse::schema_fields_ID, array_values($ids), 'IN')
                ->select()
                ->fetchArray();
            $map = [];
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $id = (int)($row[Warehouse::schema_fields_ID] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }
                    $code = trim((string)($row[Warehouse::schema_fields_WAREHOUSE_CODE] ?? ''));
                    $name = trim((string)($row[Warehouse::schema_fields_NAME] ?? ''));
                    $map[$id] = trim(($code !== '' ? $code . ' — ' : '') . $name);
                }
            }

            return $map;
        } catch (\Throwable) {
            return [];
        }
    }

    private function humanizePublishError(string $raw): string
    {
        if (str_contains($raw, 'dropship_scope_warehouse_map_missing')) {
            return (string)__('未找到仓与国家映射：请先在「仓与国家映射」配置该供应商与国家的本地仓。');
        }
        if (str_contains($raw, 'dropship_snapshot_provider_mismatch')) {
            return (string)__('选中商品与当前供应商不一致，请清空勾选后重试。');
        }
        if (str_contains($raw, 'dropship_platform_not_enabled')) {
            return (string)__('该货源平台未启用。');
        }
        if (str_contains($raw, 'provider_required') || str_contains($raw, 'scope_or_provider_required')) {
            return (string)__('请选择同一供应商的商品后再刊登。');
        }
        if (str_contains($raw, 'scope_required')) {
            return (string)__('请选择刊登作用范围。');
        }
        if (str_contains($raw, 'ids_required')) {
            return (string)__('请选择要删除的刊登。');
        }
        if (str_contains($raw, 'delete_failed')) {
            return (string)__('删除刊登失败。');
        }

        return $raw !== '' ? $raw : (string)__('操作失败');
    }
}