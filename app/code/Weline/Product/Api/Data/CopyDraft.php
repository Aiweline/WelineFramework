<?php

declare(strict_types=1);

namespace Weline\Product\Api\Data;

/**
 * Mutable copy draft（createDraft 规范化后供 preview/commit）.
 */
final class CopyDraft
{
    public const ENTRY_BLANK = 'blank';
    public const ENTRY_SITE_PULL = 'site_pull';
    public const ENTRY_STORE_INHERIT = 'store_inherit';

    public const STATE_DRAFT = 'draft';
    public const STATE_COMMITTING = 'committing';
    public const STATE_CANCELLED = 'cancelled';
    public const STATE_COMMITTED = 'committed';

    public const POLICY_SKIP = 'skip';
    public const POLICY_UPDATE = 'update_selected_fields';

    public const PKG_IDENTITY = 'identity';
    public const PKG_ATTRS = 'attrs';
    public const PKG_PRICE = 'price';
    public const PKG_MEDIA = 'media';
    public const PKG_INVENTORY = 'inventory';

    public string $draftId = '';
    public string $entry = self::ENTRY_BLANK;
    public string $state = self::STATE_DRAFT;

    public int $targetWebsiteId = 0;
    public int $targetStoreId = 0;
    public ?int $sourceWebsiteId = null;
    public ?int $sourceStoreId = null;

    /** @var list<int> */
    public array $categoryIds = [];
    /** @var list<int> categories explicitly unchecked (children of selected parents) */
    public array $excludedCategoryIds = [];

    public bool $includeProducts = true;
    /** @var list<string> */
    public array $fieldPackages = [
        self::PKG_IDENTITY,
        self::PKG_ATTRS,
        self::PKG_PRICE,
        self::PKG_MEDIA,
        self::PKG_INVENTORY,
    ];

    /** Default false：库存包存在时仍写 0；true 才复制 on_hand（TEST-P2C-COPY-03） */
    public bool $inventoryCopyQty = false;

    public string $duplicatePolicy = self::POLICY_SKIP;

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'draft_id' => $this->draftId,
            'entry' => $this->entry,
            'state' => $this->state,
            'target_website_id' => $this->targetWebsiteId,
            'target_store_id' => $this->targetStoreId,
            'source_website_id' => $this->sourceWebsiteId,
            'source_store_id' => $this->sourceStoreId,
            'category_ids' => $this->categoryIds,
            'excluded_category_ids' => $this->excludedCategoryIds,
            'include_products' => $this->includeProducts,
            'field_packages' => $this->fieldPackages,
            'inventory_copy_qty' => $this->inventoryCopyQty,
            'duplicate_policy' => $this->duplicatePolicy,
        ];
    }
}
