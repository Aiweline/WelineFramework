<?php
declare(strict_types=1);

namespace GuoLaiRen\Blog\Extends\Module\Weline_Framework\Query;

use GuoLaiRen\Blog\Model\Category;
use GuoLaiRen\Blog\Model\Post;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

/**
 * 博客查询器
 *
 * 提供 getCategoryList、getPostBySlug、getPostList 等能力，供其他模块通过 w_query('blog', ...) 调用。
 */
class BlogQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly Post $postModel,
        private readonly Category $categoryModel
    ) {
    }

    public function getProviderName(): string
    {
        return 'blog';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getCategoryList' => $this->getCategoryList($params),
            'getCategoryBySlug' => $this->getCategoryBySlug($params),
            'getPostBySlug' => $this->getPostBySlug($params),
            'getPostList' => $this->getPostList($params),
            'getRelatedPosts' => $this->getRelatedPosts($params),
            'getRecentPosts' => $this->getRecentPosts($params),
            'incrementPostViewCount' => $this->incrementPostViewCount($params),
            'getPostsWithTags' => $this->getPostsWithTags($params),
            default => throw new \InvalidArgumentException(
                (string)__('Blog 查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    private function getCategoryList(array $params): array
    {
        $siteId = (int)($params['site_id'] ?? 0);
        $category = clone $this->categoryModel;
        $category->clear()->where(Category::schema_fields_STATUS, Category::STATUS_ENABLED);
        if ($siteId > 0) {
            $category->where(Category::schema_fields_SITE_ID, $siteId);
        }
        $items = $category->order(Category::schema_fields_SORT_ORDER, 'ASC')->select()->fetch()->getItems();
        $result = [];
        foreach ($items as $cat) {
            if (!$cat->getId()) {
                continue;
            }
            $slug = $cat->getData(Category::schema_fields_SLUG);
            $result[] = [
                'category_id' => (int)$cat->getId(),
                'name' => $cat->getData(Category::schema_fields_NAME),
                'slug' => $slug,
                'url' => '/blog/category/' . ($slug ?? ''),
                'description' => $cat->getData(Category::schema_fields_DESCRIPTION),
            ];
        }
        return $result;
    }

    private function getCategoryBySlug(array $params): ?array
    {
        $slug = trim((string)($params['slug'] ?? ''));
        $siteId = (int)($params['site_id'] ?? 0);
        if ($slug === '') {
            return null;
        }
        $category = clone $this->categoryModel;
        $category->clear()
            ->where(Category::schema_fields_SLUG, $slug)
            ->where(Category::schema_fields_STATUS, Category::STATUS_ENABLED);
        if ($siteId > 0) {
            $category->where(Category::schema_fields_SITE_ID, $siteId);
        }
        $category->find()->fetch();
        if (!$category->getId()) {
            return null;
        }
        $slugVal = $category->getData(Category::schema_fields_SLUG);
        return [
            'category_id' => (int)$category->getId(),
            'name' => $category->getData(Category::schema_fields_NAME),
            'slug' => $slugVal,
            'url' => '/blog/category/' . ($slugVal ?? ''),
            'description' => $category->getData(Category::schema_fields_DESCRIPTION),
        ];
    }

    private function getPostBySlug(array $params): ?array
    {
        $slug = trim((string)($params['slug'] ?? ''));
        $siteId = (int)($params['site_id'] ?? 0);
        if ($slug === '') {
            return null;
        }
        $post = clone $this->postModel;
        $post->clear()
            ->where(Post::schema_fields_SLUG, $slug)
            ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED);
        if ($siteId > 0) {
            $post->where(Post::schema_fields_SITE_ID, $siteId);
        }
        $post->find()->fetch();
        if (!$post->getId()) {
            return null;
        }
        return $this->postToArray($post);
    }

    private function getPostList(array $params): array
    {
        $siteId = (int)($params['site_id'] ?? 0);
        $categoryId = isset($params['category_id']) ? (int)$params['category_id'] : null;
        $page = max(1, (int)($params['page'] ?? 1));
        $pageSize = min(100, max(1, (int)($params['page_size'] ?? 12)));
        $post = clone $this->postModel;
        $post->clear()->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED);
        if ($siteId > 0) {
            $post->where(Post::schema_fields_SITE_ID, $siteId);
        }
        if ($categoryId !== null && $categoryId > 0) {
            $post->where(Post::schema_fields_CATEGORY_ID, $categoryId);
        }
        try {
            $result = $post
                ->order(Post::schema_fields_IS_FEATURED, 'DESC')
                ->order(Post::schema_fields_PUBLISHED_AT, 'DESC')
                ->page($page, $pageSize)
                ->pagination()
                ->select()
                ->fetch();
            $items = [];
            foreach ($result->getItems() as $p) {
                if ($p->getId()) {
                    $items[] = $this->postToArray($p);
                }
            }
            return ['items' => $items, 'pagination' => $result->getPagination()];
        } catch (\Throwable $e) {
            $post = clone $this->postModel;
            $post->clear()->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED);
            if ($siteId > 0) {
                $post->where(Post::schema_fields_SITE_ID, $siteId);
            }
            if ($categoryId !== null && $categoryId > 0) {
                $post->where(Post::schema_fields_CATEGORY_ID, $categoryId);
            }
            $result = $post->order(Post::schema_fields_PUBLISHED_AT, 'DESC')
                ->page($page, $pageSize)
                ->pagination()
                ->select()
                ->fetch();
            $items = [];
            foreach ($result->getItems() as $p) {
                if ($p->getId()) {
                    $items[] = $this->postToArray($p);
                }
            }
            return ['items' => $items, 'pagination' => $result->getPagination()];
        }
    }

    private function getRelatedPosts(array $params): array
    {
        $categoryId = (int)($params['category_id'] ?? 0);
        $excludePostId = (int)($params['exclude_post_id'] ?? 0);
        $siteId = (int)($params['site_id'] ?? 0);
        $limit = min(20, max(1, (int)($params['limit'] ?? 6)));
        $post = clone $this->postModel;
        $post->clear()->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED);
        if ($excludePostId > 0) {
            $post->where(Post::schema_fields_ID, $excludePostId, '!=');
        }
        if ($siteId > 0) {
            $post->where(Post::schema_fields_SITE_ID, $siteId);
        }
        if ($categoryId > 0) {
            $post->where(Post::schema_fields_CATEGORY_ID, $categoryId);
        }
        $items = $post->order(Post::schema_fields_PUBLISHED_AT, 'DESC')
            ->limit($limit)
            ->select()
            ->fetch()
            ->getItems();
        $result = [];
        foreach ($items as $p) {
            if ($p->getId()) {
                $result[] = $this->postToArray($p);
            }
        }
        return $result;
    }

    private function getRecentPosts(array $params): array
    {
        $siteId = (int)($params['site_id'] ?? 0);
        $limit = min(50, max(1, (int)($params['limit'] ?? 10)));
        $post = clone $this->postModel;
        $post->clear()->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED);
        if ($siteId > 0) {
            $post->where(Post::schema_fields_SITE_ID, $siteId);
        }
        $items = $post->order(Post::schema_fields_PUBLISHED_AT, 'DESC')
            ->limit($limit)
            ->select()
            ->fetch()
            ->getItems();
        $result = [];
        foreach ($items as $p) {
            if ($p->getId()) {
                $result[] = $this->postToArray($p);
            }
        }
        return $result;
    }

    private function incrementPostViewCount(array $params): void
    {
        $postId = (int)($params['post_id'] ?? 0);
        if ($postId <= 0) {
            return;
        }
        $post = clone $this->postModel;
        $post->load($postId);
        if ($post->getId()) {
            $post->incrementViewCount()->save();
        }
    }

    private function getPostsWithTags(array $params): array
    {
        $siteId = (int)($params['site_id'] ?? 0);
        $post = clone $this->postModel;
        $post->clear()
            ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED)
            ->where(Post::schema_fields_TAGS, '', '!=');
        if ($siteId > 0) {
            $post->where(Post::schema_fields_SITE_ID, $siteId);
        }
        $items = $post->select()->fetch()->getItems();
        $result = [];
        foreach ($items as $p) {
            if ($p->getId()) {
                $arr = $this->postToArray($p);
                $tags = $p->getData(Post::schema_fields_TAGS);
                $arr['tags_array'] = $tags ? array_filter(array_map('trim', explode(',', (string)$tags))) : [];
                $result[] = $arr;
            }
        }
        return $result;
    }

    private function postToArray(object $post): array
    {
        $slug = $post->getData(Post::schema_fields_SLUG);
        return [
            'post_id' => (int)$post->getId(),
            'title' => $post->getData(Post::schema_fields_TITLE),
            'slug' => $slug,
            'url' => '/blog/' . ($slug ?? ''),
            'summary' => $post->getData(Post::schema_fields_SUMMARY),
            'content' => $post->getData(Post::schema_fields_CONTENT),
            'cover_image' => $post->getData(Post::schema_fields_COVER_IMAGE),
            'author' => $post->getData(Post::schema_fields_AUTHOR),
            'published_at' => $post->getData(Post::schema_fields_PUBLISHED_AT),
            'view_count' => (int)($post->getData(Post::schema_fields_VIEW_COUNT) ?? 0),
            'category_id' => (int)($post->getData(Post::schema_fields_CATEGORY_ID) ?? 0),
            'tags' => $post->getData(Post::schema_fields_TAGS),
        ];
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'blog',
            'name' => __('博客查询'),
            'description' => __('提供博客文章与分类查询能力'),
            'module' => 'GuoLaiRen_Blog',
            'operations' => [
                ['name' => 'getCategoryList', 'description' => __('获取分类列表'), 'params' => [['name' => 'site_id', 'type' => 'int', 'required' => false]]],
                ['name' => 'getCategoryBySlug', 'description' => __('根据 slug 获取分类'), 'params' => [['name' => 'slug', 'type' => 'string', 'required' => true], ['name' => 'site_id', 'type' => 'int', 'required' => false]]],
                ['name' => 'getPostBySlug', 'description' => __('根据 slug 获取文章'), 'params' => [['name' => 'slug', 'type' => 'string', 'required' => true], ['name' => 'site_id', 'type' => 'int', 'required' => false]]],
                ['name' => 'getPostList', 'description' => __('获取文章列表（分页）'), 'params' => [['name' => 'site_id', 'type' => 'int'], ['name' => 'category_id', 'type' => 'int|null'], ['name' => 'page', 'type' => 'int'], ['name' => 'page_size', 'type' => 'int']]],
                ['name' => 'getRelatedPosts', 'description' => __('获取相关文章'), 'params' => [['name' => 'category_id', 'type' => 'int'], ['name' => 'exclude_post_id', 'type' => 'int'], ['name' => 'site_id', 'type' => 'int'], ['name' => 'limit', 'type' => 'int']]],
                ['name' => 'getRecentPosts', 'description' => __('获取最近文章'), 'params' => [['name' => 'site_id', 'type' => 'int'], ['name' => 'limit', 'type' => 'int']]],
                ['name' => 'incrementPostViewCount', 'description' => __('增加文章浏览量'), 'params' => [['name' => 'post_id', 'type' => 'int', 'required' => true]]],
                ['name' => 'getPostsWithTags', 'description' => __('获取带标签的文章（用于标签云）'), 'params' => [['name' => 'site_id', 'type' => 'int']]],
            ],
        ];
    }
}
