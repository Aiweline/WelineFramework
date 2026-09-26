<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

use Weline\Blog\Api\Data\BlogArticle;
use Weline\Blog\Api\Sitemap\BlogSitemapContentSourceInterface;
use Weline\Blog\Api\Uri\BlogNamespace;
use Weline\Blog\Model\Category;
use Weline\Blog\Model\Post;
use Weline\Blog\Model\Post\LocalDescription;
use Weline\Framework\Runtime\RequestContext;

final class BlogContentResolver implements BlogSitemapContentSourceInterface
{
    private const CTX_POST_BY_ID = 'blog.published_post.by_id.v1.';
    private const CTX_POST_BY_SLUG = 'blog.published_post.by_slug.v1.';
    private const CTX_SLUG_MISS = 'blog.published_post.slug_miss.v1.';
    private const CTX_CATEGORY_META = 'blog.category_meta.v1.';
    private const CTX_KEYWORDS = 'blog.post_keywords.v1.';
    private ?BlogContentCache $contentCache = null;

    public function __construct(
        private readonly Post $postModel,
        private readonly Category $categoryModel,
        private readonly CmsBlogPageAdapter $cmsAdapter,
        private readonly BlogCategoryAttributeService $categoryAttributes,
        private readonly BlogCategoryAdminService $categoryAdmin,
        private readonly BlogKeywordLocalizer $keywordLocalizer,
        private readonly LocalDescription $postLocalDescription,
        private readonly BlogSeoFactsBuilder $seoFacts,
    ) {
    }

    public function resolveBySlug(int $websiteId, string $locale, string $slug, string $baseUrl = ''): ?BlogArticle
    {
        $slug = trim(strtolower($slug), '/ ');
        if ($slug === '') {
            return null;
        }

        $post = $this->findPublishedPost($websiteId, $locale, $slug);
        if ($post === null) {
            $storageSlug = $this->storageSlugForLocale($slug, $locale);
            if ($storageSlug !== $slug) {
                $post = $this->findPublishedPost($websiteId, $locale, $storageSlug);
            }
        }
        if ($post === null) {
            $baseSlug = self::stripKnownLocaleSlugSuffix($slug);
            foreach ($this->contentLocaleCandidates($locale) as $candidate) {
                if ($candidate === trim(str_replace('-', '_', $locale))) {
                    continue;
                }
                $candidateStorage = $this->storageSlugForLocale($baseSlug, $candidate);
                foreach (array_unique([$baseSlug, $candidateStorage]) as $trySlug) {
                    $post = $this->findPublishedPost($websiteId, $candidate, $trySlug);
                    if ($post !== null) {
                        break 2;
                    }
                }
            }
        }
        if ($post !== null) {
            return $this->postToArticle($post, $baseUrl, $locale);
        }

        $cmsPage = $this->cmsAdapter->getPublishedPage($websiteId, $slug);
        if ($cmsPage !== null) {
            return $this->cmsAdapter->toBlogArticle($cmsPage, $baseUrl);
        }

        return null;
    }

    /**
     * @return list<BlogArticle>
     */
    public function listPublishedArticles(int $websiteId, string $locale, int $limit = 50, string $baseUrl = ''): array
    {
        return $this->collectArticles($websiteId, $locale, 0, $limit, $baseUrl);
    }

    /**
     * @return list<BlogArticle>
     */
    public function listPublishedArticlesByCategory(
        int $websiteId,
        string $locale,
        int $categoryId,
        int $limit = 50,
        string $baseUrl = '',
    ): array {
        return $this->collectArticles($websiteId, $locale, max(0, $categoryId), $limit, $baseUrl);
    }

    /**
     * @return list<BlogArticle>
     */
    public function listRelatedArticles(
        int $websiteId,
        string $locale,
        BlogArticle $article,
        int $limit = 4,
        string $baseUrl = '',
    ): array {
        $categoryId = (int)($article->sourceRef['category_id'] ?? 0);
        $candidates = $categoryId > 0
            ? $this->listPublishedArticlesByCategory($websiteId, $locale, $categoryId, $limit + 5, $baseUrl)
            : $this->listPublishedArticles($websiteId, $locale, $limit + 5, $baseUrl);

        $selfId = $article->entityId();
        $out = [];
        foreach ($candidates as $candidate) {
            if ($candidate->entityId() === $selfId && $candidate->contentKind === $article->contentKind) {
                continue;
            }
            $out[] = $candidate;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listCategories(int $websiteId = 0, string $locale = ''): array
    {
        $tree = $this->categoryAdmin->tree($websiteId, $locale);

        $map = function (array $nodes) use (&$map): array {
            $out = [];
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $id = (int)($node['category_id'] ?? 0);
                $slug = trim((string)($node['slug'] ?? $node['code'] ?? ''));
                if ($id <= 0 || $slug === '') {
                    continue;
                }
                $children = $map(is_array($node['nodes'] ?? null) ? $node['nodes'] : []);
                $out[] = [
                    'category_id' => $id,
                    'parent_id' => max(0, (int)($node['parent_id'] ?? 0)),
                    'name' => (string)($node['name'] ?? $slug),
                    'slug' => $slug,
                    'url' => BlogNamespace::categoryPublicPath($slug),
                    'sort_order' => (int)($node['sort_order'] ?? $node['position'] ?? 0),
                    'level' => (int)($node['level'] ?? 1),
                    'children' => $children,
                    'nodes' => $children,
                ];
            }

            return $out;
        };

        return $map($tree);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function resolveCategoryBySlug(int $websiteId, string $slug, string $locale = ''): ?array
    {
        $slug = trim(strtolower($slug), '/ ');
        if ($slug === '') {
            return null;
        }
        $model = clone $this->categoryModel;
        $row = $model->clearData()->reset()
            ->where(Category::schema_fields_WEBSITE_ID, BlogWebsiteScope::websiteIdsForQuery($websiteId), 'IN')
            ->where(Category::schema_fields_SLUG, $slug)
            ->find()
            ->fetchArray();
        if (!is_array($row) || (int)($row[Category::schema_fields_ID] ?? 0) <= 0) {
            return null;
        }

        $categoryId = (int)$row[Category::schema_fields_ID];
        $fallbackName = (string)($row[Category::schema_fields_NAME] ?? $slug);
        $displayName = $this->categoryAttributes->resolveDisplayName(
            $websiteId,
            $categoryId,
            $locale,
            $fallbackName,
        );

        return [
            'category_id' => $categoryId,
            'parent_id' => max(0, (int)($row[Category::schema_fields_PARENT_ID] ?? 0)),
            'name' => $displayName,
            'slug' => (string)($row[Category::schema_fields_SLUG] ?? $slug),
            'url' => BlogNamespace::categoryPublicPath((string)($row[Category::schema_fields_SLUG] ?? $slug)),
        ];
    }

    /**
     * @return list<BlogArticle>
     */
    private function collectArticles(
        int $websiteId,
        string $locale,
        int $categoryId,
        int $limit,
        string $baseUrl,
    ): array {
        $limit = max(1, $limit);
        $articles = [];
        $posts = $this->listPublishedPosts($websiteId, $locale, $limit, $categoryId);
        $this->warmCategoryMetaForPosts($posts, $locale);
        $this->warmKeywordsForPosts($posts);
        foreach ($posts as $post) {
            $this->rememberPublishedPostRow($post);
            $articles[] = $this->postToArticle($post, $baseUrl, $locale);
        }

        if ($categoryId <= 0) {
            $cmsPages = [];
            $slugs = [];
            foreach ($this->cmsAdapter->listPublishedBlogPages($websiteId, 1, $limit) as $page) {
                if (!is_array($page)) {
                    continue;
                }
                $slug = trim(strtolower((string)($page['slug'] ?? '')));
                if ($slug === '') {
                    continue;
                }
                $cmsPages[] = $page;
                $slugs[] = $slug;
            }
            $existingBySlug = $this->findPublishedPostsBySlugs($websiteId, $locale, $slugs);
            foreach ($cmsPages as $page) {
                $slug = trim(strtolower((string)($page['slug'] ?? '')));
                if ($slug === '' || isset($existingBySlug[$slug])) {
                    continue;
                }
                $articles[] = $this->cmsAdapter->toBlogArticle($page, $baseUrl);
            }
        }

        $requestLocale = trim(str_replace('-', '_', $locale));
        usort($articles, static function (BlogArticle $a, BlogArticle $b) use ($requestLocale): int {
            $aPreferred = ($requestLocale !== '' && $a->locale === $requestLocale) ? 0 : 1;
            $bPreferred = ($requestLocale !== '' && $b->locale === $requestLocale) ? 0 : 1;
            if ($aPreferred !== $bPreferred) {
                return $aPreferred <=> $bPreferred;
            }

            return strcmp((string)($b->publishedAt ?? ''), (string)($a->publishedAt ?? ''));
        });

        return array_slice($articles, 0, $limit);
    }

    private function storageSlugForLocale(string $slug, string $locale): string
    {
        $slug = trim(strtolower($slug), '/ ');
        $suffix = self::localeSlugSuffix($locale);
        if ($suffix === '' || $slug === '' || str_ends_with($slug, '-' . $suffix)) {
            return $slug;
        }

        return $slug . '-' . $suffix;
    }

    public function publicSlugForLocale(string $slug, string $locale): string
    {
        $slug = trim(strtolower($slug), '/ ');
        $suffix = self::localeSlugSuffix($locale);
        if ($suffix !== '' && str_ends_with($slug, '-' . $suffix)) {
            return substr($slug, 0, -(strlen($suffix) + 1));
        }

        return self::stripKnownLocaleSlugSuffix($slug);
    }

    private function isEnglishLocale(string $locale): bool
    {
        $locale = strtolower(str_replace('-', '_', trim($locale)));

        return $locale === 'en' || str_starts_with($locale, 'en_');
    }

    /** @return array<string, string> locale => storage slug suffix (without leading dash) */
    public static function localeSlugSuffixMap(): array
    {
        return [
            'en_US' => 'en',
            'ar_SA' => 'ar',
            'bn_BD' => 'bn',
            'es_ES' => 'es',
            'fr_FR' => 'fr',
            'hi_IN' => 'hi',
            'id_ID' => 'id',
            'pt_BR' => 'pt',
            'ur_PK' => 'ur',
        ];
    }

    private static function localeSlugSuffix(string $locale): string
    {
        $locale = trim(str_replace('-', '_', $locale));

        return self::localeSlugSuffixMap()[$locale] ?? '';
    }

    private static function stripKnownLocaleSlugSuffix(string $slug): string
    {
        foreach (self::localeSlugSuffixMap() as $suffix) {
            $tail = '-' . $suffix;
            if ($suffix !== '' && str_ends_with($slug, $tail)) {
                return substr($slug, 0, -strlen($tail));
            }
        }

        return $slug;
    }

    /** Site source / default content locale used when a storefront locale has no post rows. */
    private function defaultContentLocale(): string
    {
        return 'zh_Hans_CN';
    }

    /**
     * Prefer the request locale; fall back to default content locale when missing translations.
     *
     * @return list<string>
     */
    private function contentLocaleCandidates(string $locale): array
    {
        $locale = trim(str_replace('-', '_', $locale));
        $candidates = [];
        if ($locale !== '') {
            $candidates[] = $locale;
        }
        $default = $this->defaultContentLocale();
        if ($default !== '' && !in_array($default, $candidates, true)) {
            $candidates[] = $default;
        }

        return $candidates;
    }

    /**
     * Pair locale storage variants of the same article for listing dedupe.
     *
     * @param array<string, mixed> $row
     */
    private function contentIdentityKey(array $row): string
    {
        $slug = trim(strtolower((string)($row[Post::schema_fields_SLUG] ?? '')));

        return self::stripKnownLocaleSlugSuffix($slug);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findPublishedPost(int $websiteId, string $locale, string $slug): ?array
    {
        $slug = trim(strtolower($slug));
        if ($slug === '') {
            return null;
        }
        $slugKey = $this->postSlugContextKey($websiteId, $locale, $slug);
        if (RequestContext::has($slugKey)) {
            $cached = RequestContext::get($slugKey);

            return \is_array($cached) ? $cached : null;
        }
        $missKey = self::CTX_SLUG_MISS . $websiteId . '.' . $locale . '.' . $slug;
        if (RequestContext::get($missKey) === true) {
            return null;
        }

        $row = $this->cache()->remember($websiteId, $locale, 'post_slug', [$slug], function () use ($websiteId, $locale, $slug): mixed {
            $query = clone $this->postModel;
            $query->clearData()->reset()
                ->where(Post::schema_fields_WEBSITE_ID, BlogWebsiteScope::websiteIdsForQuery($websiteId), 'IN')
                ->where(Post::schema_fields_SLUG, $slug)
                ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED);
            if ($locale !== '') {
                $query->where(Post::schema_fields_LOCALE, $locale);
            }
            $hit = $query->find()->fetchArray();
            if (is_array($hit) && $hit !== [] && (int)($hit[Post::schema_fields_ID] ?? 0) > 0) {
                return $hit;
            }

            return [];
        });
        if (!is_array($row)
            || $row === []
            || (int)($row[Post::schema_fields_ID] ?? 0) <= 0
        ) {
            RequestContext::set($missKey, true);

            return null;
        }

        $this->rememberPublishedPostRow($row);
        RequestContext::set($slugKey, $row);

        return $row;
    }

    /**
     * @param list<string> $slugs
     * @return array<string, array<string, mixed>> slug => row
     */
    private function findPublishedPostsBySlugs(int $websiteId, string $locale, array $slugs): array
    {
        $slugs = \array_values(\array_unique(\array_filter(
            \array_map(static fn(string $slug): string => \trim(\strtolower($slug)), $slugs),
            static fn(string $slug): bool => $slug !== '',
        )));
        $found = [];
        $unknown = [];
        foreach ($slugs as $slug) {
            $slugKey = $this->postSlugContextKey($websiteId, $locale, $slug);
            if (RequestContext::has($slugKey)) {
                $cached = RequestContext::get($slugKey);
                if (\is_array($cached)) {
                    $found[$slug] = $cached;
                }
                continue;
            }
            $missKey = self::CTX_SLUG_MISS . $websiteId . '.' . $locale . '.' . $slug;
            if (RequestContext::get($missKey) === true) {
                continue;
            }
            $unknown[] = $slug;
        }
        if ($unknown === []) {
            return $found;
        }

        sort($unknown, SORT_STRING);
        $rows = $this->cache()->remember($websiteId, $locale, 'post_slugs', $unknown, function () use ($websiteId, $locale, $unknown): mixed {
            $candidates = $this->contentLocaleCandidates($locale);
            $query = clone $this->postModel;
            $query->clearData()->reset()
                ->where(Post::schema_fields_WEBSITE_ID, BlogWebsiteScope::websiteIdsForQuery($websiteId), 'IN')
                ->where(Post::schema_fields_SLUG, $unknown, 'IN')
                ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED);
            if ($candidates !== []) {
                $query->where(Post::schema_fields_LOCALE, $candidates, 'IN');
            }
            return $query->select()->fetchArray();
        });
        $hitSlugs = [];
        $priority = array_flip($this->contentLocaleCandidates($locale));
        $bestBySlug = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row) || (int)($row[Post::schema_fields_ID] ?? 0) <= 0) {
                continue;
            }
            $slug = \trim(\strtolower((string)($row[Post::schema_fields_SLUG] ?? '')));
            if ($slug === '') {
                continue;
            }
            $rowLocale = (string)($row[Post::schema_fields_LOCALE] ?? '');
            $rank = $priority[$rowLocale] ?? 1000;
            if (!isset($bestBySlug[$slug]) || $rank < ($bestBySlug[$slug]['_rank'] ?? 1000)) {
                $bestBySlug[$slug] = $row + ['_rank' => $rank];
            }
        }
        foreach ($bestBySlug as $slug => $row) {
            unset($row['_rank']);
            $this->rememberPublishedPostRow($row);
            RequestContext::set($this->postSlugContextKey($websiteId, $locale, $slug), $row);
            $found[$slug] = $row;
            $hitSlugs[$slug] = true;
        }
        foreach ($unknown as $slug) {
            if (!isset($hitSlugs[$slug])) {
                RequestContext::set(self::CTX_SLUG_MISS . $websiteId . '.' . $locale . '.' . $slug, true);
            }
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rememberPublishedPostRow(array $row): void
    {
        $postId = (int)($row[Post::schema_fields_ID] ?? 0);
        if ($postId <= 0) {
            return;
        }
        $websiteId = (int)($row[Post::schema_fields_WEBSITE_ID] ?? 0);
        $locale = (string)($row[Post::schema_fields_LOCALE] ?? '');
        $slug = \trim(\strtolower((string)($row[Post::schema_fields_SLUG] ?? '')));
        RequestContext::set(self::CTX_POST_BY_ID . $postId, $row);
        if ($slug !== '') {
            RequestContext::set($this->postSlugContextKey($websiteId, $locale, $slug), $row);
            RequestContext::remove(self::CTX_SLUG_MISS . $websiteId . '.' . $locale . '.' . $slug);
        }
        try {
            $model = clone $this->postModel;
            $model->hydrateLoadedRow($row);
        } catch (\Throwable) {
            // Identity-map hydrate is best-effort.
        }
    }

    private function postSlugContextKey(int $websiteId, string $locale, string $slug): string
    {
        return self::CTX_POST_BY_SLUG . $websiteId . '.' . $locale . '.' . $slug;
    }

    /**
     * @param list<array<string, mixed>> $posts
     */
    private function warmCategoryMetaForPosts(array $posts, string $locale): void
    {
        $displayLocale = trim(str_replace('-', '_', $locale));
        $groups = [];
        foreach ($posts as $post) {
            $id = (int)($post[Post::schema_fields_CATEGORY_ID] ?? 0);
            $websiteId = (int)($post[Post::schema_fields_WEBSITE_ID] ?? 0);
            $metaLocale = $displayLocale !== '' ? $displayLocale : (string)($post[Post::schema_fields_LOCALE] ?? '');
            if ($id > 0 && !RequestContext::has(self::CTX_CATEGORY_META . $id . '.' . $websiteId . '.' . $metaLocale)) {
                $groups[$websiteId][$metaLocale][$id] = $id;
            }
        }
        foreach ($groups as $websiteId => $locales) {
            foreach ($locales as $rowLocale => $ids) {
                sort($ids, SORT_NUMERIC);
                $rows = $this->cache()->remember((int)$websiteId, '', 'category_rows', $ids, function () use ($ids): array {
                    $model = clone $this->categoryModel;
                    $rows = $model->clearData()->reset()->where(Category::schema_fields_ID, $ids, 'IN')->select()->fetchArray();
                    return is_array($rows) ? $rows : [];
                });
                // EAV owns localized values and their changed invalidation; share only native facts here.
                $names = $this->categoryAttributes->readNameMap((int)$websiteId, $ids, (string)$rowLocale);
                $map = array_fill_keys($ids, []);
                foreach ($rows as $row) {
                    $id = (int)($row[Category::schema_fields_ID] ?? 0);
                    $slug = trim((string)($row[Category::schema_fields_SLUG] ?? ''));
                    $map[$id] = ['name' => $names[$id] ?? '', 'fallback_name' => (string)($row[Category::schema_fields_NAME] ?? ''),
                        'slug' => $slug, 'url' => $slug !== '' ? BlogNamespace::categoryPublicPath($slug) : ''];
                }
                foreach ($map as $id => $meta) {
                    if ($meta !== [] && $meta['name'] === '') {
                        $meta['name'] = (string)__($meta['fallback_name']);
                    }
                    unset($meta['fallback_name']);
                    RequestContext::set(self::CTX_CATEGORY_META . $id . '.' . $websiteId . '.' . $rowLocale, $meta);
                }
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listPublishedPosts(int $websiteId, string $locale, int $limit, int $categoryId = 0): array
    {
        return $this->cache()->remember($websiteId, $locale, 'post_list', [$limit, $categoryId, 'content_fallback_v2'], function () use ($websiteId, $locale, $limit, $categoryId): array {
            $candidates = $this->contentLocaleCandidates($locale);
            $categoryIds = [];
            if ($categoryId > 0) {
                $categoryIds = $this->categoryAdmin->selfAndDescendantIds($websiteId, $categoryId);
                if ($categoryIds === []) {
                    $categoryIds = [$categoryId];
                }
            }

            $out = [];
            $seen = [];
            foreach ($candidates as $candidate) {
                if (count($out) >= $limit) {
                    break;
                }
                $need = $limit - count($out);
                $query = clone $this->postModel;
                $query->clearData()->reset()
                    ->where(Post::schema_fields_WEBSITE_ID, BlogWebsiteScope::websiteIdsForQuery($websiteId), 'IN')
                    ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED);
                if ($candidate !== '') {
                    $query->where(Post::schema_fields_LOCALE, $candidate);
                }
                if ($categoryIds !== []) {
                    $query->where(Post::schema_fields_CATEGORY_ID, $categoryIds, 'IN');
                }
                $rows = $query->order(Post::schema_fields_PUBLISHED_AT, 'DESC')
                    ->limit(max($need * 3, $need))
                    ->select()
                    ->fetchArray();
                if (!is_array($rows)) {
                    continue;
                }
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $key = $this->contentIdentityKey($row);
                    if ($key === '' || isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $out[] = $row;
                    if (count($out) >= $limit) {
                        break;
                    }
                }
            }

            return $out;
        });
    }

    /**
     * @param array<string, mixed> $row
     */
    private function postToArticle(array $row, string $baseUrl, string $displayLocale = ''): BlogArticle
    {
        $contentLocale = (string)($row[Post::schema_fields_LOCALE] ?? '');
        $displayLocale = trim(str_replace('-', '_', $displayLocale !== '' ? $displayLocale : $contentLocale));
        $this->warmCategoryMetaForPosts([$row], $displayLocale);
        $this->warmKeywordsForPosts([$row]);
        $storageSlug = (string)($row[Post::schema_fields_SLUG] ?? '');
        $slug = $this->publicSlugForLocale($storageSlug, $contentLocale);
        $path = BlogNamespace::publicPath($slug);
        $absolute = $this->absoluteUrl($path, $baseUrl);
        $categoryId = (int)($row[Post::schema_fields_CATEGORY_ID] ?? 0);
        $categoryMeta = $this->resolveCategoryMeta(
            $categoryId,
            (int)($row[Post::schema_fields_WEBSITE_ID] ?? 0),
            $displayLocale !== '' ? $displayLocale : $contentLocale,
        );

        return new BlogArticle(
            contentKind: BlogArticle::KIND_POST,
            websiteId: (int)($row[Post::schema_fields_WEBSITE_ID] ?? 0),
            locale: $contentLocale,
            slug: $slug,
            identifier: BlogNamespace::identifierFromSlug($slug),
            title: (string)($row[Post::schema_fields_TITLE] ?? ''),
            excerpt: (string)($row[Post::schema_fields_EXCERPT] ?? ''),
            publishedAt: (string)($row[Post::schema_fields_PUBLISHED_AT] ?? '') ?: null,
            updatedAt: (string)($row[Post::schema_fields_UPDATED_AT] ?? '') ?: null,
            author: (string)($row[Post::schema_fields_AUTHOR] ?? '') ?: null,
            coverImage: (string)($row[Post::schema_fields_COVER_IMAGE] ?? '') ?: null,
            categories: ($categoryMeta['name'] ?? '') !== '' ? [(string)$categoryMeta['name']] : [],
            canonicalUrl: $absolute !== '' ? $absolute : $path,
            publicUrl: $path,
            sourceRef: [
                'kind' => BlogArticle::KIND_POST,
                'post_id' => (int)($row[Post::schema_fields_ID] ?? 0),
                'storage_slug' => $storageSlug,
                'category_id' => $categoryId,
                'category_slug' => (string)($categoryMeta['slug'] ?? ''),
                'category_url' => (string)($categoryMeta['url'] ?? ''),
            ],
            keywords: $this->resolveKeywords(
                (int)($row[Post::schema_fields_ID] ?? 0),
                $displayLocale !== '' ? $displayLocale : $contentLocale,
                (string)($row[Post::schema_fields_KEYWORDS] ?? ''),
            ),
            authorUrl: $this->resolveAuthorUrl($row, $displayLocale !== '' ? $displayLocale : $contentLocale, $absolute !== '' ? $absolute : $baseUrl),
            authorBio: $this->resolveAuthorBio($row, $displayLocale !== '' ? $displayLocale : $contentLocale),
            authorJobTitle: $this->resolveAuthorJobTitle($row, $displayLocale !== '' ? $displayLocale : $contentLocale, $absolute !== '' ? $absolute : $baseUrl),
            authorSameAs: $this->resolveAuthorSameAs($row, $displayLocale !== '' ? $displayLocale : $contentLocale, $absolute !== '' ? $absolute : $baseUrl),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveAuthorUrl(array $row, string $locale, string $canonicalOrBase): ?string
    {
        $url = trim((string)($row[Post::schema_fields_AUTHOR_URL] ?? ''));
        if ($url !== '') {
            return $url;
        }
        if (trim((string)($row[Post::schema_fields_AUTHOR] ?? '')) === '') {
            return null;
        }

        return $this->seoFacts->defaultAuthorIdentity($locale, $canonicalOrBase)['url'] ?: null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveAuthorBio(array $row, string $locale): ?string
    {
        $bio = trim((string)($row[Post::schema_fields_AUTHOR_BIO] ?? ''));
        if ($bio !== '') {
            return $bio;
        }
        if (trim((string)($row[Post::schema_fields_AUTHOR] ?? '')) === '') {
            return null;
        }

        return $this->seoFacts->defaultAuthorIdentity($locale, '')['bio'] ?: null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveAuthorJobTitle(array $row, string $locale, string $canonicalOrBase): ?string
    {
        $job = trim((string)($row[Post::schema_fields_AUTHOR_JOB_TITLE] ?? ''));
        if ($job !== '') {
            return $job;
        }
        if (trim((string)($row[Post::schema_fields_AUTHOR] ?? '')) === '') {
            return null;
        }

        return $this->seoFacts->defaultAuthorIdentity($locale, $canonicalOrBase)['jobTitle'] ?: null;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>|null
     */
    private function resolveAuthorSameAs(array $row, string $locale, string $canonicalOrBase): ?array
    {
        $sameAs = BlogArticle::normalizeSameAs($row[Post::schema_fields_AUTHOR_SAME_AS] ?? null);
        if ($sameAs !== null && $sameAs !== []) {
            return $sameAs;
        }
        if (trim((string)($row[Post::schema_fields_AUTHOR] ?? '')) === '') {
            return null;
        }

        return $this->seoFacts->defaultAuthorIdentity($locale, $canonicalOrBase)['sameAs'];
    }

    private function resolveKeywords(int $postId, string $locale, string $fallback): ?string
    {
        $localKeywords = null;
        if ($postId > 0 && $locale !== '') {
            $localKeywords = $this->loadLocalKeywords($postId, $locale);
        }

        return $this->keywordLocalizer->localizeKeywordsString($fallback, $locale, $localKeywords);
    }

    private function loadLocalKeywords(int $postId, string $locale): ?string
    {
        $key = self::CTX_KEYWORDS . $postId . '.' . $locale;
        if (!RequestContext::has($key)) {
            $this->warmKeywordsForPosts([['post_id' => $postId, 'locale' => $locale, 'website_id' => 0]]);
        }
        return RequestContext::get($key);
    }

    /** @param list<array<string,mixed>> $posts */
    private function warmKeywordsForPosts(array $posts): void
    {
        $groups = [];
        foreach ($posts as $post) {
            $id = (int)($post[Post::schema_fields_ID] ?? 0);
            $locale = (string)($post[Post::schema_fields_LOCALE] ?? '');
            $websiteId = (int)($post[Post::schema_fields_WEBSITE_ID] ?? 0);
            if ($id > 0 && $locale !== '' && !RequestContext::has(self::CTX_KEYWORDS . $id . '.' . $locale)) {
                $groups[$websiteId][$locale][$id] = $id;
            }
        }
        foreach ($groups as $websiteId => $locales) {
            foreach ($locales as $locale => $ids) {
                sort($ids, SORT_NUMERIC);
                try {
                $map = $this->cache()->remember((int)$websiteId, (string)$locale, 'post_keywords', $ids, function () use ($ids, $locale): array {
                    $local = clone $this->postLocalDescription;
                    $rows = $local->clearData()->reset()->where(LocalDescription::schema_fields_ID, $ids, 'IN')
                        ->where(LocalDescription::schema_fields_local_code, $locale)->select()->fetchArray();
                    $map = array_fill_keys($ids, null);
                    foreach (is_array($rows) ? $rows : [] as $row) {
                        $id = (int)($row[LocalDescription::schema_fields_ID] ?? 0);
                        $value = trim((string)($row[LocalDescription::schema_fields_KEYWORDS] ?? ''));
                        $map[$id] = $value !== '' ? $value : null;
                    }
                    return $map;
                });
                } catch (\Throwable) {
                    // Keep the previous request-local fallback; never cache a failed read in L1/L2.
                    $map = array_fill_keys($ids, null);
                }
                foreach ($map as $id => $value) {
                    RequestContext::set(self::CTX_KEYWORDS . $id . '.' . $locale, $value);
                }
            }
        }
    }

    private function cache(): BlogContentCache
    {
        return $this->contentCache ??= \Weline\Framework\Manager\ObjectManager::getInstance(BlogContentCache::class);
    }

    /**
     * @return array{name?:string,slug?:string,url?:string}
     */
    private function resolveCategoryMeta(int $categoryId, int $websiteId = 0, string $locale = ''): array
    {
        if ($categoryId <= 0) {
            return [];
        }
        $cacheKey = self::CTX_CATEGORY_META . $categoryId . '.' . $websiteId . '.' . $locale;
        if (RequestContext::has($cacheKey)) {
            $cached = RequestContext::get($cacheKey);

            return \is_array($cached) ? $cached : [];
        }
        $model = clone $this->categoryModel;
        $model->clearData()->reset()->load($categoryId);
        if ($model->getCategoryId() <= 0) {
            RequestContext::set($cacheKey, []);

            return [];
        }
        $slug = trim((string)($model->getData(Category::schema_fields_SLUG) ?? ''));
        $fallbackName = trim((string)($model->getData(Category::schema_fields_NAME) ?? ''));
        if ($websiteId <= 0) {
            $websiteId = (int)($model->getData(Category::schema_fields_WEBSITE_ID) ?? 0);
        }
        $name = $this->categoryAttributes->resolveDisplayName(
            $websiteId,
            $categoryId,
            $locale,
            $fallbackName,
        );

        $meta = [
            'name' => $name,
            'slug' => $slug,
            'url' => $slug !== '' ? BlogNamespace::categoryPublicPath($slug) : '',
        ];
        RequestContext::set($cacheKey, $meta);

        return $meta;
    }

    private function resolveCategoryName(int $categoryId, int $websiteId = 0, string $locale = ''): string
    {
        return (string)($this->resolveCategoryMeta($categoryId, $websiteId, $locale)['name'] ?? '');
    }

    private function absoluteUrl(string $path, string $baseUrl): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            return $path;
        }

        return $baseUrl . '/' . ltrim($path, '/');
    }
}
