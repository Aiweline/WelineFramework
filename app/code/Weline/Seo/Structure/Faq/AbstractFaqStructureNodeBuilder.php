<?php

declare(strict_types=1);

namespace Weline\Seo\Structure\Faq;

use Weline\Seo\Structure\AbstractSeoStructureNodeBuilder;
use Weline\Seo\Structure\SeoStructureContextKeys;
use Weline\Seo\Structure\SeoStructureType;

/**
 * FAQ JSON-LD Builder 基类。
 */
abstract class AbstractFaqStructureNodeBuilder extends AbstractSeoStructureNodeBuilder
{
    public function structureType(): string
    {
        return SeoStructureType::FAQ;
    }

    protected function contextFactKey(): string
    {
        return SeoStructureContextKeys::FAQ;
    }

    protected function schemaNodeType(): string
    {
        return 'FAQPage';
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function hasSupportedFacts(array $context): bool
    {
        return $this->hasNonEmptyFacts($context);
    }
}
