<?php

declare(strict_types=1);

namespace Weline\Seo\Structure\Review;

use Weline\Seo\Structure\AbstractSeoStructureNodeBuilder;
use Weline\Seo\Structure\SeoStructureContextKeys;
use Weline\Seo\Structure\SeoStructureType;

/**
 * 评价 JSON-LD Builder 基类。
 *
 * 业务模块（如 WeShop_Review）可继承本类输出 Review / AggregateRating 节点。
 */
abstract class AbstractReviewStructureNodeBuilder extends AbstractSeoStructureNodeBuilder
{
    public function structureType(): string
    {
        return SeoStructureType::REVIEW;
    }

    protected function contextFactKey(): string
    {
        return SeoStructureContextKeys::REVIEW;
    }

    protected function schemaNodeType(): string
    {
        return 'Review';
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
        foreach (['Review', 'AggregateRating'] as $reviewType) {
            if (parent::hasSchemaNodeType($context, $reviewType)) {
                return true;
            }
        }

        return parent::hasSchemaNodeType($context, $type);
    }
}
