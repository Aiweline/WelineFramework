<?php

declare(strict_types=1);

namespace Weline\Promotion\Extends\Module\Weline_Seo\SeoProfileProvider;

use Weline\Framework\Manager\ObjectManager;
use Weline\Promotion\Service\PromotionSeoFactsBuilder;
use Weline\Promotion\Service\PromotionStorefrontPageService;
use Weline\Seo\Interface\SeoProfileProviderInterface;

/**
 * Head-slot fallback for /promotion and /promotion/{slug}.
 * Controllers publish via assign('seo'); this fills gaps when bag is incomplete.
 */
final class PromotionSeoProfileProvider implements SeoProfileProviderInterface
{
    public function __construct(
        private readonly ?PromotionSeoFactsBuilder $factsBuilder = null,
        private readonly ?PromotionStorefrontPageService $pageService = null,
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

        if (!$this->claimsPromotion($template, $context)) {
            return [];
        }

        // Controller already published a products profile with item_list — keep it.
        $existingType = strtolower(str_replace(['-', ' '], '_', trim((string)($context['page_type'] ?? ''))));
        if ($existingType === 'products'
            && is_array($context['item_list'] ?? null)
            && $context['item_list'] !== []
        ) {
            return [];
        }

        $slug = $this->resolveSlug($template, $context);
        $pageData = $this->pageDataFromContext($template, $context);
        if ($pageData === []) {
            try {
                $pageService = $this->pageService ?? ObjectManager::getInstance(PromotionStorefrontPageService::class);
                if ($pageService->isPageAvailable($slug)) {
                    $pageData = $pageService->build($slug);
                }
            } catch (\Throwable) {
                $pageData = [];
            }
        }

        $builder = $this->factsBuilder ?? ObjectManager::getInstance(PromotionSeoFactsBuilder::class);

        return $builder->buildListingProfile($slug, $pageData);
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     */
    private function claimsPromotion($template, array $context): bool
    {
        $seoSlug = trim((string)($context['promotion_page_slug'] ?? ''));
        if ($seoSlug !== '') {
            return true;
        }

        $route = strtolower(trim((string)($this->templateGet($template, 'theme_public_route')
            ?? $context['theme_public_route']
            ?? '')));
        if ($route === 'promotion' || str_starts_with($route, 'promotion/')) {
            return true;
        }

        $url = trim((string)($context['canonical_url'] ?? $context['url'] ?? ''));
        $path = strtolower(trim((string)(parse_url($url, PHP_URL_PATH) ?: ''), '/'));
        // Strip optional locale prefix like en_US/
        if (preg_match('#^[a-z]{2}(?:_[a-z]{2})?/(.*)$#i', $path, $m) === 1) {
            $path = strtolower((string)$m[1]);
        }

        if ($path === 'promotion' || str_starts_with($path, 'promotion/')) {
            return true;
        }

        $uiPageType = strtolower(trim((string)($this->templateGet($template, 'page_type') ?? '')));
        if (in_array($uiPageType, ['deals', 'sale', 'weekend', 'wedding', 'index'], true)
            && ($this->templateGet($template, 'nav_tabs') !== null || $this->templateGet($template, 'promotions') !== null)
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     */
    private function resolveSlug($template, array $context): string
    {
        $slug = strtolower(trim((string)($context['promotion_page_slug'] ?? '')));
        if ($slug !== '') {
            return $slug;
        }

        $fromTemplate = strtolower(trim((string)($this->templateGet($template, 'page_type') ?? '')));
        if ($fromTemplate !== '' && $fromTemplate !== 'products') {
            return $fromTemplate;
        }

        $url = trim((string)($context['canonical_url'] ?? $context['url'] ?? ''));
        $path = strtolower(trim((string)(parse_url($url, PHP_URL_PATH) ?: ''), '/'));
        if (preg_match('#^[a-z]{2}(?:_[a-z]{2})?/(.*)$#i', $path, $m) === 1) {
            $path = strtolower((string)$m[1]);
        }
        if ($path === 'promotion') {
            return 'index';
        }
        if (preg_match('#^promotion/([a-z0-9_-]+)$#', $path, $m) === 1) {
            return (string)$m[1];
        }

        return 'index';
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function pageDataFromContext($template, array $context): array
    {
        $data = [];
        foreach (['title', 'hero_lede', 'page_type', 'items', 'promotions', 'list_url', 'deals_url', 'sale_url', 'total'] as $key) {
            $value = $this->templateGet($template, $key);
            if ($value === null && array_key_exists($key, $context)) {
                $value = $context[$key];
            }
            if ($value !== null) {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /**
     * @param mixed $template
     */
    private function templateGet($template, string $key): mixed
    {
        if (!is_object($template) || !method_exists($template, 'getData')) {
            return null;
        }
        try {
            return $template->getData($key);
        } catch (\Throwable) {
            return null;
        }
    }
}
