<?php

declare(strict_types=1);

namespace Weline\Faq\Extends\Module\Weline_Seo\SeoProfileProvider;

use Weline\Faq\Api\FaqSeoFactsInterface;
use Weline\Seo\Interface\SeoProfileProviderInterface;

/**
 * Inject product FAQ facts into product page SEO context (FAQPage inputs).
 * Does not handle hub pages — FaqSeoProfileProvider owns hub.
 */
final class ProductFaqSeoProfileProvider implements SeoProfileProviderInterface
{
    public function __construct(
        private readonly FaqSeoFactsInterface $faqs,
    ) {
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function provideSeoProfile($template, array $context): array
    {
        $slot = strtolower(trim((string)($context['_slot'] ?? 'head')));
        if ($slot !== '' && $slot !== 'head') {
            return [];
        }

        $pageType = strtolower(str_replace(['-', ' '], '_', trim((string)($context['page_type'] ?? ''))));
        $isProduct = $pageType === 'product'
            || $pageType === 'product_detail'
            || is_array($context['product'] ?? null);
        if (!$isProduct) {
            return [];
        }

        if (!empty($context['faqs']) && in_array($pageType, ['faq', 'faq_hub'], true)) {
            return [];
        }

        $offer = $this->storefrontOffer($template, $context);
        $entityUuid = $this->firstNonEmpty([
            $offer['global_offer_uuid'] ?? null,
            $offer['global_product_uuid'] ?? null,
            $offer['product_uuid'] ?? null,
            $context['product']['global_offer_uuid'] ?? null,
            $context['product']['global_product_uuid'] ?? null,
            $context['product']['product_uuid'] ?? null,
        ]);
        if ($entityUuid === '') {
            return [];
        }

        $websiteId = max(0, (int)($context['website_id'] ?? 0));
        $locale = trim((string)($context['locale'] ?? $context['locale_code'] ?? ''));
        $storeCode = strtolower(trim((string)($context['store_code'] ?? '')));
        $channelCode = strtolower(trim((string)($context['channel_code'] ?? '')));
        try {
            $faqs = $this->faqs->seoFaqsForPdp([
                'website_id' => $websiteId,
                'store_code' => $storeCode,
                'channel_code' => $channelCode,
                'locale_code' => $locale,
                'product_uuid' => $entityUuid,
            ]);
        } catch (\Throwable) {
            return [];
        }
        if ($faqs === []) {
            return [];
        }

        return [
            'page_type' => 'product',
            'faqs' => $faqs,
        ];
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function storefrontOffer($template, array $context): array
    {
        if (is_array($context['product'] ?? null) && $context['product'] !== []) {
            return $context['product'];
        }
        if (!is_object($template) || !method_exists($template, 'getData')) {
            return [];
        }
        $offer = $template->getData('storefront_offer');
        if (is_array($offer) && $offer !== []) {
            return $offer;
        }
        $product = $template->getData('product');

        return is_array($product) ? $product : [];
    }

    /**
     * @param list<mixed> $values
     */
    private function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string)$value) !== '') {
                return trim((string)$value);
            }
        }

        return '';
    }
}
