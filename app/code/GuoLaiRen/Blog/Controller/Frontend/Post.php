<?php

declare(strict_types=1);

/*
 * GuoLaiRen Blog Module
 * 博客前台详情控制器
 * 
 * 使用 PageRenderService 渲染，从首页继承 header/footer 配置
 */

namespace GuoLaiRen\Blog\Controller\Frontend;

use GuoLaiRen\Blog\Model\Post as PostModel;
use GuoLaiRen\Blog\Model\Category;
use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\PageRenderService;
use GuoLaiRen\PageBuilder\Service\LayoutOwnerResolver;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Websites\Data\WebsiteData;

class Post extends FrontendController
{
    private PostModel $postModel;
    private Category $categoryModel;
    private Page $pageModel;
    private PageRenderService $pageRenderService;
    private LayoutOwnerResolver $layoutOwnerResolver;

    public function __construct(
        PostModel $postModel,
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
     * 博客文章详情
     *
     * 路由示例：/blog/{slug} 或 /blog/frontend/post/view?slug=my-first-blog-post
     */
    public function view()
    {
        $slug = (string)$this->request->getGet('slug', '');

        if (empty($slug)) {
            $this->redirect(404);
            return;
        }

        $websiteId = WebsiteData::getWebsiteId();
        $post = clone $this->postModel;
        $query = $post->clear()
            ->where(PostModel::fields_SLUG, $slug)
            ->where(PostModel::fields_STATUS, PostModel::STATUS_PUBLISHED);
        
        // 根据当前网站过滤
        if ($websiteId) {
            $query->where(PostModel::fields_SITE_ID, $websiteId);
        }
        
        $query->find()->fetch();

        if (!$post->getId()) {
            $this->redirect(404);
            return;
        }

        // 增加浏览量
        $post->incrementViewCount()->save();

        // 获取分类名称
        $categoryName = '';
        $categoryId = $post->getData(PostModel::fields_CATEGORY_ID);
        if ($categoryId) {
            $cat = clone $this->categoryModel;
            $cat->load($categoryId);
            if ($cat->getId()) {
                $categoryName = $cat->getData(Category::fields_NAME);
            }
        }

        // 构建当前文章数据
        $currentPost = [
            'post_id'      => $post->getId(),
            'title'        => $post->getData(PostModel::fields_TITLE),
            'slug'         => $post->getData(PostModel::fields_SLUG),
            'url'          => '/blog/' . $post->getData(PostModel::fields_SLUG),
            'summary'      => $post->getData(PostModel::fields_SUMMARY),
            'content'      => $post->getData(PostModel::fields_CONTENT),
            'cover_image'  => $post->getData(PostModel::fields_COVER_IMAGE),
            'author'       => $post->getData(PostModel::fields_AUTHOR),
            'tags'         => $post->getData(PostModel::fields_TAGS),
            'published_at' => $post->getData(PostModel::fields_PUBLISHED_AT),
            'view_count'   => $post->getData(PostModel::fields_VIEW_COUNT),
            'category_id'  => $categoryId,
            'category_name' => $categoryName,
        ];

        // 获取相关文章
        $relatedPosts = $this->getRelatedPosts($post, 6);
        
        // 获取博客分类列表
        $categories = $this->getBlogCategories();
        
        // 获取最近文章（用于侧边栏）
        $recentPosts = $this->getRecentPosts(10);

        // 尝试获取博客详情页面配置
        $blogPage = $this->getBlogDetailPage();
        
        if ($blogPage && $blogPage->getId()) {
            // 使用 PageRenderService 渲染（继承首页的 header/footer）
            return $this->renderWithPageBuilder(
                $blogPage, 
                $currentPost, 
                $relatedPosts, 
                $categories, 
                $recentPosts
            );
        }

        // 回退到传统模板渲染
        $this->assign('post', $post);
        $this->assign('current_post', $currentPost);
        $this->assign('related_posts', $relatedPosts);
        $this->assign('blog_categories', $categories);
        $this->assign('recent_posts', $recentPosts);
        $this->assign('title', $post->getData(PostModel::fields_TITLE));

        return $this->fetch('view');
    }

    /**
     * 使用 PageRenderService 渲染博客详情
     * 
     * 布局继承规则（由 LayoutOwnerResolver 统一处理）：
     * - 如果博客页面设置了 layout_page_id，使用该页面的布局配置
     * - header/footer 始终从首页继承（全局统一）
     * - 每个页面可以有自己的模板/样式设置
     */
    private function renderWithPageBuilder(
        Page $blogPage,
        array $currentPost,
        array $relatedPosts,
        array $categories,
        array $recentPosts
    ): string {
        // 获取首页配置用于继承样式和统计代码
        $homeConfig = $blogPage->getHomePageConfig();
        
        // 页面使用自己的样式设置（如果没有设置，才使用首页的）
        $pageStyle = $blogPage->getData(Page::fields_STYLE);
        if (empty($pageStyle) && !empty($homeConfig['style'])) {
            $blogPage->setData(Page::fields_STYLE, $homeConfig['style']);
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
        
        // 设置页面标题（使用文章标题）
        $blogPage->setData(Page::fields_TITLE, $currentPost['title']);
        $blogPage->setData(Page::fields_META_TITLE, $currentPost['title']);
        if (!empty($currentPost['summary'])) {
            $blogPage->setData(Page::fields_META_DESCRIPTION, mb_substr($currentPost['summary'], 0, 160));
        }
        
        // Logo 和 Icon：页面自己的优先，没有则从首页继承
        if (empty($blogPage->getData(Page::fields_LOGO)) && !empty($homeConfig['logo'])) {
            $blogPage->setData(Page::fields_LOGO, $homeConfig['logo']);
        }
        if (empty($blogPage->getData(Page::fields_ICON)) && !empty($homeConfig['icon'])) {
            $blogPage->setData(Page::fields_ICON, $homeConfig['icon']);
        }
        
        // 统计代码：页面自己的优先，没有则从首页继承
        if (empty($blogPage->getData(Page::fields_GA4_ID)) && !empty($homeConfig['ga4_id'])) {
            $blogPage->setData(Page::fields_GA4_ID, $homeConfig['ga4_id']);
        }
        if (empty($blogPage->getData(Page::fields_GTM_ID)) && !empty($homeConfig['gtm_id'])) {
            $blogPage->setData(Page::fields_GTM_ID, $homeConfig['gtm_id']);
        }
        if (empty($blogPage->getData(Page::fields_FB_PIXEL_ID)) && !empty($homeConfig['fb_pixel_id'])) {
            $blogPage->setData(Page::fields_FB_PIXEL_ID, $homeConfig['fb_pixel_id']);
        }
        
        // 设置请求对象
        $this->pageRenderService->setRequest($this->request);
        
        // 预先设置博客数据到模板（PageRenderService 会使用这些数据）
        $template = \Weline\Framework\View\Template::getInstance();
        $template->assign('current_post', $currentPost);
        $template->assign('related_posts', $relatedPosts);
        $template->assign('blog_categories', $categories);
        $template->assign('recent_posts', $recentPosts);
        
        // 使用 PageRenderService 渲染（LayoutOwnerResolver 会处理布局继承）
        return $this->pageRenderService->render(
            $blogPage,
            PageRenderService::MODE_LIVE,
            \Weline\Framework\Http\Cookie::getLang()
        );
    }

    /**
     * 获取博客详情页面配置
     */
    private function getBlogDetailPage(): ?Page
    {
        $websiteId = $this->getWebsiteId();
        
        $page = clone $this->pageModel;
        $page->clear()
            ->where(Page::fields_TYPE, Page::TYPE_BLOG)
            ->where(Page::fields_STATUS, Page::STATUS_PUBLISHED);
        
        if ($websiteId) {
            $page->where(Page::fields_WEBSITE_ID, $websiteId);
        }
        
        $page->find()->fetch();
        
        // 如果没有博客详情页面，尝试使用博客列表页面
        if (!$page->getId()) {
            $page = clone $this->pageModel;
            $page->clear()
                ->where(Page::fields_TYPE, Page::TYPE_BLOG_LIST)
                ->where(Page::fields_STATUS, Page::STATUS_PUBLISHED);
            
            if ($websiteId) {
                $page->where(Page::fields_WEBSITE_ID, $websiteId);
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
     * 获取相关文章
     */
    private function getRelatedPosts(PostModel $currentPost, int $limit = 6): array
    {
        $websiteId = WebsiteData::getWebsiteId();
        $posts = clone $this->postModel;
        $query = $posts->clear()
            ->where(PostModel::fields_STATUS, PostModel::STATUS_PUBLISHED)
            ->where(PostModel::fields_ID, $currentPost->getId(), '!=');
        
        // 根据当前网站过滤
        if ($websiteId) {
            $query->where(PostModel::fields_SITE_ID, $websiteId);
        }
        
        // 优先同分类文章
        $categoryId = $currentPost->getData(PostModel::fields_CATEGORY_ID);
        if ($categoryId) {
            $query->where(PostModel::fields_CATEGORY_ID, $categoryId);
        }
        
        $items = $query->order(PostModel::fields_PUBLISHED_AT, 'DESC')
            ->limit($limit)
            ->select()
            ->fetch()
            ->getItems();
        
        // 如果同分类文章不够，获取其他文章
        if (count($items) < $limit && $categoryId) {
            $otherPosts = clone $this->postModel;
            $otherQuery = $otherPosts->clear()
                ->where(PostModel::fields_STATUS, PostModel::STATUS_PUBLISHED)
                ->where(PostModel::fields_ID, $currentPost->getId(), '!=')
                ->where(PostModel::fields_CATEGORY_ID, $categoryId, '!=');
            
            // 根据当前网站过滤
            if ($websiteId) {
                $otherQuery->where(PostModel::fields_SITE_ID, $websiteId);
            }
            
            $otherItems = $otherQuery->order(PostModel::fields_PUBLISHED_AT, 'DESC')
                ->limit($limit - count($items))
                ->select()
                ->fetch()
                ->getItems();
            
            $items = array_merge($items, $otherItems);
        }
        
        $result = [];
        foreach ($items as $item) {
            $slug = $item->getData(PostModel::fields_SLUG);
            $result[] = [
                'post_id' => $item->getId(),
                'title' => $item->getData(PostModel::fields_TITLE),
                'slug' => $slug,
                'url' => '/blog/' . $slug,
                'summary' => $item->getData(PostModel::fields_SUMMARY),
                'cover_image' => $item->getData(PostModel::fields_COVER_IMAGE),
                'published_at' => $item->getData(PostModel::fields_PUBLISHED_AT),
            ];
        }
        
        return $result;
    }

    /**
     * 获取博客分类列表
     */
    private function getBlogCategories(): array
    {
        $websiteId = WebsiteData::getWebsiteId();
        $categories = clone $this->categoryModel;
        $query = $categories->clear()
            ->where(Category::fields_STATUS, 1);
        
        // 根据当前网站过滤
        if ($websiteId) {
            $query->where(Category::fields_SITE_ID, $websiteId);
        }
        
        $items = $query->order(Category::fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
        
        $result = [];
        foreach ($items as $cat) {
            $slug = $cat->getData(Category::fields_SLUG);
            $result[] = [
                'category_id' => $cat->getId(),
                'name' => $cat->getData(Category::fields_NAME),
                'slug' => $slug,
                'url' => '/blog/category/' . $slug,
                'description' => $cat->getData(Category::fields_DESCRIPTION),
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
            ->where(PostModel::fields_STATUS, PostModel::STATUS_PUBLISHED);
        
        // 根据当前网站过滤
        if ($websiteId) {
            $query->where(PostModel::fields_SITE_ID, $websiteId);
        }
        
        $items = $query->order(PostModel::fields_PUBLISHED_AT, 'DESC')
            ->limit($limit)
            ->select()
            ->fetch()
            ->getItems();
        
        $result = [];
        foreach ($items as $item) {
            $slug = $item->getData(PostModel::fields_SLUG);
            $result[] = [
                'post_id' => $item->getId(),
                'title' => $item->getData(PostModel::fields_TITLE),
                'slug' => $slug,
                'url' => '/blog/' . $slug,
                'cover_image' => $item->getData(PostModel::fields_COVER_IMAGE),
                'published_at' => $item->getData(PostModel::fields_PUBLISHED_AT),
            ];
        }
        
        return $result;
    }
}
