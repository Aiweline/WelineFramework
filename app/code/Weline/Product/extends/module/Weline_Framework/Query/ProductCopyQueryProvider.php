<?php

declare(strict_types=1);

namespace Weline\Product\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Product\Api\Data\CopyDraft;
use Weline\Product\Service\ProductCopyService;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;

/**
 * Product-owned browser boundary for the backend Store copy wizard.
 *
 * Websites owns the page and ACL source; Product owns draft normalization,
 * preview and commit so the browser never reaches an internal Product service
 * through a Websites-side adapter.
 */
final class ProductCopyQueryProvider implements QueryProviderInterface
{
    public const ACL_SOURCE = 'Weline_Websites::store_copy_wizard';

    private const MAX_ID = 2147483647;

    public function __construct(
        private readonly ProductCopyService $copyService,
        private readonly WebsiteCatalogInterface $websiteCatalog,
        private readonly StoreCatalogInterface $storeCatalog,
    ) {
    }

    public function getProviderName(): string
    {
        return 'product_copy';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'scopeOptions' => $this->scopeOptions(),
            'createDraft' => $this->createDraft($params),
            'getDraft' => $this->getDraft($params),
            'preview' => $this->preview($params),
            'commit' => $this->commit($params),
            'cancel' => $this->cancel($params),
            default => throw new \InvalidArgumentException(
                (string)__('商品复制查询器不支持操作：%{1}', [$operation]),
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => $this->getProviderName(),
            'name' => (string)__('Store 商品复制'),
            'description' => (string)__('后台创建复制草稿、预览并幂等提交 Product 目录到目标 Store。'),
            'module' => 'Weline_Product',
            'operations' => [
                $this->operation('scopeOptions', (string)__('读取可复制 Website/Store 选项'), 'read', []),
                $this->operation('createDraft', (string)__('创建并规范化复制草稿'), 'write', [
                    ['name' => 'draft_id', 'type' => 'string|null', 'required' => false, 'max_length' => 96],
                    ['name' => 'entry', 'type' => 'string', 'required' => true, 'max_length' => 32],
                    ['name' => 'target_website_id', 'type' => 'int', 'required' => true, 'min' => 0, 'max' => self::MAX_ID],
                    ['name' => 'target_store_id', 'type' => 'int', 'required' => true, 'min' => 1, 'max' => self::MAX_ID],
                    ['name' => 'source_website_id', 'type' => 'int|null', 'required' => false, 'min' => 0, 'max' => self::MAX_ID],
                    ['name' => 'source_store_id', 'type' => 'int|null', 'required' => false, 'min' => 1, 'max' => self::MAX_ID],
                    ['name' => 'category_ids', 'type' => 'array', 'required' => false, 'max_items' => 2000],
                    ['name' => 'excluded_category_ids', 'type' => 'array', 'required' => false, 'max_items' => 2000],
                    ['name' => 'include_products', 'type' => 'bool', 'required' => false],
                    ['name' => 'field_packages', 'type' => 'array', 'required' => false, 'max_items' => 5],
                    ['name' => 'inventory_copy_qty', 'type' => 'bool', 'required' => false],
                    ['name' => 'duplicate_policy', 'type' => 'string', 'required' => false, 'max_length' => 32],
                ]),
                $this->operation('getDraft', (string)__('读取复制草稿'), 'read', [
                    ['name' => 'draft_id', 'type' => 'string', 'required' => true, 'max_length' => 96],
                ]),
                $this->operation('preview', (string)__('无写入预览复制计划'), 'read', [
                    ['name' => 'draft_id', 'type' => 'string', 'required' => true, 'max_length' => 96],
                ]),
                $this->operation('commit', (string)__('幂等提交复制草稿'), 'write', [
                    ['name' => 'draft_id', 'type' => 'string', 'required' => true, 'max_length' => 96],
                    ['name' => 'request_hash', 'type' => 'string', 'required' => true, 'min_length' => 32, 'max_length' => 128],
                ]),
                $this->operation('cancel', (string)__('取消未提交复制草稿'), 'write', [
                    ['name' => 'draft_id', 'type' => 'string', 'required' => true, 'max_length' => 96],
                ]),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function scopeOptions(): array
    {
        $websites = array_map(
            static fn($website): array => $website->toArray(),
            $this->websiteCatalog->all(),
        );
        $stores = [];
        foreach ($this->storeCatalog->all() as $store) {
            if ($store->lifecycleStatus !== 'active' || $store->tombstonedAt !== null) {
                continue;
            }
            $stores[] = $store->toArray();
        }

        return [
            'success' => true,
            'websites' => $websites,
            'stores' => $stores,
        ];
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function createDraft(array $params): array
    {
        $draft = new CopyDraft();
        $draft->draftId = $this->optionalString($params, 'draft_id');
        $draft->entry = $this->requiredString($params, 'entry');
        $draft->targetWebsiteId = $this->nonNegativeInt($params, 'target_website_id');
        $draft->targetStoreId = $this->positiveInt($params, 'target_store_id');
        $draft->sourceWebsiteId = $this->nullableNonNegativeInt($params, 'source_website_id');
        $draft->sourceStoreId = $this->nullablePositiveInt($params, 'source_store_id');
        $draft->categoryIds = $this->positiveIntList($params, 'category_ids');
        $draft->excludedCategoryIds = $this->positiveIntList($params, 'excluded_category_ids');
        $draft->includeProducts = $this->boolParam($params, 'include_products', true);
        $draft->fieldPackages = array_key_exists('field_packages', $params)
            ? $this->stringList($params, 'field_packages')
            : $draft->fieldPackages;
        $draft->inventoryCopyQty = $this->boolParam($params, 'inventory_copy_qty', false);
        $draft->duplicatePolicy = $this->optionalString($params, 'duplicate_policy') ?: CopyDraft::POLICY_SKIP;

        $this->assertScope($draft);
        $created = $this->copyService->createDraft($draft);

        return [
            'success' => true,
            'draft' => $created->toArray(),
        ];
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function getDraft(array $params): array
    {
        $draft = $this->copyService->getDraft($this->requiredString($params, 'draft_id'));

        return [
            'success' => true,
            'draft' => $draft?->toArray(),
        ];
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function preview(array $params): array
    {
        $preview = $this->copyService->preview($this->requiredString($params, 'draft_id'));

        return [
            'success' => true,
            'preview' => $preview->toArray(),
        ];
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function commit(array $params): array
    {
        return $this->copyService->commit(
            $this->requiredString($params, 'draft_id'),
            $this->requiredString($params, 'request_hash'),
        )->toArray();
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function cancel(array $params): array
    {
        $draftId = $this->requiredString($params, 'draft_id');
        $this->copyService->cancel($draftId);

        return [
            'success' => true,
            'draft' => $this->copyService->getDraft($draftId)?->toArray(),
        ];
    }

    private function assertScope(CopyDraft $draft): void
    {
        $websiteIds = [];
        foreach ($this->websiteCatalog->all() as $website) {
            $websiteIds[$website->id] = true;
        }
        if (!isset($websiteIds[$draft->targetWebsiteId])) {
            throw new \InvalidArgumentException((string)__('目标 Website 不存在'));
        }
        $this->assertStoreBelongsToWebsite(
            $draft->targetStoreId,
            $draft->targetWebsiteId,
            (string)__('目标 Store'),
        );

        if ($draft->entry === CopyDraft::ENTRY_BLANK) {
            return;
        }
        if ($draft->sourceWebsiteId === null || !isset($websiteIds[$draft->sourceWebsiteId])) {
            throw new \InvalidArgumentException((string)__('来源 Website 不存在'));
        }
        if ($draft->entry === CopyDraft::ENTRY_STORE_INHERIT && $draft->sourceStoreId !== null) {
            $this->assertStoreBelongsToWebsite(
                $draft->sourceStoreId,
                $draft->sourceWebsiteId,
                (string)__('来源 Store'),
            );
        }
    }

    private function assertStoreBelongsToWebsite(int $storeId, int $websiteId, string $label): void
    {
        $store = $this->storeCatalog->byId($storeId);
        if ($store === null || $store->websiteId !== $websiteId) {
            throw new \InvalidArgumentException((string)__('%{1} 不存在或不属于所选 Website', [$label]));
        }
        if ($store->lifecycleStatus !== 'active' || $store->tombstonedAt !== null) {
            throw new \InvalidArgumentException((string)__('%{1} 已归档，不可复制', [$label]));
        }
    }

    /**
     * @param list<array<string,mixed>> $params
     * @return array<string,mixed>
     */
    private function operation(string $name, string $description, string $mode, array $params): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'frontend' => true,
            'backend' => true,
            'external' => false,
            'auth' => 'backend',
            'backend_acl' => [
                'kind' => 'source',
                'source_id' => self::ACL_SOURCE,
            ],
            'mode' => $mode,
            'graph' => false,
            'cost' => $mode === 'write' ? 3 : 1,
            'params' => $params,
            'returns' => ['type' => 'map'],
        ];
    }

    /** @param array<string,mixed> $params */
    private function requiredString(array $params, string $key): string
    {
        $value = trim((string)($params[$key] ?? ''));
        if ($value === '') {
            throw new \InvalidArgumentException((string)__('缺少参数：%{1}', [$key]));
        }
        return $value;
    }

    /** @param array<string,mixed> $params */
    private function optionalString(array $params, string $key): string
    {
        return trim((string)($params[$key] ?? ''));
    }

    /** @param array<string,mixed> $params */
    private function nonNegativeInt(array $params, string $key): int
    {
        $value = $this->canonicalInt($params, $key);
        if ($value < 0) {
            throw new \InvalidArgumentException((string)__('参数 %{1} 须为非负整数', [$key]));
        }
        return $value;
    }

    /** @param array<string,mixed> $params */
    private function positiveInt(array $params, string $key): int
    {
        $value = $this->canonicalInt($params, $key);
        if ($value <= 0) {
            throw new \InvalidArgumentException((string)__('参数 %{1} 须为正整数', [$key]));
        }
        return $value;
    }

    /** @param array<string,mixed> $params */
    private function nullableNonNegativeInt(array $params, string $key): ?int
    {
        if (!array_key_exists($key, $params) || $params[$key] === null || $params[$key] === '') {
            return null;
        }
        return $this->nonNegativeInt($params, $key);
    }

    /** @param array<string,mixed> $params */
    private function nullablePositiveInt(array $params, string $key): ?int
    {
        if (!array_key_exists($key, $params) || $params[$key] === null || $params[$key] === '') {
            return null;
        }
        return $this->positiveInt($params, $key);
    }

    /** @param array<string,mixed> $params */
    private function canonicalInt(array $params, string $key): int
    {
        $value = $params[$key] ?? null;
        if (is_int($value)) {
            if ($value > self::MAX_ID) {
                throw new \InvalidArgumentException((string)__('参数 %{1} 超出范围', [$key]));
            }
            return $value;
        }
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new \InvalidArgumentException((string)__('参数 %{1} 须为规范整数', [$key]));
        }
        $int = (int)$value;
        if ((string)$int !== $value || $int > self::MAX_ID) {
            throw new \InvalidArgumentException((string)__('参数 %{1} 超出范围', [$key]));
        }
        return $int;
    }

    /** @param array<string,mixed> $params */
    private function boolParam(array $params, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $params)) {
            return $default;
        }
        $value = filter_var($params[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            throw new \InvalidArgumentException((string)__('参数 %{1} 须为布尔值', [$key]));
        }
        return $value;
    }

    /** @param array<string,mixed> $params @return list<int> */
    private function positiveIntList(array $params, string $key): array
    {
        $raw = $params[$key] ?? [];
        if (!is_array($raw)) {
            throw new \InvalidArgumentException((string)__('参数 %{1} 须为数组', [$key]));
        }
        $ids = [];
        foreach ($raw as $value) {
            $id = $this->positiveInt([$key => $value], $key);
            $ids[$id] = true;
        }
        $out = array_keys($ids);
        sort($out, SORT_NUMERIC);
        return $out;
    }

    /** @param array<string,mixed> $params @return list<string> */
    private function stringList(array $params, string $key): array
    {
        $raw = $params[$key] ?? [];
        if (!is_array($raw)) {
            throw new \InvalidArgumentException((string)__('参数 %{1} 须为数组', [$key]));
        }
        $out = [];
        foreach ($raw as $value) {
            if (!is_string($value)) {
                continue;
            }
            $value = trim($value);
            if ($value !== '') {
                $out[] = $value;
            }
        }
        return array_values(array_unique($out));
    }
}
