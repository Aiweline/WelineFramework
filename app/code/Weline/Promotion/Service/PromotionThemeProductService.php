<?php

declare(strict_types=1);

namespace Weline\Promotion\Service;

use Weline\Product\Api\ProductAdminReadInterface;
use Weline\Promotion\Model\PromotionActivityTheme;
use Weline\Promotion\Model\PromotionActivityThemeProduct;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

final class PromotionThemeProductService
{
    public const PICK_MODE_MANUAL = 'manual';
    public const PICK_MODE_FILTER = 'filter';
    public const PICK_MODE_SINGLE = 'single';

    public function __construct(
        private readonly PromotionActivityThemeProduct $binding,
        private readonly StoreCatalogInterface $storeCatalog,
    ) {
    }

    /** @return list<string> */
    public static function allowedPickModes(): array
    {
        return [self::PICK_MODE_MANUAL, self::PICK_MODE_FILTER, self::PICK_MODE_SINGLE];
    }

    /** @return list<int> */
    public function listProductIds(int $themeId): array
    {
        return array_values(array_map(
            static fn (array $binding): int => (int)$binding['product_id'],
            $this->listProductBindings($themeId),
        ));
    }

    /** @return list<array{product_id:int,website_id:int,sort_order:int}> */
    public function listProductBindings(int $themeId): array
    {
        if ($themeId <= 0) {
            return [];
        }

        $collection = clone $this->binding;
        $collection->clear()
            ->where(PromotionActivityThemeProduct::schema_fields_THEME_ID, $themeId)
            ->order(PromotionActivityThemeProduct::schema_fields_SORT_ORDER, 'ASC')
            ->order(PromotionActivityThemeProduct::schema_fields_ID, 'ASC')
            ->select()
            ->fetch();

        $bindings = [];
        foreach ($collection->getItems() as $item) {
            $productId = (int)$item->getData(PromotionActivityThemeProduct::schema_fields_PRODUCT_ID);
            if ($productId <= 0) {
                continue;
            }
            $bindings[] = [
                'product_id' => $productId,
                'website_id' => max(0, (int)$item->getData(PromotionActivityThemeProduct::schema_fields_WEBSITE_ID)),
                'sort_order' => (int)$item->getData(PromotionActivityThemeProduct::schema_fields_SORT_ORDER),
            ];
        }

        return $bindings;
    }

    /**
     * @param array{website_id:int,store_code:string,channel_code:string} $scope
     * @return list<array<string, mixed>>
     */
    public function listProductsForBackend(int $themeId, array $scope): array
    {
        $items = [];
        foreach ($this->listProductBindings($themeId) as $binding) {
            $bindingScope = [
                'website_id' => (int)$binding['website_id'] > 0
                    ? (int)$binding['website_id']
                    : (int)$scope['website_id'],
                'store_code' => (string)$scope['store_code'],
                'channel_code' => (string)$scope['channel_code'],
            ];
            foreach ($this->rowsByIds($bindingScope, [(int)$binding['product_id']]) as $row) {
                $items[] = $row + [
                    'website_id' => (int)$bindingScope['website_id'],
                    'website_label' => $this->resolveWebsiteLabel((int)$bindingScope['website_id']),
                ];
            }
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $params
     * @return array{success:bool,message?:string,items:list<array<string,mixed>>,total?:int}
     */
    public function searchProducts(array $params): array
    {
        $scope = $this->scopeFromParams($params);
        // website_id=0 是「默认网站」（真实站点），允许搜索；仅缺省/非法时拒绝。
        if (!array_key_exists('website_id', $params) && !array_key_exists('websiteId', $params)) {
            return [
                'success' => false,
                'message' => (string)__('活动主题必须指定 Website（一站一活动）。'),
                'items' => [],
            ];
        }
        if ((int)$scope['website_id'] < 0) {
            return [
                'success' => false,
                'message' => (string)__('活动主题 Website 无效。'),
                'items' => [],
            ];
        }

        $validation = $this->validateScope($scope);
        if ($validation !== null) {
            return ['success' => false, 'message' => $validation, 'items' => []];
        }

        $reader = $this->productReader();
        if ($reader === null) {
            return [
                'success' => false,
                'message' => (string)__('Product 模块未安装，无法选品。'),
                'items' => [],
            ];
        }

        $filters = $this->filtersFromParams($params);
        $limit = max(1, min(50, (int)($params['limit'] ?? 20)));

        try {
            $items = $this->collectFilteredAdminRows(
                $reader,
                (int)$scope['website_id'],
                $scope,
                $filters,
                $limit,
            );
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'message' => (string)__('当前 Website 商品分片未就绪，无法预览：%{1}', [$exception->getMessage()]),
                'items' => [],
            ];
        }

        return ['success' => true, 'items' => $items, 'total' => count($items)];
    }

    /**
     * 后台条件选品预览：按当前活动范围 + 筛选规则返回命中商品。
     * filter_limit = 前台最多展示（命中上限）；page / page_size = 预览表格异步翻页。
     *
     * @param array<string,mixed> $params
     * @return array{
     *   success:bool,
     *   message?:string,
     *   items:list<array<string,mixed>>,
     *   total?:int,
     *   limit?:int,
     *   page?:int,
     *   page_size?:int,
     *   page_count?:int
     * }
     */
    public function previewFilterProducts(array $params): array
    {
        $limit = max(1, min(48, (int)($params['filter_limit'] ?? 12)));
        $pageSize = max(1, min(48, (int)($params['page_size'] ?? 10)));
        $page = max(1, (int)($params['page'] ?? 1));
        $params['limit'] = $limit;
        $result = $this->searchProducts($params);
        if (($result['success'] ?? false) !== true) {
            $result['limit'] = $limit;
            $result['page'] = 1;
            $result['page_size'] = $pageSize;
            $result['page_count'] = 1;
            $result['total'] = 0;

            return $result;
        }

        $all = is_array($result['items'] ?? null) ? array_values($result['items']) : [];
        $total = count($all);
        $pageCount = max(1, (int)ceil($total / $pageSize));
        if ($total === 0) {
            $pageCount = 1;
            $page = 1;
        } else {
            $page = min($page, $pageCount);
        }
        $offset = ($page - 1) * $pageSize;

        return [
            'success' => true,
            'items' => array_slice($all, $offset, $pageSize),
            'total' => $total,
            'limit' => $limit,
            'page' => $page,
            'page_size' => $pageSize,
            'page_count' => $pageCount,
        ];
    }

    /**
     * @param array<string,mixed> $theme
     * @param array{website_id:int,store_code:string,channel_code:string} $requestScope
     * @return list<int>
     */
    public function resolveStorefrontProductIds(array $theme, array $requestScope): array
    {
        $pickMode = $this->normalizePickMode((string)($theme['product_pick_mode'] ?? self::PICK_MODE_MANUAL));
        $themeScope = [
            'website_id' => (int)($theme['website_id'] ?? 0),
            'store_code' => trim((string)($theme['store_code'] ?? '')),
            'channel_code' => trim((string)($theme['channel_code'] ?? '')),
        ];
        $scope = $this->mergeScope($themeScope, $requestScope);

        if ($pickMode === self::PICK_MODE_FILTER) {
            $filters = $this->decodeFilterJson((string)($theme['product_filter_json'] ?? ''));

            return $this->searchIds($scope, $filters, max(1, (int)($filters['limit'] ?? 12)));
        }

        $themeId = (int)($theme['id'] ?? 0);
        if ($themeId <= 0) {
            return [];
        }

        $requestWebsiteId = max(0, (int)$requestScope['website_id']);
        $bindings = $this->listProductBindings($themeId);
        if ($bindings === []) {
            return [];
        }

        if ((int)$themeScope['website_id'] <= 0 && $requestWebsiteId > 0) {
            $bindings = array_values(array_filter(
                $bindings,
                static fn (array $binding): bool => (int)$binding['website_id'] === $requestWebsiteId
                    || (int)$binding['website_id'] <= 0,
            ));
        }

        // Validate all manual bindings sharing one storefront scope with one
        // bounded reader call. The previous per-binding search repeated the
        // full published-product scan for every selected SKU (N+1 on promotion
        // pages with a large manual selection).
        $scopeGroups = [];
        foreach ($bindings as $binding) {
            $bindingWebsiteId = (int)$binding['website_id'] > 0
                ? (int)$binding['website_id']
                : (int)$scope['website_id'];
            // website_id=0 is the default website — keep it eligible.
            if ($bindingWebsiteId < 0) {
                continue;
            }
            $bindingScope = [
                'website_id' => $bindingWebsiteId,
                'store_code' => (string)$scope['store_code'],
                'channel_code' => (string)$scope['channel_code'],
            ];
            $scopeKey = serialize($bindingScope);
            $scopeGroups[$scopeKey] ??= [
                'scope' => $bindingScope,
            ];
        }

        /** @var array<string, array<int, true>> $allowedByScope */
        $allowedByScope = [];
        foreach ($scopeGroups as $scopeKey => $group) {
            $allowedByScope[$scopeKey] = array_fill_keys(
                $this->searchIds($group['scope'], ['status' => 'published'], 500),
                true,
            );
        }

        $ids = [];
        foreach ($bindings as $binding) {
            $bindingWebsiteId = (int)$binding['website_id'] > 0
                ? (int)$binding['website_id']
                : (int)$scope['website_id'];
            if ($bindingWebsiteId < 0) {
                continue;
            }
            $bindingScope = [
                'website_id' => $bindingWebsiteId,
                'store_code' => (string)$scope['store_code'],
                'channel_code' => (string)$scope['channel_code'],
            ];
            $scopeKey = serialize($bindingScope);
            $productId = (int)$binding['product_id'];
            if ($productId > 0 && isset($allowedByScope[$scopeKey][$productId])) {
                $ids[] = $productId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<int>|array<int|string,mixed> $productIds
     * @param array{website_id:int,store_code:string,channel_code:string} $scope
     * @return array{success:bool,message?:string}
     */
    public function validateSelection(array $productIds, array $scope, string $pickMode, array $productWebsiteIds = []): array
    {
        $pickMode = $this->normalizePickMode($pickMode);
        if ($pickMode === self::PICK_MODE_FILTER) {
            return ['success' => true];
        }

        $bindings = $this->normalizeProductBindings($productIds, $productWebsiteIds, $scope);
        if ($pickMode === self::PICK_MODE_SINGLE && count($bindings) > 1) {
            return ['success' => false, 'message' => (string)__('单商品活动只能绑定 1 个商品。')];
        }

        if ($bindings === []) {
            return ['success' => true];
        }

        foreach ($bindings as $binding) {
            $bindingScope = [
                'website_id' => (int)$binding['website_id'],
                'store_code' => (string)$scope['store_code'],
                'channel_code' => (string)$scope['channel_code'],
            ];
            $validation = $this->validateScope($bindingScope);
            if ($validation !== null) {
                return ['success' => false, 'message' => $validation];
            }

            $allowed = array_fill_keys($this->searchIds($bindingScope, ['status' => 'published'], 500), true);
            $productId = (int)$binding['product_id'];
            if (!isset($allowed[$productId])) {
                return [
                    'success' => false,
                    'message' => (string)__(
                        '商品 #%{1} 不在网站 #%{2} 的当前活动范围内，或尚未发布。',
                        [$productId, (int)$binding['website_id']],
                    ),
                ];
            }
        }

        return ['success' => true];
    }

    /**
     * @param list<int>|array<int|string,mixed> $productIds
     * @param list<int|string> $productWebsiteIds
     * @param array{website_id:int,store_code:string,channel_code:string} $scope
     * @return list<array{product_id:int,website_id:int}>
     */
    public function normalizeProductBindings(array $productIds, array $productWebsiteIds, array $scope): array
    {
        $themeWebsiteId = max(0, (int)$scope['website_id']);
        $bindings = [];
        $index = 0;
        foreach ($productIds as $productId) {
            $productId = (int)$productId;
            if ($productId <= 0) {
                ++$index;
                continue;
            }
            // website_id=0 是默认网站；未传商品站时继承主题站（含 0）。
            if (array_key_exists($index, $productWebsiteIds) && $productWebsiteIds[$index] !== '' && $productWebsiteIds[$index] !== null) {
                $websiteId = max(0, (int)$productWebsiteIds[$index]);
            } else {
                $websiteId = $themeWebsiteId;
            }
            $key = $websiteId . ':' . $productId;
            $bindings[$key] = [
                'product_id' => $productId,
                'website_id' => $websiteId,
            ];
            ++$index;
        }

        return array_values($bindings);
    }

    /**
     * @param list<int>|array<int|string,mixed> $productIds
     * @param list<int|string> $productWebsiteIds
     * @param array{website_id:int,store_code:string,channel_code:string} $scope
     */
    public function saveProducts(
        int $themeId,
        array $productIds,
        array $scope,
        string $pickMode,
        array $productWebsiteIds = [],
    ): void {
        if ($themeId <= 0) {
            return;
        }

        $pickMode = $this->normalizePickMode($pickMode);
        $collection = clone $this->binding;
        $collection->clear()
            ->where(PromotionActivityThemeProduct::schema_fields_THEME_ID, $themeId)
            ->select()
            ->fetch();
        foreach ($collection->getItems() as $item) {
            $item->delete();
        }

        if ($pickMode === self::PICK_MODE_FILTER) {
            return;
        }

        $bindings = $this->normalizeProductBindings($productIds, $productWebsiteIds, $scope);
        if ($pickMode === self::PICK_MODE_SINGLE) {
            $bindings = array_slice($bindings, 0, 1);
        }

        foreach ($bindings as $sortOrder => $binding) {
            $model = clone $this->binding;
            $model->clearData();
            $model->setData(PromotionActivityThemeProduct::schema_fields_THEME_ID, $themeId);
            $model->setData(PromotionActivityThemeProduct::schema_fields_WEBSITE_ID, (int)$binding['website_id']);
            $model->setData(PromotionActivityThemeProduct::schema_fields_PRODUCT_ID, (int)$binding['product_id']);
            $model->setData(PromotionActivityThemeProduct::schema_fields_SORT_ORDER, $sortOrder);
            $model->save();
        }
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function encodeFilterJson(array $params): string
    {
        $payload = [
            'product_type' => trim((string)($params['filter_product_type'] ?? '')),
            'status' => trim((string)($params['filter_status'] ?? 'published')) ?: 'published',
            'name' => trim((string)($params['filter_name'] ?? '')),
            'sku' => trim((string)($params['filter_sku'] ?? '')),
            'product_code' => trim((string)($params['filter_product_code'] ?? '')),
            'new_within_days' => max(0, (int)($params['filter_new_within_days'] ?? 0)),
            'limit' => max(1, min(48, (int)($params['filter_limit'] ?? 12))),
        ];

        return json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /** @return array<string,mixed> */
    public function decodeFilterJson(string $raw): array
    {
        if ($raw === '') {
            return ['status' => 'published', 'limit' => 12];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : ['status' => 'published', 'limit' => 12];
    }

    /** @param array<string,mixed> $params @return array{website_id:int,store_code:string,channel_code:string} */
    public function scopeFromParams(array $params): array
    {
        return [
            'website_id' => max(0, (int)($params['website_id'] ?? 0)),
            'store_code' => trim((string)($params['store_code'] ?? '')),
            'channel_code' => trim((string)($params['channel_code'] ?? '')),
        ];
    }

    /** @param array{website_id:int,store_code:string,channel_code:string} $themeScope @param array{website_id:int,store_code:string,channel_code:string} $requestScope */
    private function mergeScope(array $themeScope, array $requestScope): array
    {
        // 主题 website_id=0 表示默认网站，不得被请求站覆盖。
        $websiteId = max(0, (int)$themeScope['website_id']);

        $storeCode = trim((string)$themeScope['store_code']);
        if ($storeCode === '') {
            $storeCode = trim((string)$requestScope['store_code']);
        }

        $channelCode = trim((string)$themeScope['channel_code']);
        if ($channelCode === '') {
            $channelCode = trim((string)$requestScope['channel_code']);
        }

        return [
            'website_id' => $websiteId,
            'store_code' => $storeCode,
            'channel_code' => $channelCode,
        ];
    }

    /** @param array{website_id:int,store_code:string,channel_code:string} $scope */
    private function validateScope(array $scope): ?string
    {
        // 0 = 默认网站（真实站点），允许选品。
        if ((int)$scope['website_id'] < 0) {
            return (string)__('活动主题 Website 无效。');
        }

        $storeCode = trim((string)$scope['store_code']);
        $channelCode = trim((string)$scope['channel_code']);
        if ($channelCode !== '' && $storeCode === '') {
            return (string)__('配置渠道范围时必须同时选择店铺。');
        }

        if ($storeCode !== '') {
            $store = $this->storeCatalog->byCode((int)$scope['website_id'], $storeCode);
            if ($store === null) {
                return (string)__('店铺 code「%{1}」在当前 Website 下不存在。', [$storeCode]);
            }
        }

        return null;
    }

    /** @param array{website_id:int,store_code:string,channel_code:string} $scope @param list<int> $orderedIds @return list<array<string,mixed>> */
    private function rowsByIds(array $scope, array $orderedIds): array
    {
        $reader = $this->productReader();
        if ($reader === null || $orderedIds === []) {
            return [];
        }

        $allowed = array_fill_keys($orderedIds, true);
        $items = [];
        foreach ($reader->search((int)$scope['website_id'], $this->readerFilters($scope, ['status' => 'published'])) as $row) {
            $productId = (int)($row['product_id'] ?? 0);
            if ($productId <= 0 || !isset($allowed[$productId])) {
                continue;
            }
            $items[] = $this->normalizeAdminRow($row);
        }

        usort($items, static function (array $left, array $right) use ($orderedIds): int {
            $leftIndex = array_search((int)($left['product_id'] ?? 0), $orderedIds, true);
            $rightIndex = array_search((int)($right['product_id'] ?? 0), $orderedIds, true);

            return (int)$leftIndex <=> (int)$rightIndex;
        });

        return $items;
    }

    /** @param array{website_id:int,store_code:string,channel_code:string} $scope @param array<string,mixed> $filters @return list<int> */
    private function searchIds(array $scope, array $filters, int $limit): array
    {
        // Selection is a base-product read. Re-entering the priced storefront
        // catalog here recurses back through active-deal resolution.
        $reader = \Weline\Framework\Runtime\RequestLifecycleTrace::measurePhase(
            'promotion.selection.reader',
            fn() => $this->productReader(),
        );
        if ($reader === null || (int)$scope['website_id'] < 0) {
            return [];
        }

        $ids = [];
        try {
            $readerFilters = $this->readerFilters($scope, $filters);
            $rows = \Weline\Framework\Runtime\RequestLifecycleTrace::measurePhase(
                'promotion.selection.search',
                fn() => $reader instanceof \Weline\Product\Api\ProductSelectionReadInterface
                    ? $reader->searchSelectionRows((int)$scope['website_id'], $readerFilters)
                    : $reader->search((int)$scope['website_id'], $readerFilters),
            );
            foreach ($rows as $row) {
                if (!$this->matchesProductFilters($row, $filters)) {
                    continue;
                }
                $productId = (int)($row['product_id'] ?? 0);
                if ($productId <= 0) {
                    continue;
                }
                $ids[] = $productId;
                if (count($ids) >= $limit) {
                    break;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $ids;
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function filtersFromParams(array $params): array
    {
        return [
            'product_type' => trim((string)($params['filter_product_type'] ?? $params['product_type'] ?? '')),
            'status' => trim((string)($params['filter_status'] ?? $params['status'] ?? 'published')) ?: 'published',
            'name' => trim((string)($params['filter_name'] ?? $params['keyword'] ?? $params['name'] ?? '')),
            'sku' => trim((string)($params['filter_sku'] ?? $params['sku'] ?? '')),
            'product_code' => trim((string)($params['filter_product_code'] ?? $params['product_code'] ?? '')),
            'new_within_days' => max(0, (int)($params['filter_new_within_days'] ?? $params['new_within_days'] ?? 0)),
        ];
    }

    /** @param array{website_id:int,store_code:string,channel_code:string} $scope @param array<string,mixed> $filters @return array<string,mixed> */
    private function readerFilters(array $scope, array $filters): array
    {
        $storeId = $this->resolveStoreId((int)$scope['website_id'], trim((string)$scope['store_code']));
        $readerFilters = ['status' => trim((string)($filters['status'] ?? 'published')) ?: 'published'];
        if ($storeId !== null) {
            $readerFilters['store_id'] = $storeId;
        }
        foreach (['product_type', 'name', 'sku', 'product_code'] as $key) {
            $value = trim((string)($filters[$key] ?? ''));
            if ($value !== '') {
                $readerFilters[$key] = $value;
            }
        }

        return $readerFilters;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $filters */
    private function matchesProductFilters(array $row, array $filters): bool
    {
        $days = max(0, (int)($filters['new_within_days'] ?? 0));
        if ($days <= 0) {
            return true;
        }

        $updatedAt = trim((string)($row['updated_at'] ?? ''));
        if ($updatedAt === '') {
            return false;
        }

        $timestamp = strtotime($updatedAt);

        return $timestamp !== false && $timestamp >= strtotime('-' . $days . ' days');
    }

    private function normalizePickMode(string $pickMode): string
    {
        $pickMode = strtolower(trim($pickMode));

        return in_array($pickMode, self::allowedPickModes(), true)
            ? $pickMode
            : self::PICK_MODE_MANUAL;
    }

    private function resolveStoreId(int $websiteId, string $storeCode): ?int
    {
        if ($storeCode === '') {
            return null;
        }

        $store = $this->storeCatalog->byCode($websiteId, $storeCode);

        return $store !== null ? $store->id : null;
    }

    private function productReader(): ?ProductAdminReadInterface
    {
        if (!interface_exists(ProductAdminReadInterface::class)) {
            return null;
        }

        try {
            $reader = \Weline\Framework\Manager\ObjectManager::getInstance(ProductAdminReadInterface::class);

            return $reader instanceof ProductAdminReadInterface ? $reader : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeAdminRow(array $row): array
    {
        $skus = is_array($row['skus'] ?? null) ? $row['skus'] : [];
        $sku = trim((string)($skus[0] ?? ''));
        $mainMedia = is_array($row['main_media'] ?? null) ? $row['main_media'] : [];
        $imageUrl = trim((string)($mainMedia['display_url']
            ?? $row['image_url']
            ?? $row['main_image']
            ?? ''));

        return [
            'product_id' => (int)($row['product_id'] ?? 0),
            'website_id' => max(0, (int)($row['website_id'] ?? 0)),
            'website_label' => trim((string)($row['website_label'] ?? '')),
            'name' => trim((string)($row['name'] ?? '')) ?: $sku,
            'sku' => $sku,
            'image_url' => $imageUrl,
            'status' => (string)($row['status'] ?? ''),
            'product_type' => (string)($row['product_type'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $params @return array{success:bool,message?:string,items:list<array<string,mixed>>,total?:int} */
    private function searchProductsAcrossWebsites(array $params): array
    {
        $reader = $this->productReader();
        if ($reader === null) {
            return [
                'success' => false,
                'message' => (string)__('Product 模块未安装，无法选品。'),
                'items' => [],
            ];
        }

        $filters = $this->filtersFromParams($params);
        $limit = max(1, min(50, (int)($params['limit'] ?? 20)));
        $storeCode = trim((string)($params['store_code'] ?? ''));
        $channelCode = trim((string)($params['channel_code'] ?? ''));
        $items = [];
        foreach ($this->previewWebsiteCandidates() as $candidate) {
            $websiteId = (int)$candidate['website_id'];
            $scope = [
                'website_id' => $websiteId,
                'store_code' => $storeCode,
                'channel_code' => $channelCode,
            ];

            try {
                $rows = $this->collectFilteredAdminRows(
                    $reader,
                    $websiteId,
                    $scope,
                    $filters,
                    $limit - count($items),
                    trim((string)$candidate['website_label']),
                );
            } catch (\Throwable) {
                continue;
            }

            foreach ($rows as $row) {
                $items[] = $row;
                if (count($items) >= $limit) {
                    break 2;
                }
            }
        }

        return ['success' => true, 'items' => $items, 'total' => count($items)];
    }

    /**
     * @return list<array{website_id:int,website_label:string}>
     */
    private function previewWebsiteCandidates(): array
    {
        $candidates = [];
        $seen = [];
        foreach ($this->loadWebsiteOptions() as $website) {
            if (!is_array($website)) {
                continue;
            }

            $websiteId = (int)($website['value'] ?? -1);
            if ($websiteId < 0 || isset($seen[$websiteId])) {
                continue;
            }

            $seen[$websiteId] = true;
            $candidates[] = [
                'website_id' => $websiteId,
                'website_label' => trim((string)($website['label'] ?? '')) ?: ('W#' . $websiteId),
            ];
        }

        if ($candidates === []) {
            return [];
        }

        usort(
            $candidates,
            static fn (array $left, array $right): int => (int)$left['website_id'] <=> (int)$right['website_id'],
        );

        return $candidates;
    }

    /**
     * @param array{website_id:int,store_code:string,channel_code:string} $scope
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function collectFilteredAdminRows(
        ProductAdminReadInterface $reader,
        int $websiteId,
        array $scope,
        array $filters,
        int $limit,
        string $websiteLabel = '',
    ): array {
        if ($limit <= 0) {
            return [];
        }

        $label = $websiteLabel !== '' ? $websiteLabel : $this->resolveWebsiteLabel($websiteId);
        $items = [];
        foreach ($reader->search($websiteId, $this->readerFilters($scope, $filters)) as $row) {
            if (!$this->matchesProductFilters($row, $filters)) {
                continue;
            }

            $item = $this->normalizeAdminRow($row);
            // Right-hand assignment must win: normalize may leave empty website_label.
            $item['website_id'] = $websiteId;
            $item['website_label'] = $label;
            $items[] = $item;
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /** @return list<array{value:string,label:string}> */
    private function loadWebsiteOptions(): array
    {
        try {
            $rows = w_query('websites', 'getWebsiteSelectOptions', [], 'backend');
        } catch (\Throwable) {
            $rows = [];
        }

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    private function resolveWebsiteLabel(int $websiteId): string
    {
        if ($websiteId < 0) {
            return '';
        }
        foreach ($this->loadWebsiteOptions() as $option) {
            if ((string)($option['value'] ?? '') === (string)$websiteId) {
                return trim((string)($option['label'] ?? '')) ?: ('W#' . $websiteId);
            }
        }

        return $websiteId === 0 ? (string)__('默认网站') : ('W#' . $websiteId);
    }
}
