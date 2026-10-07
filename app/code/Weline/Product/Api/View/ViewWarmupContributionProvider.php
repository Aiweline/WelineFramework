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
    public function contribution(): ViewWarmupContribution
    {
        return new ViewWarmupContribution(
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

        // products + category surfaces from nav tree + a few locale /products homes.
        // Critical FPC still only seals `/`+`/products`; category paths feed
        // deferred `category_surface_prime` (own budget, out of UC wall clock).
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
