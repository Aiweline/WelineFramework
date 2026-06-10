<?php

declare(strict_types=1);

namespace Weline\Seo\Structure\Faq;

use Weline\Seo\Structure\AbstractSeoStructureNormalizer;
use Weline\Seo\Structure\SeoStructureFactsInterface;

/**
 * FAQ 结构化事实归一化基类。
 */
abstract class AbstractFaqStructureNormalizer extends AbstractSeoStructureNormalizer implements SeoStructureFactsInterface
{
    /**
     * @return array<int, array{question:string,answer:string}>
     */
    abstract public function normalize(mixed $faqs): array;
}
