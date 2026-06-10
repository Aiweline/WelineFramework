<?php

declare(strict_types=1);

namespace Weline\Seo\Structure\Qa;

use Weline\Seo\Structure\AbstractSeoStructureNormalizer;
use Weline\Seo\Structure\SeoStructureFactsInterface;

/**
 * Q&A 列表结构化事实归一化基类。
 */
abstract class AbstractQaStructureNormalizer extends AbstractSeoStructureNormalizer implements SeoStructureFactsInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    abstract public function normalize(mixed $qaList): array;
}
