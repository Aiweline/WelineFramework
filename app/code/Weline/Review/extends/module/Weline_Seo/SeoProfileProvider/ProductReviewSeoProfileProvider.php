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
 *
 * Entity resolution mirrors the storefront reviews widget: offer uuid when selected,
 * otherwise product uuid (PDP may clear offer uuid until a variant is chosen).
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

        $offer = $this->storefrontOffer($template, $context);
        $entityUuid = $this->reviewEntityUuid($offer, $template, $context);
        if ($entityUuid === '') {
            return [];
        }

        $pageType = strtolower(str_replace(['-', ' '], '_', trim((string)($context['page_type'] ?? ''))));
        $isProductContext = $pageType === ''
            || $pageType === 'product'
            || $pageType === 'product_detail'
            || is_array($context['product'] ?? null)
            || $offer !== [];
        if (!$isProductContext) {
            return [];
        }

        try {
            $facts = $this->reviews->seoFacts('product', $entityUuid, 10);
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
     * @param array<string, mixed> $offer
     * @param mixed $template
     * @param array<string, mixed> $context
     */
    private function reviewEntityUuid(array $offer, $template, array $context): string
    {
        $uuid = $this->firstNonEmpty([
            $offer['global_offer_uuid'] ?? null,
            $offer['global_product_uuid'] ?? null,
            $offer['product_uuid'] ?? null,
        ]);
        if ($uuid !== '') {
            return $uuid;
        }

        foreach ($this->offerCandidates($offer, $template, $context) as $candidate) {
            $uuid = $this->firstNonEmpty([
                $candidate['global_offer_uuid'] ?? null,
                $candidate['global_product_uuid'] ?? null,
                $candidate['product_uuid'] ?? null,
                $candidate['id'] ?? null,
            ]);
            if ($uuid !== '') {
                return $uuid;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $offer
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return list<array<string, mixed>>
     */
    private function offerCandidates(array $offer, $template, array $context): array
    {
        $candidates = [];
        foreach ([
            $offer['storefront_offers'] ?? null,
            $offer['variants'] ?? null,
            $context['product']['storefront_offers'] ?? null,
            $context['product']['variants'] ?? null,
            is_object($template) && method_exists($template, 'getData')
                ? $template->getData('storefront_offers')
                : null,
        ] as $source) {
            if (!is_array($source)) {
                continue;
            }
            foreach ($source as $row) {
                if (is_array($row) && $row !== []) {
                    $candidates[] = $row;
                }
            }
        }

        return $candidates;
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
