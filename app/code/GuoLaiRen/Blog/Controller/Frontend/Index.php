<?php

declare(strict_types=1);

/*
 * GuoLaiRen Blog Module
 * 博客前台列表控制器
 * 
 * 使用 PageRenderService 渲染，从首页继承 header/footer 配置
 */

namespace GuoLaiRen\Blog\Controller\Frontend;

use GuoLaiRen\Blog\Model\Post;
use GuoLaiRen\Blog\Model\Category;
use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\PageRenderService;
use GuoLaiRen\PageBuilder\Service\LayoutOwnerResolver;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Data\WebsiteData;

class Index extends FrontendController
{
    private Post $postModel;
    private Category $categoryModel;
    private Page $pageModel;
    private PageRenderService $pageRenderService;
    private LayoutOwnerResolver $layoutOwnerResolver;

    public function __construct(
        Post $postModel,
        Category $categoryModel,
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
     * 博客文章列表
     *
     * 路由示例：/blog 或 /blog/frontend/index/index?page=1
     */
    public function index()
    {
        $page     = (int)($this->request->getGet('page', 1));
        $page     = $page > 0 ? $page : 1;
        $pageSize = 12;

        // 获取博客文章列表
        $websiteId = WebsiteData::getWebsiteId();
        $listModel = clone $this->postModel;
        $listModel->clear()
            ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED);
        
        // 根据当前网站过滤
        if ($websiteId) {
            $listModel->where(Post::schema_fields_SITE_ID, $websiteId);
        }
        
        $listModel->order(Post::schema_fields_PUBLISHED_AT, 'DESC')
            ->page($page, $pageSize);

        $collection = $listModel->select()->fetch();
        $items      = $collection->getItems();

        $posts = [];
        foreach ($items as $item) {
            /** @var Post $item */
            $slug = (string)$item->getData(Post::schema_fields_SLUG);
            
            // 获取分类名称
            $categoryName = '';
            $categoryId = $item->getData(Post::schema_fields_CATEGORY_ID);
            if ($categoryId) {
                $cat = clone $this->categoryModel;
                $cat->load($categoryId);
                if ($cat->getId()) {
                    $categoryName = $cat->getData(Category::schema_fields_NAME);
                }
            }

            $posts[] = [
                'post_id'      => (int)$item->getId(),
                'title'        => (string)$item->getData(Post::schema_fields_TITLE),
                'summary'      => (string)$item->getData(Post::schema_fields_SUMMARY),
                'slug'         => $slug,
                'url'          => '/blog/' . $slug,
                'cover_image'  => (string)$item->getData(Post::schema_fields_COVER_IMAGE),
                'author'       => (string)$item->getData(Post::schema_fields_AUTHOR),
                'published_at' => (string)$item->getData(Post::schema_fields_PUBLISHED_AT),
                'view_count'   => (int)$item->getData(Post::schema_fields_VIEW_COUNT),
                'category_id'  => $categoryId,
                'category_name' => $categoryName,
            ];
        }

        // 获取博客分类列表
        $categories = $this->getBlogCategories();
        
        // 获取最近文章（用于侧边栏）
        $recentPosts = $this->getRecentPosts(10);

        // 尝试获取博客列表页面配置
        $blogPage = $this->getBlogListPage();
        
        if ($blogPage && $blogPage->getId()) {
            // 使用 PageRenderService 渲染（继承首页的 header/footer）
            return $this->renderWithPageBuilder($blogPage, $posts, $categories, $recentPosts, $page, $pageSize);
        }

        // 回退到传统模板渲染
        $this->assign('posts', $posts);
        $this->assign('blog_posts', $posts);
        $this->assign('blog_categories', $categories);
        $this->assign('recent_posts', $recentPosts);
        $this->assign('current_page', $page);
        $this->assign('page_size', $pageSize);
        $this->assign('has_more', count($posts) === $pageSize);

        return $this->fetch('index');
    }

    /**
     * 使用 PageRenderService 渲染博客列表
     * 
     * 布局继承规则（由 LayoutOwnerResolver 统一处理）：
     * - 如果博客页面设置了 layout_page_id，使用该页面的布局配置
     * - header/footer 始终从首页继承（全局统一）
     * - 每个页面可以有自己的模板/样式设置
     */
    private function renderWithPageBuilder(
        Page $blogPage,
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
            // 首页配置作为基础，页面自己的配置覆盖
            $mergedSettings = array_merge($homeConfig['style_setting'], $pageStyleSettings);
            $blogPage->setStyleSetting($mergedSettings);
        }
        
        // 布局配置已由 LayoutOwnerResolver 统一处理，这里不需要手动操作
        // - 如果 blogPage 设置了 layout_page_id，会使用该页面的布局
        // - header/footer 会从首页继承
        // - content 使用布局拥有者页面的配置
        
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
        
        // 预先设置博客数据到模板（PageRenderService 会使用这些数据）
        $template = \Weline\Framework\View\Template::getInstance();
        $template->assign('blog_posts', $posts);
        $template->assign('blog_categories', $categories);
        $template->assign('recent_posts', $recentPosts);
        $template->assign('current_page', $currentPage);
        $template->assign('page_size', $pageSize);
        $template->assign('has_more', count($posts) === $pageSize);
        
        // 使用 PageRenderService 渲染（LayoutOwnerResolver 会处理布局继承）
        return $this->pageRenderService->render(
            $blogPage,
            PageRenderService::MODE_LIVE,
            \Weline\Framework\Http\Cookie::getLang()
        );
    }

    /**
     * 获取博客列表页面
     */
    private function getBlogListPage(): ?Page
    {
        // 从当前站点获取博客列表页面
        $websiteId = $this->getWebsiteId();
        
        $page = clone $this->pageModel;
        $page->clear()
            ->where(Page::schema_fields_TYPE, Page::TYPE_BLOG_LIST)
            ->where(Page::schema_fields_STATUS, Page::STATUS_PUBLISHED);
        
        if ($websiteId) {
            $page->where(Page::schema_fields_WEBSITE_ID, $websiteId);
        }
        
        $page->find()->fetch();
        
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
            ->where(Category::schema_fields_STATUS, 1);
        
        // 根据当前网站过滤
        if ($websiteId) {
            $query->where(Category::schema_fields_SITE_ID, $websiteId);
        }
        
        $items = $query->order(Category::schema_fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
        
        $result = [];
        foreach ($items as $cat) {
            $slug = $cat->getData(Category::schema_fields_SLUG);
            $result[] = [
                'category_id' => $cat->getId(),
                'name' => $cat->getData(Category::schema_fields_NAME),
                'slug' => $slug,
                'url' => '/blog/category/' . $slug,
                'description' => $cat->getData(Category::schema_fields_DESCRIPTION),
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
        
        // 根据当前网站过滤
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
