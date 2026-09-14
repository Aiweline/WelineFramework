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

    /**
     * PDP FAQ facts via scope cascade + product merge (same path as storefront widget).
     *
     * @param array{
     *   website_id?:int,
     *   store_code?:string,
     *   channel_code?:string,
     *   locale_code?:string,
     *   product_uuid?:string,
     *   merge_enabled?:bool|null,
     *   active_pack?:string|null
     * } $context
     * @return list<array{question:string,answer:string}>
     */
    public function seoFaqsForPdp(array $context): array;
}
