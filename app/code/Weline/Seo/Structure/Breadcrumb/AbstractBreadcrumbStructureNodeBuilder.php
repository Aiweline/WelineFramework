<?php

declare(strict_types=1);

namespace Weline\Seo\Structure\Breadcrumb;

use Weline\Seo\Structure\AbstractSeoStructureNodeBuilder;
use Weline\Seo\Structure\SeoStructureContextKeys;
use Weline\Seo\Structure\SeoStructureType;

/**
 * 面包屑 JSON-LD Builder 基类。
 *
 * 内置 BreadcrumbList 节点当前仍由 HeadRenderer 渲染。
 */
abstract class AbstractBreadcrumbStructureNodeBuilder extends AbstractSeoStructureNodeBuilder
{
    public function structureType(): string
    {
        return SeoStructureType::BREADCRUMB;
    }

    protected function contextFactKey(): string
    {
        return SeoStructureContextKeys::BREADCRUMB;
    }

    protected function schemaNodeType(): string
    {
        return 'BreadcrumbList';
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function hasSupportedFacts(array $context): bool
    {
        return $this->hasNonEmptyFacts($context);
    }
}
