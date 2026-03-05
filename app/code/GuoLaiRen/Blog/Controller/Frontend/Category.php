<?php

declare(strict_types=1);

/*
 * GuoLaiRen Blog Module
 * 博客前台分类控制器
 * 
 * 使用 PageRenderService 渲染，从首页继承 header/footer 配置
 */

namespace GuoLaiRen\Blog\Controller\Frontend;

use GuoLaiRen\Blog\Model\Post;
use GuoLaiRen\Blog\Model\Category as CategoryModel;
use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\PageRenderService;
use GuoLaiRen\PageBuilder\Service\LayoutOwnerResolver;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Data\WebsiteData;

class Category extends FrontendController
{
    private Post $postModel;
    private CategoryModel $categoryModel;
    private Page $pageModel;
    private PageRenderService $pageRenderService;
    private LayoutOwnerResolver $layoutOwnerResolver;

    public function __construct(
        Post $postModel,
        CategoryModel $categoryModel,
        Page $pageModel,
        PageRenderService $pageRenderService,
        LayoutOwnerResolver $layoutOwnerResolver
    ) {
        $this->postModel = $postModel;
        $this->categoryModel = $categoryModel;
        $this->pageModel = $pageModel;
        $this->pageRenderService = $pageRenderService;
        $this->layoutOwnerResolver = $layoutOwnerResolver;
    }

    /**
     * 分类文章列表
     *
     * 路由示例：/blog/category/{slug} 或 /blog/frontend/category/view?slug=tech
     */
    public function view()
    {
        $slug = (string)$this->request->getGet('slug', '');
        $page = (int)($this->request->getGet('page', 1));
        $page = $page > 0 ? $page : 1;
        $pageSize = 12;

        if (empty($slug)) {
            $this->redirect(404);
            return;
        }

        $websiteId = WebsiteData::getWebsiteId();

        // 加载分类
        $category = clone $this->categoryModel;
        $query = $category->clear()
            ->where(CategoryModel::schema_fields_SLUG, $slug)
            ->where(CategoryModel::schema_fields_STATUS, CategoryModel::STATUS_ENABLED);

        if ($websiteId) {
            $query->where(CategoryModel::schema_fields_SITE_ID, $websiteId);
        }

        $query->find()->fetch();

        if (!$category->getId()) {
            $this->redirect(404);
            return;
        }

        // 获取分类下的文章
        $listModel = clone $this->postModel;
        $listModel->clear()
            ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED)
            ->where(Post::schema_fields_CATEGORY_ID, $category->getId());

        if ($websiteId) {
            $listModel->where(Post::schema_fields_SITE_ID, $websiteId);
        }

        $listModel->order(Post::schema_fields_PUBLISHED_AT, 'DESC')
            ->page($page, $pageSize);

        $collection = $listModel->select()->fetch();
        $items = $collection->getItems();

        $posts = [];
        foreach ($items as $item) {
            $postSlug = (string)$item->getData(Post::schema_fields_SLUG);
            $posts[] = [
                'post_id'      => (int)$item->getId(),
                'title'        => (string)$item->getData(Post::schema_fields_TITLE),
                'summary'      => (string)$item->getData(Post::schema_fields_SUMMARY),
                'slug'         => $postSlug,
                'url'          => '/blog/' . $postSlug,
                'cover_image'  => (string)$item->getData(Post::schema_fields_COVER_IMAGE),
                'author'       => (string)$item->getData(Post::schema_fields_AUTHOR),
                'published_at' => (string)$item->getData(Post::schema_fields_PUBLISHED_AT),
                'view_count'   => (int)$item->getData(Post::schema_fields_VIEW_COUNT),
            ];
        }

        // 当前分类数据
        $currentCategory = [
            'category_id'  => $category->getId(),
            'name'         => $category->getData(CategoryModel::schema_fields_NAME),
            'slug'         => $category->getData(CategoryModel::schema_fields_SLUG),
            'description'  => $category->getData(CategoryModel::schema_fields_DESCRIPTION),
            'cover_image'  => $category->getData(CategoryModel::schema_fields_COVER_IMAGE),
            'meta_title'   => $category->getData(CategoryModel::schema_fields_META_TITLE),
            'meta_description' => $category->getData(CategoryModel::schema_fields_META_DESCRIPTION),
        ];

        // 获取所有博客分类列表
        $categories = $this->getBlogCategories();

        // 获取最近文章（用于侧边栏）
        $recentPosts = $this->getRecentPosts(10);

        // 尝试获取博客分类页面配置
        $blogPage = $this->getBlogCategoryPage();

        if ($blogPage && $blogPage->getId()) {
            // 使用 PageRenderService 渲染
            return $this->renderWithPageBuilder(
                $blogPage,
                $currentCategory,
                $posts,
                $categories,
                $recentPosts,
                $page,
                $pageSize
            );
        }

        // 回退到传统模板渲染
        $this->assign('category', $category);
        $this->assign('current_category', $currentCategory);
        $this->assign('posts', $posts);
        $this->assign('blog_posts', $posts);
        $this->assign('blog_categories', $categories);
        $this->assign('recent_posts', $recentPosts);
        $this->assign('current_page', $page);
        $this->assign('page_size', $pageSize);
        $this->assign('has_more', count($posts) === $pageSize);
        $this->assign('title', $currentCategory['name']);

        return $this->fetch('category');
    }

    /**
     * 使用 PageRenderService 渲染博客分类
     */
    private function renderWithPageBuilder(
        Page $blogPage,
        array $currentCategory,
        array $posts,
        array $categories,
        array $recentPosts,
        int $currentPage,
        int $pageSize
    ): string {
        // 获取首页配置用于继承样式和统计代码
        $homeConfig = $blogPage->getHomePageConfig();

        // 页面使用自己的样式设置（如果没有设置，才使用首页的）
        $pageStyle = $blogPage->getData(Page::schema_fields_STYLE);
        if (empty($pageStyle) && !empty($homeConfig['style'])) {
            $blogPage->setData(Page::schema_fields_STYLE, $homeConfig['style']);
        }

        // 样式配置：页面自己的配置优先，首页配置作为基础
        $pageStyleSettings = $blogPage->getStyleSetting();
        if (!empty($homeConfig['style_setting'])) {
            $mergedSettings = array_merge($homeConfig['style_setting'], $pageStyleSettings);
            $blogPage->setStyleSetting($mergedSettings);
        }

        // 设置页面标题（使用分类名称）
        $blogPage->setData(Page::schema_fields_TITLE, $currentCategory['name']);
        $blogPage->setData(Page::schema_fields_META_TITLE, $currentCategory['meta_title'] ?: $currentCategory['name']);
        if (!empty($currentCategory['meta_description'])) {
            $blogPage->setData(Page::schema_fields_META_DESCRIPTION, $currentCategory['meta_description']);
        } elseif (!empty($currentCategory['description'])) {
            $blogPage->setData(Page::schema_fields_META_DESCRIPTION, mb_substr($currentCategory['description'], 0, 160));
        }

        // Logo 和 Icon：页面自己的优先，没有则从首页继承
        if (empty($blogPage->getData(Page::schema_fields_LOGO)) && !empty($homeConfig['logo'])) {
            $blogPage->setData(Page::schema_fields_LOGO, $homeConfig['logo']);
        }
        if (empty($blogPage->getData(Page::schema_fields_ICON)) && !empty($homeConfig['icon'])) {
            $blogPage->setData(Page::schema_fields_ICON, $homeConfig['icon']);
        }

        // 统计代码：页面自己的优先，没有则从首页继承
        if (empty($blogPage->getData(Page::schema_fields_GA4_ID)) && !empty($homeConfig['ga4_id'])) {
            $blogPage->setData(Page::schema_fields_GA4_ID, $homeConfig['ga4_id']);
        }
        if (empty($blogPage->getData(Page::schema_fields_GTM_ID)) && !empty($homeConfig['gtm_id'])) {
            $blogPage->setData(Page::schema_fields_GTM_ID, $homeConfig['gtm_id']);
        }
        if (empty($blogPage->getData(Page::schema_fields_FB_PIXEL_ID)) && !empty($homeConfig['fb_pixel_id'])) {
            $blogPage->setData(Page::schema_fields_FB_PIXEL_ID, $homeConfig['fb_pixel_id']);
        }

        // 设置请求对象
        $this->pageRenderService->setRequest($this->request);

        // 预先设置数据到模板
        $template = \Weline\Framework\View\Template::getInstance();
        $template->assign('current_category', $currentCategory);
        $template->assign('blog_posts', $posts);
        $template->assign('blog_categories', $categories);
        $template->assign('recent_posts', $recentPosts);
        $template->assign('current_page', $currentPage);
        $template->assign('page_size', $pageSize);
        $template->assign('has_more', count($posts) === $pageSize);

        // 使用 PageRenderService 渲染
        return $this->pageRenderService->render(
            $blogPage,
            PageRenderService::MODE_LIVE,
            \Weline\Framework\Http\Cookie::getLang()
        );
    }

    /**
     * 获取博客分类页面配置
     */
    private function getBlogCategoryPage(): ?Page
    {
        $websiteId = $this->getWebsiteId();

        $page = clone $this->pageModel;
        $page->clear()
            ->where(Page::schema_fields_TYPE, Page::TYPE_BLOG_CATEGORY)
            ->where(Page::schema_fields_STATUS, Page::STATUS_PUBLISHED);

        if ($websiteId) {
            $page->where(Page::schema_fields_WEBSITE_ID, $websiteId);
        }

        $page->find()->fetch();

        // 如果没有博客分类页面，尝试使用博客列表页面
        if (!$page->getId()) {
            $page = clone $this->pageModel;
            $page->clear()
                ->where(Page::schema_fields_TYPE, Page::TYPE_BLOG_LIST)
                ->where(Page::schema_fields_STATUS, Page::STATUS_PUBLISHED);

            if ($websiteId) {
                $page->where(Page::schema_fields_WEBSITE_ID, $websiteId);
            }

            $page->find()->fetch();
        }

        return $page->getId() ? $page : null;
    }

    /**
     * 获取当前站点ID
     */
    private function getWebsiteId(): ?int
    {
        return WebsiteData::getWebsiteId();
    }

    /**
     * 获取博客分类列表
     */
    private function getBlogCategories(): array
    {
        $websiteId = WebsiteData::getWebsiteId();
        $categories = clone $this->categoryModel;
        $query = $categories->clear()
            ->where(CategoryModel::schema_fields_STATUS, 1);

        if ($websiteId) {
            $query->where(CategoryModel::schema_fields_SITE_ID, $websiteId);
        }

        $items = $query->order(CategoryModel::schema_fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetch()
            ->getItems();

        $result = [];
        foreach ($items as $cat) {
            $slug = $cat->getData(CategoryModel::schema_fields_SLUG);
            $result[] = [
                'category_id' => $cat->getId(),
                'name' => $cat->getData(CategoryModel::schema_fields_NAME),
                'slug' => $slug,
                'url' => '/blog/category/' . $slug,
                'description' => $cat->getData(CategoryModel::schema_fields_DESCRIPTION),
            ];
        }

        return $result;
    }

    /**
     * 获取最近文章
     */
    private function getRecentPosts(int $limit = 10): array
    {
        $websiteId = WebsiteData::getWebsiteId();
        $posts = clone $this->postModel;
        $query = $posts->clear()
            ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED);

        if ($websiteId) {
            $query->where(Post::schema_fields_SITE_ID, $websiteId);
        }

        $items = $query->order(Post::schema_fields_PUBLISHED_AT, 'DESC')
            ->limit($limit)
            ->select()
            ->fetch()
            ->getItems();

        $result = [];
        foreach ($items as $item) {
            $slug = $item->getData(Post::schema_fields_SLUG);
            $result[] = [
                'post_id' => $item->getId(),
                'title' => $item->getData(Post::schema_fields_TITLE),
                'slug' => $slug,
                'url' => '/blog/' . $slug,
                'cover_image' => $item->getData(Post::schema_fields_COVER_IMAGE),
                'published_at' => $item->getData(Post::schema_fields_PUBLISHED_AT),
            ];
        }

        return $result;
    }
}
