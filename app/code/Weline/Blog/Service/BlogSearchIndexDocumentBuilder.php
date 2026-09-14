<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

use Weline\Blog\Api\Data\BlogArticle;
use Weline\Blog\Api\Uri\BlogNamespace;
use Weline\Blog\Model\Category;
use Weline\Blog\Model\Post;
use Weline\Search\Dto\IndexDocument;
use Weline\Search\Dto\SearchRequest;

final class BlogSearchIndexDocumentBuilder
{
    public function __construct(
        private readonly Post $postModel,
        private readonly Category $categoryModel,
        private readonly BlogContentResolver $resolver,
        private readonly CmsBlogPageAdapter $cmsAdapter,
        private readonly BlogSearchHitPresenter $presenter,
    ) {
    }

    /**
     * @return list<IndexDocument>
     */
    public function buildForRequest(SearchRequest $request): array
    {
        $websiteId = max(0, $request->websiteId);
        $locale = trim($request->locale);
        $documents = [];

        $model = clone $this->postModel;
        $query = $model->clearData()->reset()
            ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED);
        $websiteIds = BlogWebsiteScope::websiteIdsForQuery($websiteId);
        $query->where(Post::schema_fields_WEBSITE_ID, $websiteIds, 'IN');
        if ($locale !== '') {
            $query->where(Post::schema_fields_LOCALE, $locale);
        }
        $rows = $query->order(Post::schema_fields_UPDATED_AT, 'DESC')->select()->fetchArray();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $document = $this->fromPostRow($row);
                if ($document !== null) {
                    $documents[] = $document;
                }
            }
        }

        foreach ($this->cmsAdapter->listPublishedBlogPages($websiteId, 1, 500) as $page) {
            if (!is_array($page)) {
                continue;
            }
            $slug = trim(strtolower((string)($page['slug'] ?? '')));
            if ($slug === '') {
                continue;
            }
            $article = $this->resolver->resolveBySlug($websiteId, $locale, $slug);
            if ($article === null) {
                continue;
            }
            $documents[] = $this->fromArticle($article);
        }

        return $documents;
    }

    /**
     * @param array<string,mixed> $row
     */
    public function fromPostRow(array $row): ?IndexDocument
    {
        if ((string)($row[Post::schema_fields_STATUS] ?? '') !== Post::STATUS_PUBLISHED) {
            return null;
        }

        $storageSlug = trim(strtolower((string)($row[Post::schema_fields_SLUG] ?? '')));
        if ($storageSlug === '') {
            return null;
        }
        $locale = (string)($row[Post::schema_fields_LOCALE] ?? '');
        $slug = $this->resolver->publicSlugForLocale($storageSlug, $locale);

        $categoryId = (int)($row[Post::schema_fields_CATEGORY_ID] ?? 0);
        $article = new BlogArticle(
            contentKind: BlogArticle::KIND_POST,
            websiteId: (int)($row[Post::schema_fields_WEBSITE_ID] ?? 0),
            locale: $locale,
            slug: $slug,
            identifier: BlogNamespace::identifierFromSlug($slug),
            title: (string)($row[Post::schema_fields_TITLE] ?? ''),
            excerpt: (string)($row[Post::schema_fields_EXCERPT] ?? ''),
            publishedAt: (string)($row[Post::schema_fields_PUBLISHED_AT] ?? '') ?: null,
            updatedAt: (string)($row[Post::schema_fields_UPDATED_AT] ?? '') ?: null,
            author: (string)($row[Post::schema_fields_AUTHOR] ?? '') ?: null,
            coverImage: (string)($row[Post::schema_fields_COVER_IMAGE] ?? '') ?: null,
            categories: $this->categoryNames($categoryId),
            canonicalUrl: BlogNamespace::publicPath($slug),
            publicUrl: BlogNamespace::publicPath($slug),
            sourceRef: [
                'kind' => BlogArticle::KIND_POST,
                'post_id' => (int)($row[Post::schema_fields_ID] ?? 0),
                'storage_slug' => $storageSlug,
                'category_id' => $categoryId,
            ],
            keywords: (string)($row[Post::schema_fields_KEYWORDS] ?? '') ?: null,
            authorUrl: (string)($row[Post::schema_fields_AUTHOR_URL] ?? '') ?: null,
            authorBio: (string)($row[Post::schema_fields_AUTHOR_BIO] ?? '') ?: null,
            authorJobTitle: (string)($row[Post::schema_fields_AUTHOR_JOB_TITLE] ?? '') ?: null,
            authorSameAs: BlogArticle::normalizeSameAs($row[Post::schema_fields_AUTHOR_SAME_AS] ?? null),
        );

        return $this->fromArticle($article);
    }

    public function fromArticle(BlogArticle $article): IndexDocument
    {
        $hit = $this->presenter->prepareHits([$article])[0] ?? [];
        $keywords = array_values(array_filter([
            $article->slug,
            (string)($article->keywords ?? ''),
            $article->excerpt,
        ], static fn(string $value): bool => trim($value) !== ''));

        $payload = is_array($hit) ? $hit : [];
        $categoryId = (int)($article->sourceRef['category_id'] ?? 0);
        $payload['category_id'] = $categoryId;
        $payload['blog_category_id'] = $categoryId;
        $payload['categories'] = $article->categories;
        $payload['content_locale'] = $article->locale;

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return new IndexDocument(
            indexer: 'blog',
            entityType: 'blog',
            entityId: $this->entityKey($article),
            websiteId: max(0, $article->websiteId),
            storeId: 0,
            channelId: 0,
            // Keep the article content locale so storefront search does not
            // surface zh_Hans_CN posts under en_US (and other) queries.
            locale: trim($article->locale),
            currency: '',
            title: $article->title,
            keywords: $keywords,
            url: $article->publicUrl,
            payload: $payload,
            status: 'published',
            updatedAt: (string)($article->updatedAt ?? $article->publishedAt ?? date('Y-m-d H:i:s')),
            documentVersion: 1,
            payloadHash: hash('sha256', $payloadJson),
        );
    }

    private function entityKey(BlogArticle $article): string
    {
        if ($article->contentKind === BlogArticle::KIND_CMS) {
            return 'cms:' . max(0, (int)($article->sourceRef['cms_page_id'] ?? 0));
        }

        return 'post:' . max(0, (int)($article->sourceRef['post_id'] ?? 0));
    }

    /**
     * @return list<string>
     */
    private function categoryNames(int $categoryId): array
    {
        if ($categoryId <= 0) {
            return [];
        }
        $model = clone $this->categoryModel;
        $model->clearData()->reset()->load($categoryId);
        if ($model->getCategoryId() <= 0) {
            return [];
        }
        $name = trim((string)($model->getData(Category::schema_fields_NAME) ?? ''));

        return $name !== '' ? [$name] : [];
    }
}
