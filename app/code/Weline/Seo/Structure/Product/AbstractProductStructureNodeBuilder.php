<?php

declare(strict_types=1);

namespace Weline\Seo\Structure\Product;

use Weline\Seo\Structure\AbstractSeoStructureNodeBuilder;
use Weline\Seo\Structure\SeoStructureContextKeys;
use Weline\Seo\Structure\SeoStructureType;

/**
 * 商品 JSON-LD Builder 基类。
 *
 * 内置 Product 节点当前仍由 HeadRenderer 渲染；业务模块可继承本类并通过
 * SeoStructureNodeBuilder 扩展点注册补充节点或覆盖策略。
 */
abstract class AbstractProductStructureNodeBuilder extends AbstractSeoStructureNodeBuilder
{
    public function structureType(): string
    {
        return SeoStructureType::PRODUCT;
    }

    protected function contextFactKey(): string
    {
        return SeoStructureContextKeys::PRODUCT;
    }

    protected function schemaNodeType(): string
    {
        return 'Product';
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function hasSupportedFacts(array $context): bool
    {
        return $this->hasNonEmptyFacts($context)
            && $this->isPageType($context, 'product');
    }
}
