<?php

declare(strict_types=1);

namespace Weline\Faq\Api;

/**
 * SEO-facing FAQ facts for the active request locale / website.
 */
interface FaqSeoFactsInterface
{
    /**
     * @return list<array{question:string,answer:string}>
     */
    public function seoFaqsForEntity(string $typeCode, string $entityUuid, int $websiteId = 0, string $localeCode = ''): array;
}
