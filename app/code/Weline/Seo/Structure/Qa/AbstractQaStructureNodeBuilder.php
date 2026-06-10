<?php

declare(strict_types=1);

namespace Weline\Seo\Structure\Qa;

use Weline\Seo\Structure\AbstractSeoStructureNodeBuilder;
use Weline\Seo\Structure\SeoStructureContextKeys;
use Weline\Seo\Structure\SeoStructureType;

/**
 * Q&A 列表 JSON-LD Builder 基类。
 */
abstract class AbstractQaStructureNodeBuilder extends AbstractSeoStructureNodeBuilder
{
    public function structureType(): string
    {
        return SeoStructureType::QA;
    }

    protected function contextFactKey(): string
    {
        return SeoStructureContextKeys::QA;
    }

    protected function schemaNodeType(): string
    {
        return 'QAPage';
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function hasSupportedFacts(array $context): bool
    {
        $qaList = $context[SeoStructureContextKeys::QA] ?? [];
        return is_array($qaList) && $qaList !== [];
    }
}
