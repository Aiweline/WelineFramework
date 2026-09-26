<?php

declare(strict_types=1);

namespace Weline\Blog\Extends\Module\Weline_Seo\SitemapUrlProvider;

use Weline\Blog\Service\BlogScopeResolver;
use Weline\Blog\Service\BlogSitemapUrlBuilder;
use Weline\Blog\Service\BlogContentResolver;
use Weline\Blog\Api\Data\BlogArticle;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Api\Sitemap\AbstractSitemapUrlProvider;
use Weline\Seo\Api\Sitemap\WebsiteDirectoryInterface;

/**
 * Blog sitemap URL provider.
 *
 * Emits per-site-locale rows with metadata.alternates (hreflang). Does not opt into
 * SitemapLocaleUrlExpander path expansion — Blog owns locale content / public slug pairing.
 */
final class BlogSitemapUrlProvider extends AbstractSitemapUrlProvider
{
    public function __construct(
        private readonly BlogSitemapUrlBuilder $builder,
        private readonly BlogScopeResolver $scope,
        private readonly WebsiteDirectoryInterface $websiteDirectory,
        private readonly ?BlogContentResolver $contentResolver = null,
    ) {
        parent::__construct();
    }

    public function getScope(): string
    {
        return 'blog_article';
    }

    public function getModule(): string
    {
        return 'Weline_Blog';
    }

    public function getWebsiteIds(): array
    {
        $ids = [];
        foreach ($this->websiteDirectory->all() as $website) {
            $websiteId = $website->id;
            if ($websiteId >= 0) {
                $ids[$websiteId] = $websiteId;
            }
        }

        return array_values($ids);
    }

    public function getUrlsForWebsite(int $websiteId): array
    {
        if ($websiteId < 0) {
            return [];
        }

        $website = $this->websiteDirectory->get($websiteId);
        if ($website === null) {
            return [];
        }
        $baseUrl = rtrim(trim($website->url), '/');
        $urls = $this->builder->buildForWebsite($websiteId, $baseUrl);
        foreach ($urls as &$url) {
            $loc = (string)($url['loc'] ?? '');
            // Relative Blog paths only — absolute locs from LocalizedUrlBuilder stay intact.
            if ($loc === '/blog' || str_starts_with($loc, '/blog/')) {
                $url['loc'] = $baseUrl . $loc;
            }
        }
        unset($url);
        $groups = [];
        foreach ($urls as $url) {
            $locale = trim((string)($url['locale'] ?? $url['metadata']['locale'] ?? ''));
            $groups[$locale . "\0" . (string)$url['loc']][] = $url;
        }
        $result = [];
        foreach ($groups as $rows) {
            if (count($rows) === 1) {
                $result[] = $rows[0];
                continue;
            }
            // Match the same public route as the frontend, including Post precedence over CMS.
            $loc = (string)$rows[0]['loc'];
            $resolved = null;
            if (str_starts_with($loc, $baseUrl . '/blog/') || preg_match('#/blog/#', $loc) === 1) {
                $path = (string)(parse_url($loc, PHP_URL_PATH) ?: '');
                $slug = '';
                if (str_contains($path, '/blog/')) {
                    $slug = trim(substr($path, (int)strpos($path, '/blog/') + strlen('/blog/')), '/');
                }
                $locale = trim((string)($rows[0]['locale'] ?? $rows[0]['metadata']['locale'] ?? ''));
                $resolver = $this->contentResolver ?? ObjectManager::getInstance(BlogContentResolver::class);
                $resolved = $slug !== '' ? $resolver->resolveBySlug($websiteId, $locale, $slug, $baseUrl) : null;
            }
            usort($rows, static function (array $left, array $right) use ($resolved): int {
                $rank = static function (array $row) use ($resolved): int {
                    $kind = (string)($row['metadata']['content_kind'] ?? '');
                    if ($resolved !== null && $kind === $resolved->contentKind && (int)($row['entity_id'] ?? 0) === $resolved->entityId()) {
                        return 0;
                    }
                    return $kind === BlogArticle::KIND_POST ? 1 : 2;
                };
                return ($rank($left) <=> $rank($right)) ?: strcmp((string)$left['url_key'], (string)$right['url_key']);
            });
            $result[] = $rows[0];
        }

        return $result;
    }

    public function getDescription(): string
    {
        return (string)__('博客文章 sitemap URL 提供器（多语 hreflang）');
    }

    public function supportsSiteLanguagePathExpansion(): bool
    {
        // Content is locale-owned; builder already emits locale rows + alternates.
        return false;
    }
}
