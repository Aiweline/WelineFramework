<?php

declare(strict_types=1);

namespace Weline\Seo\Structure\Organization;

use Weline\Seo\Structure\AbstractSeoStructureNodeBuilder;
use Weline\Seo\Structure\SeoStructureContextKeys;
use Weline\Seo\Structure\SeoStructureType;

/**
 * 组织/本地商家 JSON-LD Builder 基类。
 *
 * 内置 Organization/LocalBusiness 节点当前仍由 HeadRenderer 渲染。
 */
abstract class AbstractOrganizationStructureNodeBuilder extends AbstractSeoStructureNodeBuilder
{
    public function structureType(): string
    {
        return SeoStructureType::ORGANIZATION;
    }

    protected function contextFactKey(): string
    {
        return SeoStructureContextKeys::ORGANIZATION;
    }

    protected function schemaNodeType(): string
    {
        return 'Organization';
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function hasSupportedFacts(array $context): bool
    {
        return $this->hasNonEmptyFacts($context);
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function hasSchemaNodeType(array $context, string $type): bool
    {
        foreach (['Organization', 'LocalBusiness'] as $orgType) {
            if (parent::hasSchemaNodeType($context, $orgType)) {
                return true;
            }
        }

        return parent::hasSchemaNodeType($context, $type);
    }
}
