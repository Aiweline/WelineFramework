<?php

declare(strict_types=1);

namespace Weline\Seo\Structure\ItemList;

use Weline\Seo\Structure\AbstractSeoStructureNodeBuilder;
use Weline\Seo\Structure\SeoStructureContextKeys;
use Weline\Seo\Structure\SeoStructureType;

/**
 * 列表页 JSON-LD Builder 基类。
 *
 * 内置 ItemList/CollectionPage 节点当前仍由 HeadRenderer 渲染。
 */
abstract class AbstractItemListStructureNodeBuilder extends AbstractSeoStructureNodeBuilder
{
    public function structureType(): string
    {
        return SeoStructureType::ITEM_LIST;
    }

    protected function contextFactKey(): string
    {
        return SeoStructureContextKeys::ITEM_LIST;
    }

    protected function schemaNodeType(): string
    {
        return 'ItemList';
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function hasSupportedFacts(array $context): bool
    {
        return $this->hasNonEmptyFacts($context)
            && $this->isPageType(
                $context,
                'category',
                'collection',
                'collection_page',
                'blog_list',
                'blog_category',
                'searchable_landing'
            );
    }
}
