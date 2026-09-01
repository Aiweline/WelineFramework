<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

use Weline\Blog\Api\Uri\BlogNamespace;
use Weline\Blog\Model\Post;

final class BlogSlugConflictGuard
{
    public function __construct(
        private readonly Post $postModel,
        private readonly CmsBlogPageAdapter $cmsAdapter,
    ) {
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function assertSaveAllowed(int $websiteId, string $slug, int $excludePostId = 0): void
    {
        $slug = trim(strtolower($slug), '/ ');
        if ($slug === '' || $websiteId < 0) {
            throw new \InvalidArgumentException((string)__('博客 slug 无效。'));
        }

        $query = clone $this->postModel;
        $query->clearData()->reset()
            ->where(Post::schema_fields_WEBSITE_ID, $websiteId)
            ->where(Post::schema_fields_SLUG, $slug);
        if ($excludePostId > 0) {
            $query->where(Post::schema_fields_ID, $excludePostId, '!=');
        }
        $existing = $query->find()->fetchArray();
        if (is_array($existing) && (int)($existing[Post::schema_fields_ID] ?? 0) > 0) {
            throw new \InvalidArgumentException((string)__('博客 slug 已被其他文章占用：%{1}', [$slug]));
        }

        $cmsPage = $this->cmsAdapter->getPageForConflictCheck($websiteId, $slug);
        if ($cmsPage !== null && (string)($cmsPage['status'] ?? '') === 'published') {
            throw new \InvalidArgumentException((string)__('博客 slug 与 CMS blog 页面冲突：%{1}', [$slug]));
        }
    }
}
