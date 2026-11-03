<?php

declare(strict_types=1);

namespace Weline\Product\Api\View;

use Weline\Framework\App\State;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Preload\ViewWarmupContribution;
use Weline\Framework\Runtime\Preload\ViewWarmupContributionProviderInterface;
use Weline\Product\Service\StorefrontAllMenuCategoryTreeService;
use Weline\Websites\Model\Website;

/**
 * Publishes bounded anonymous catalog surfaces for the WLS startup warmup.
 *
 * Category paths are resolved from the live storefront category nav tree
 * (Product-owned). Framework Runtime only seals whatever this contribution
 * publishes — it must not hardcode catalog slugs.
 */
final class ViewWarmupContributionProvider implements ViewWarmupContributionProviderInterface
{
    /**
     * Product-card surfaces that every storefront listing renders immediately.
     *
     * Cold measurement showed the first batch of 24 listing cards costing 79 ms, of
     * which one card paid 44 ms and the other 23 averaged 0.5 ms. The 44 ms sat in
     * the card's CTA block: `Template::getHook('...product-card::add-to-cart')` 31.2 ms
     * plus `...::buy-now` 11.7 ms on their first call (0.2-0.3 ms afterwards), split
     * into contributor resolution (~5.8 ms) and contributor rendering (~34.3 ms).
     *
     * Declaring the hook names warms the HookReader file lists; declaring the card and
     * hook templates warms their compiled artifacts so the first listing render no
     * longer pays for template resolution and compilation.
     *
     * @return list<string>
     */
    private function productCardTemplates(): array
    {
        return [
            'Weline_Product::templates/frontend/partials/product-card.phtml',
            'Weline_Product::templates/frontend/partials/product-card-shelf.phtml',
            'Weline_Product::templates/frontend/partials/product-cards-batch.phtml',
        ];
    }

    /**
     * Hook contributors rendered by every product card.
     *
     * @return list<string>
     */
    private function productCardHooks(): array
    {
        return [
            'Weline_Theme::frontend::partials::product-card::add-to-cart',
            'Weline_Theme::frontend::partials::product-card::buy-now',
        ];
    }

    /**
     * Hook contributor templates rendered by every product card.
     *
     * `hookNames` only warms the HookReader file lists; the contributor templates
     * themselves are warmed through `tagTemplates` so their compiled artifacts exist
     * before the first listing render. Sources use the `Module::hooks/<path>` shape
     * that resolveHookContributorFiles() produces.
     *
     * @return array<string, list<string>>
     */
    private function productCardHookTemplates(): array
    {
        return [
            'hooks' => [
                'Weline_Cart::hooks/Weline_Theme/frontend/partials/product-card/add-to-cart.phtml',
                'Weline_Checkout::hooks/Weline_Theme/frontend/partials/product-card/buy-now.phtml',
            ],
        ];
    }

    public function contribution(): ViewWarmupContribution
    {
        return new ViewWarmupContribution(
            templates: $this->productCardTemplates(),
            tagTemplates: $this->productCardHookTemplates(),
            hookNames: $this->productCardHooks(),
            fpcPaths: $this->catalogWarmupPaths(),
        );
    }

    /**
     * @return list<string>
     */
    private function catalogWarmupPaths(): array
    {
        $defaults = $this->resolveWebsiteDefaults();
        $defaultLanguage = $defaults['language'];
        $paths = ['/products' => '/products'];

        foreach ($this->resolveCategoryWarmupPaths() as $path) {
            $paths[$path] = $path;
        }

        // PDP 热度路径不在 Worker bootstrap 贡献里展开：bestSellerCards 会打目录构建，
        // 拖垮 READY。详情页预热交给 theme:fpc-prewarm / StorefrontFpcPrewarmer。

        $localeProductCount = 0;
        foreach ($defaults['languages'] as $code) {
            $code = \trim((string)$code);
            if ($code === '' || !State::isLanguageCodeShape($code)) {
                continue;
            }
            if ($defaultLanguage !== '' && \strcasecmp($code, $defaultLanguage) === 0) {
                continue;
            }
            $path = '/' . $code . '/products';
            $paths[$path] = $path;
            $localeProductCount++;
        }

        if ($localeProductCount === 0) {
            foreach (['ar_SA', 'zh_Hans_CN', 'en_US'] as $code) {
                if ($defaultLanguage !== '' && \strcasecmp($code, $defaultLanguage) === 0) {
                    continue;
                }
                $path = '/' . $code . '/products';
                $paths[$path] = $path;
            }
        }

        // products + category surfaces + a few locale /products homes.
        // Critical FPC still only seals `/`+`/products`; category paths feed
        // deferred surface prime. Hot PDP paths belong to theme:fpc-prewarm,
        // not Worker bootstrap (bestSellerCards would rebuild the catalog).
        return \array_slice(\array_values($paths), 0, 16);
    }

    /**
     * Live nav tree → `/categories` + top-level `/category/**` (+ one child level).
     * Fail-open to `/categories` only (route index, not a site-specific slug).
     *
     * @return list<string>
     */
    private function resolveCategoryWarmupPaths(): array
    {
        $paths = ['/categories' => '/categories'];

        try {
            /** @var StorefrontAllMenuCategoryTreeService $nav */
            $nav = ObjectManager::getInstance(StorefrontAllMenuCategoryTreeService::class);
            $tree = $nav->navTree(Website::ID_DEFAULT);
            if (\is_array($tree) && $tree !== []) {
                $this->collectCategoryRoutesFromNav($tree, $paths, 0, 1);
            }
        } catch (\Throwable) {
            // Fail-open: keep `/categories` index only.
        }

        return \array_values($paths);
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param array<string, string> $paths
     */
    private function collectCategoryRoutesFromNav(
        array $nodes,
        array &$paths,
        int $depth,
        int $maxDepth,
    ): void {
        foreach ($nodes as $node) {
            if (!\is_array($node)) {
                continue;
            }
            $route = $this->categoryRouteFromNavNode($node);
            if ($route !== null) {
                $paths[$route] = $route;
            }
            if ($depth >= $maxDepth) {
                continue;
            }
            $children = $node['children'] ?? null;
            if (\is_array($children) && $children !== []) {
                $this->collectCategoryRoutesFromNav($children, $paths, $depth + 1, $maxDepth);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private function categoryRouteFromNavNode(array $node): ?string
    {
        $meta = \is_array($node['meta'] ?? null) ? $node['meta'] : [];
        $metaPath = \trim((string)($meta['path'] ?? ''), '/');
        if ($metaPath !== '') {
            return '/category/' . $metaPath;
        }

        $url = \trim((string)($node['url'] ?? ''));
        if ($url === '') {
            return null;
        }
        $pathOnly = (string)(\parse_url($url, \PHP_URL_PATH) ?: $url);
        $pathOnly = '/' . \ltrim(\str_replace('\\', '/', $pathOnly), '/');
        if ($pathOnly === '/categories' || \str_starts_with($pathOnly, '/category/')) {
            return $pathOnly;
        }
        // Relative nav route before URL materialization: "category/…" or "categories"
        if ($url === 'categories') {
            return '/categories';
        }
        if (\str_starts_with($url, 'category/')) {
            return '/' . $url;
        }

        return null;
    }

    /**
     * @return array{language:string,currency:string,languages:list<string>}
     */
    private function resolveWebsiteDefaults(): array
    {
        $language = '';
        $currency = '';
        $languages = [];

        try {
            if (\class_exists(\Weline\Websites\Data\WebsiteData::class)
                && \class_exists(Website::class)
            ) {
                $snapshot = \Weline\Websites\Data\WebsiteData::readSharedSnapshotById(
                    Website::ID_DEFAULT
                );
                $website = \is_array($snapshot['website'] ?? null) ? $snapshot['website'] : [];
                $language = \trim((string)($website['default_language'] ?? ''));
                $currency = \trim((string)($website['default_currency'] ?? ''));
                $languages = \is_array($snapshot['language_codes'] ?? null)
                    ? \array_values(\array_map('strval', $snapshot['language_codes']))
                    : [];
            }
        } catch (\Throwable) {
        }

        if ($language === '') {
            try {
                $language = \trim(State::resolveWebsiteDefaultLanguage());
            } catch (\Throwable) {
            }
        }
        if ($currency === '') {
            try {
                $currency = \trim(State::resolveWebsiteDefaultCurrency());
            } catch (\Throwable) {
            }
        }

        return [
            'language' => $language,
            'currency' => $currency,
            'languages' => $languages,
        ];
    }
}
