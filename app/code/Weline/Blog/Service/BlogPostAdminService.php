<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

use Weline\Blog\Model\Category;
use Weline\Blog\Model\Post;

final class BlogPostAdminService
{
    public function __construct(
        private readonly Post $postModel,
        private readonly Category $categoryModel,
        private readonly BlogSlugConflictGuard $slugGuard,
        private readonly BlogPostUrlNotifier $urlNotifier,
        private readonly BlogPostCmsEditorService $cmsEditor,
    ) {
    }

    /**
     * @return array{items:list<array<string,mixed>>,pagination:mixed,total:int}
     */
    public function listing(
        int $websiteId = 0,
        int $page = 1,
        int $pageSize = 30,
        string $status = '',
        string $locale = '',
        string $search = '',
    ): array {
        $model = clone $this->postModel;
        $query = $model->clearData()->reset();
        $this->applyListingFilters($query, $websiteId, $status, $locale, $search);
        $rows = $query->order(Post::schema_fields_UPDATED_AT, 'DESC')
            ->pagination(max(1, $page), max(1, $pageSize))
            ->select();

        return [
            'items' => $rows->fetchArray(),
            'pagination' => $rows->getPagination(),
            'total' => (int)($rows->getPagination()['total'] ?? 0),
        ];
    }

    /**
     * @return array{total:int,draft:int,published:int,disabled:int}
     */
    public function statusSummary(int $websiteId = 0, string $locale = '', string $search = ''): array
    {
        $summary = [
            'total' => 0,
            'draft' => 0,
            'published' => 0,
            'disabled' => 0,
        ];
        foreach ([Post::STATUS_DRAFT, Post::STATUS_PUBLISHED, Post::STATUS_DISABLED] as $status) {
            $model = clone $this->postModel;
            $query = $model->clearData()->reset();
            $this->applyListingFilters($query, $websiteId, $status, $locale, $search);
            $count = (int)$query->count();
            $summary[$status] = $count;
            $summary['total'] += $count;
        }

        return $summary;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function save(array $data): array
    {
        $postId = (int)($data['post_id'] ?? 0);
        $websiteId = max(0, (int)($data['website_id'] ?? 0));
        $slug = trim(strtolower((string)($data['slug'] ?? '')));
        $status = trim((string)($data['status'] ?? Post::STATUS_DRAFT));
        if ($slug === '') {
            throw new \InvalidArgumentException((string)__('博客 slug 不能为空。'));
        }

        $this->slugGuard->assertSaveAllowed($websiteId, $slug, $postId);

        $model = clone $this->postModel;
        if ($postId > 0) {
            $model->clearData()->reset()->load($postId);
            if ($model->getPostId() <= 0) {
                throw new \InvalidArgumentException((string)__('博客文章不存在。'));
            }
        }

        $now = date('Y-m-d H:i:s');
        $model->setData(Post::schema_fields_WEBSITE_ID, $websiteId);
        $model->setData(Post::schema_fields_LOCALE, (string)($data['locale'] ?? 'zh_Hans_CN'));
        $model->setData(Post::schema_fields_SLUG, $slug);
        $model->setData(Post::schema_fields_TITLE, trim((string)($data['title'] ?? '')));
        $model->setData(Post::schema_fields_EXCERPT, trim((string)($data['excerpt'] ?? '')));
        if (array_key_exists('content', $data)) {
            $model->setData(Post::schema_fields_CONTENT, (string)$data['content']);
        }
        $model->setData(Post::schema_fields_COVER_IMAGE, trim((string)($data['cover_image'] ?? '')));
        $model->setData(Post::schema_fields_AUTHOR, trim((string)($data['author'] ?? '')));
        $model->setData(Post::schema_fields_KEYWORDS, trim((string)($data['keywords'] ?? '')));
        $model->setData(Post::schema_fields_CATEGORY_ID, (int)($data['category_id'] ?? 0));
        $model->setData(Post::schema_fields_STATUS, $status);
        if ($status === Post::STATUS_PUBLISHED) {
            $publishedAt = trim((string)($data['published_at'] ?? ''));
            $model->setData(
                Post::schema_fields_PUBLISHED_AT,
                $publishedAt !== '' ? $publishedAt : ($model->getData(Post::schema_fields_PUBLISHED_AT) ?: $now),
            );
        }
        if ($postId <= 0) {
            $model->setData(Post::schema_fields_CREATED_AT, $now);
        }
        $model->setData(Post::schema_fields_UPDATED_AT, $now);
        $model->save();

        $row = $model->fetchArray();
        $this->urlNotifier->notifyFromPostRow(is_array($row) ? $row : $model->getData());

        $savedRow = is_array($row) ? $row : $model->getData();
        if (is_array($savedRow)) {
            try {
                $this->cmsEditor->syncCmsPageFromPost($savedRow);
            } catch (\Throwable) {
            }
            $indexEvent = [
                'post' => $savedRow,
                'action' => 'upsert',
            ];
            \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class)
                ->dispatch('Weline_Blog::post_search_index_changed', $indexEvent);
        }

        return is_array($row) ? $row : $model->getData();
    }

    public function delete(int $postId): void
    {
        $model = clone $this->postModel;
        $model->clearData()->reset()->load($postId);
        if ($model->getPostId() <= 0) {
            throw new \InvalidArgumentException((string)__('博客文章不存在。'));
        }
        $row = $model->fetchArray();
        $model->delete();
        if (is_array($row)) {
            $this->urlNotifier->notifyFromPostRow($row, 'delete');
            $indexEvent = [
                'post' => $row,
                'action' => 'delete',
            ];
            \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class)
                ->dispatch('Weline_Blog::post_search_index_changed', $indexEvent);
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function categories(int $websiteId = 0): array
    {
        $model = clone $this->categoryModel;
        $query = $model->clearData()->reset();
        if ($websiteId > 0) {
            $query->where(Category::schema_fields_WEBSITE_ID, $websiteId);
        }
        $rows = $query->order(Category::schema_fields_SORT_ORDER, 'ASC')->select()->fetchArray();

        return is_array($rows) ? $rows : [];
    }

    private function applyListingFilters(
        Post $query,
        int $websiteId,
        string $status,
        string $locale,
        string $search,
    ): void {
        if ($websiteId > 0) {
            $query->where(Post::schema_fields_WEBSITE_ID, $websiteId);
        }
        if (
            $status !== ''
            && in_array($status, [Post::STATUS_DRAFT, Post::STATUS_PUBLISHED, Post::STATUS_DISABLED], true)
        ) {
            $query->where(Post::schema_fields_STATUS, $status);
        }
        if ($locale !== '') {
            $query->where(Post::schema_fields_LOCALE, $locale);
        }
        $search = trim($search);
        if ($search !== '') {
            $keyword = '%' . $search . '%';
            $query->where(Post::schema_fields_TITLE, $keyword, 'LIKE', 'OR')
                ->where(Post::schema_fields_SLUG, $keyword, 'LIKE', 'OR')
                ->where(Post::schema_fields_AUTHOR, $keyword, 'LIKE');
        }
    }
}
