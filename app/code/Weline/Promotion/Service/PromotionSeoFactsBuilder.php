<?php

declare(strict_types=1);

namespace Weline\Promotion\Service;

use Weline\Product\Service\StorefrontSeoListingFacts;

/**
 * Promotion-owned SEO facts for hub + theme storefront pages.
 *
 * UI page_type stays the theme slug (deals/sale/…); SEO page_type is products
 * so HeadRenderer emits CollectionPage without colliding with tab highlighting.
 */
final class PromotionSeoFactsBuilder
{
    public function __construct(
        private readonly StorefrontSeoListingFacts $listingFacts = new StorefrontSeoListingFacts(),
        private readonly ?PromotionActivityThemeService $themeService = null,
    ) {
    }

    /**
     * @param array<string, mixed> $pageData from PromotionStorefrontPageService::build()
     * @return array<string, mixed>
     */
    public function buildListingProfile(string $pageSlug, array $pageData): array
    {
        $pageSlug = strtolower(trim($pageSlug));
        if ($pageSlug === '') {
            $pageSlug = 'index';
        }

        $title = trim((string)($pageData['title'] ?? ''));
        if ($title === '') {
            $title = $pageSlug === 'index'
                ? $this->translate('活动中心')
                : $this->translate('活动主题');
        }

        $description = trim((string)($pageData['hero_lede'] ?? ''));
        if ($description === '') {
            $description = $title;
        }

        $canonical = $this->canonicalFor($pageSlug, $pageData);
        $itemList = $this->listingFacts->itemListFromCards(
            is_array($pageData['items'] ?? null) ? $pageData['items'] : [],
        );
        if ($itemList === []) {
            $itemList = $this->itemListFromEntryCards(
                is_array($pageData['promotions'] ?? null) ? $pageData['promotions'] : [],
            );
        }

        $hubUrl = $this->canonicalFor('index', $pageData);
        $trail = [
            ['name' => $this->translate('活动中心'), 'url' => $hubUrl],
        ];
        if ($pageSlug !== 'index') {
            $trail[] = ['name' => $title, 'url' => $canonical];
        }

        return [
            'page_type' => 'products',
            'title' => $title,
            'description' => $description,
            'canonical_url' => $canonical,
            'robots' => 'index,follow',
            'item_list' => $itemList,
            'breadcrumbs' => $this->listingFacts->withHomeBreadcrumb($trail),
            'sitemap' => [
                'include' => true,
                'changefreq' => 'daily',
                'priority' => $pageSlug === 'index' ? '0.8' : '0.7',
            ],
            'geo' => ['include' => true],
            // Keep theme slug for providers that need to re-resolve copy without
            // overwriting Template UI page_type.
            'promotion_page_slug' => $pageSlug,
        ];
    }

    /**
     * @param array<string, mixed> $pageData
     */
    private function canonicalFor(string $pageSlug, array $pageData): string
    {
        if ($pageSlug === 'index') {
            $listUrl = trim((string)($pageData['list_url'] ?? ''));
            if ($listUrl !== '') {
                return $listUrl;
            }
        } else {
            $slugUrls = [
                'deals' => (string)($pageData['deals_url'] ?? ''),
                'sale' => (string)($pageData['sale_url'] ?? ''),
            ];
            $fromData = trim((string)($slugUrls[$pageSlug] ?? ''));
            if ($fromData !== '') {
                return $fromData;
            }
        }

        if ($this->themeService !== null) {
            try {
                return $this->themeService->storefrontUrl($pageSlug === 'index' ? '' : $pageSlug);
            } catch (\Throwable) {
                // fall through
            }
        }

        return $pageSlug === 'index' ? '/promotion' : '/promotion/' . rawurlencode($pageSlug);
    }

    /**
     * @param list<array<string, mixed>> $cards
     * @return list<array{name:string,url:string,image?:string,description?:string}>
     */
    private function itemListFromEntryCards(array $cards): array
    {
        $mapped = [];
        foreach ($cards as $card) {
            if (!is_array($card)) {
                continue;
            }
            $mapped[] = [
                'name' => (string)($card['title'] ?? $card['name'] ?? ''),
                'url' => (string)($card['action_url'] ?? $card['url'] ?? ''),
                'description' => (string)($card['subtitle'] ?? $card['description'] ?? ''),
            ];
        }

        return $this->listingFacts->itemListFromCards($mapped);
    }

    private function translate(string $text): string
    {
        return \function_exists('__') ? (string)\__($text) : $text;
    }
}
