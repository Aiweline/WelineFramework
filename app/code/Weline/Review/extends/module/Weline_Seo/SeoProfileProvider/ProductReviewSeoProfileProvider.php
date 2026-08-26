<?php

declare(strict_types=1);

namespace Weline\Review\Extends\Module\Weline_Seo\SeoProfileProvider;

use Weline\Review\Api\ReviewSeoFactsInterface;
use Weline\Seo\Interface\SeoProfileProviderInterface;

/**
 * Inject approved product-review rating facts into page SEO context.
 *
 * Returns structured reviews + AggregateRating inputs only; HeadRenderer owns JSON-LD.
 * Display names are resolved for the current request locale via ReviewSeoFactsInterface (__()),
 * never as a multi-language payload.
 */
final class ProductReviewSeoProfileProvider implements SeoProfileProviderInterface
{
    public function __construct(
        private readonly ReviewSeoFactsInterface $reviews,
    ) {
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function provideSeoProfile($template, array $context): array
    {
        if (!$this->isHeadSlot($context)) {
            return [];
        }

        $offer = $this->storefrontOffer($template);
        $offerUuid = trim((string)($offer['global_offer_uuid'] ?? ''));
        if ($offerUuid === '') {
            return [];
        }

        try {
            $facts = $this->reviews->seoFacts('product', $offerUuid, 10);
        } catch (\Throwable) {
            return [];
        }

        $reviewCount = (int)($facts['review_count'] ?? 0);
        $reviews = is_array($facts['reviews'] ?? null) ? $facts['reviews'] : [];
        if ($reviewCount <= 0 || $reviews === []) {
            return [];
        }

        $average = (float)($facts['average_rating'] ?? 0.0);
        if ($average <= 0) {
            return [];
        }

        $product = [
            'rating' => $average,
            'review_count' => $reviewCount,
            'best_rating' => 5,
            'worst_rating' => 1,
        ];
        $name = trim((string)($offer['name'] ?? ''));
        if ($name !== '') {
            $product['name'] = $name;
        }

        return [
            'page_type' => 'product',
            'product' => $product,
            'reviews' => $reviews,
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function isHeadSlot(array $context): bool
    {
        $slot = strtolower(trim((string)($context['_slot'] ?? 'head')));

        return $slot === '' || $slot === 'head';
    }

    /**
     * @param mixed $template
     * @return array<string, mixed>
     */
    private function storefrontOffer($template): array
    {
        if (!is_object($template) || !method_exists($template, 'getData')) {
            return [];
        }
        $offer = $template->getData('storefront_offer');

        return is_array($offer) ? $offer : [];
    }
}
