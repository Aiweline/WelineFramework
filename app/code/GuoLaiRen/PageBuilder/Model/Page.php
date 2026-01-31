<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * 页面模型
 */

namespace GuoLaiRen\PageBuilder\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Page extends Model
{
    public const table = 'guolairen_page_builder_page';
    
    // 字段定义
    public const fields_ID = 'page_id';
    public const fields_HANDLE = 'handle';
    public const fields_TYPE = 'type';
    public const fields_NAME = 'name';
    public const fields_TITLE = 'title';
    public const fields_CONTENT = 'content';
    public const fields_PARENT_ID = 'parent_id';
    public const fields_GA4_ID = 'ga4_id';
    public const fields_GTM_ID = 'gtm_id';
    public const fields_FB_PIXEL_ID = 'fb_pixel_id';
    public const fields_CTA_EVENT_NAME = 'cta_event_name';
    public const fields_HEADER_CUSTOM_CODE = 'header_custom_code';
    public const fields_FOOTER_CUSTOM_CODE = 'footer_custom_code';
    public const fields_LOGO = 'logo';
    public const fields_ICON = 'icon';
    public const fields_LOCALES = 'locales';
    public const fields_DEFAULT_LOCALE = 'default_locale';
    public const fields_STYLE = 'style';
    public const fields_STYLE_SETTING = 'style_setting';
    public const fields_META_TITLE = 'meta_title';
    public const fields_META_DESCRIPTION = 'meta_description';
    public const fields_META_KEYWORDS = 'meta_keywords';
    public const fields_REDIRECT_URL = 'redirect_url';
    /** 布局配置（存储组件拖拽配置的 JSON） */
    public const fields_LAYOUT_CONFIG = 'layout_config';
    /** 布局页面ID（引用其他页面作为布局模板） */
    public const fields_LAYOUT_PAGE_ID = 'layout_page_id';
    /** 关联站点ID（Weline_Websites::Website） */
    public const fields_WEBSITE_ID = 'website_id';
    public const fields_STATUS = 'status';
    public const fields_CREATE_TIME = 'create_time';
    public const fields_UPDATE_TIME = 'update_time';
    
    // 页面类型常量
    public const TYPE_HOME = 'home_page';
    public const TYPE_ABOUT = 'about_page';
    public const TYPE_CONTACT = 'contact_page';
    public const TYPE_PRIVACY_POLICY = 'privacy_policy';
    public const TYPE_TERMS_OF_SERVICE = 'terms_of_service';
    public const TYPE_REFUND_POLICY = 'refund_policy';
    public const TYPE_SHIPPING_POLICY = 'shipping_policy';
    public const TYPE_COOKIE_POLICY = 'cookie_policy';
    /** 博客文章类型（单篇博客文章详情页） */
    public const TYPE_BLOG = 'blog_post';
    /** 博客分类类型（博客分类列表页） */
    public const TYPE_BLOG_CATEGORY = 'blog_category';
    /** 博客列表类型（博客文章列表页） */
    public const TYPE_BLOG_LIST = 'blog_list';
    public const TYPE_CUSTOM = 'custom_page';
    
    // 状态常量
    public const STATUS_DRAFT = 0;
    public const STATUS_PUBLISHED = 1;
    
    /**
     * 获取所有页面类型
     */
    public static function getPageTypes(): array
    {
        return [
            self::TYPE_HOME => __('首页'),
            self::TYPE_ABOUT => __('关于我们'),
            self::TYPE_CONTACT => __('联系我们'),
            self::TYPE_PRIVACY_POLICY => __('隐私政策'),
            self::TYPE_TERMS_OF_SERVICE => __('服务条款'),
            self::TYPE_REFUND_POLICY => __('退款政策'),
            self::TYPE_SHIPPING_POLICY => __('配送政策'),
            self::TYPE_COOKIE_POLICY => __('Cookie政策'),
            self::TYPE_BLOG => __('博客文章'),
            self::TYPE_BLOG_CATEGORY => __('博客分类'),
            self::TYPE_BLOG_LIST => __('博客列表'),
            self::TYPE_CUSTOM => __('自定义页面'),
        ];
    }
    
    /**
     * 获取博客相关的页面类型
     */
    public static function getBlogPageTypes(): array
    {
        return [
            self::TYPE_BLOG => __('博客文章'),
            self::TYPE_BLOG_CATEGORY => __('博客分类'),
            self::TYPE_BLOG_LIST => __('博客列表'),
        ];
    }
    
    /**
     * 检查是否是博客类型的页面
     */
    public function isBlogType(): bool
    {
        $type = $this->getData(self::fields_TYPE);
        return in_array($type, [self::TYPE_BLOG, self::TYPE_BLOG_CATEGORY, self::TYPE_BLOG_LIST]);
    }
    
    /**
     * 获取页面类型名称
     */
    public function getTypeName(): string
    {
        $types = self::getPageTypes();
        return $types[$this->getData(self::fields_TYPE)] ?? $this->getData(self::fields_TYPE);
    }
    
    /**
     * 获取状态名称
     */
    public function getStatusName(): string
    {
        return $this->getData(self::fields_STATUS) == self::STATUS_PUBLISHED ? __('已发布') : __('草稿');
    }
    
    /**
     * 获取选中的语言列表
     */
    public function getSelectedLocales(): array
    {
        $locales = $this->getData(self::fields_LOCALES);
        if (empty($locales)) {
            return [];
        }
        return json_decode($locales ?? '', true) ?: [];
    }
    
    /**
     * 设置选中的语言列表
     */
    public function setSelectedLocales(array $locales): self
    {
        return $this->setData(self::fields_LOCALES, json_encode($locales));
    }
    
    /**
     * 获取父页面
     */
    public function getParentPage(): ?Page
    {
        $parentId = $this->getData(self::fields_PARENT_ID);
        if (!$parentId) {
            return null;
        }
        
        $parent = clone $this;
        $parent->clear()->load($parentId);
        return $parent->getId() ? $parent : null;
    }
    
    /**
     * 获取子页面列表
     */
    public function getChildPages(): array
    {
        $children = clone $this;
        return $children->clear()
            ->where(self::fields_PARENT_ID, $this->getId())
            ->select()
            ->fetch()
            ->getItems();
    }
    
    /**
     * 获取同站点的导航页面列表
     * 
     * 用于 header 导航，返回同站点下已发布的主要页面
     * 排除博客文章详情页（TYPE_BLOG），但包含博客列表页（TYPE_BLOG_LIST）
     * 
     * @param array $excludeTypes 要排除的页面类型
     * @param int $limit 返回数量限制
     * @return array 页面列表，包含 title, handle, url, type
     */
    public function getNavigationPages(array $excludeTypes = [], int $limit = 10): array
    {
        // 使用类型转换确保 website_id 为整数，0 是有效值
        $websiteId = (int)$this->getData(self::fields_WEBSITE_ID);
        
        // 默认排除博客文章详情页
        if (empty($excludeTypes)) {
            $excludeTypes = [self::TYPE_BLOG, self::TYPE_BLOG_CATEGORY];
        }
        
        $pages = clone $this;
        $query = $pages->clear()
            ->where(self::fields_WEBSITE_ID, $websiteId)
            ->where(self::fields_STATUS, self::STATUS_PUBLISHED)
            ->where(self::fields_PARENT_ID, 0); // 只获取顶级页面
        
        // 排除指定类型
        if (!empty($excludeTypes)) {
            $query->where(self::fields_TYPE, $excludeTypes, 'NOT IN');
        }
        
        $items = $query->order(self::fields_TYPE, 'ASC') // 首页排前面
            ->limit($limit)
            ->select()
            ->fetch()
            ->getItems();
        
        $result = [];
        foreach ($items as $item) {
            $handle = $item->getData(self::fields_HANDLE);
            $result[] = [
                'title' => $item->getData(self::fields_TITLE) ?: $item->getData(self::fields_NAME),
                'handle' => $handle,
                'url' => '/' . $handle, // SEO 友好的 URL（通过路由重写）
                'type' => $item->getData(self::fields_TYPE),
                'page_id' => $item->getId(),
            ];
        }
        
        return $result;
    }
    
    /**
     * 获取博客文章列表（用于博客类型页面）
     * 
     * @param int $limit 返回数量
     * @param string $orderBy 排序字段
     * @param string $orderDir 排序方向
     * @return array 博客文章列表
     */
    public function getBlogPosts(int $limit = 10, string $orderBy = 'published_at', string $orderDir = 'DESC'): array
    {
        try {
            $blogPostClass = '\\GuoLaiRen\\Blog\\Model\\Post';
            if (!class_exists($blogPostClass)) {
                return [];
            }
            
            $websiteId = \Weline\Websites\Data\WebsiteData::getWebsiteId();
            $postModel = \Weline\Framework\Manager\ObjectManager::getInstance($blogPostClass);
            
            // 获取已发布的文章，按最新排序
            $query = $postModel->clear()
                ->where('status', 1); // STATUS_PUBLISHED
            
            // 根据当前网站过滤
            if ($websiteId) {
                $query->where('site_id', $websiteId);
            }
            
            $posts = $query->order($orderBy, $orderDir)
                ->limit($limit)
                ->select()
                ->fetch()
                ->getItems();
            
            $result = [];
            foreach ($posts as $post) {
                $slug = $post->getData('slug');
                $result[] = [
                    'post_id' => $post->getId(),
                    'title' => $post->getData('title'),
                    'slug' => $slug,
                    'url' => '/blog/' . $slug, // SEO 友好的博客文章 URL
                    'summary' => $post->getData('summary'),
                    'cover_image' => $post->getData('cover_image'),
                    'author' => $post->getData('author'),
                    'published_at' => $post->getData('published_at'),
                    'view_count' => $post->getData('view_count'),
                    'category_id' => $post->getData('category_id'),
                ];
            }
            
            return $result;
        } catch (\Throwable $e) {
            // 如果 Blog 模块不存在或出错，返回空数组
            return [];
        }
    }
    
    /**
     * 获取首页（用于继承样式配置）
     * 
     * 博客等页面可以从首页继承 header/footer 配置
     * 
     * @param int|null $websiteId 站点ID，不传则使用当前页面的站点ID（0 表示默认/全局站点）
     * @param bool $publishedOnly 是否只查找已发布的首页（默认true用于前台渲染，false用于后台编辑）
     * @return Page|null 首页对象
     */
    public function getHomePage(?int $websiteId = null, bool $publishedOnly = true): ?Page
    {
        // 使用 ?? 运算符处理 null，保留 0 作为有效的 website_id
        $websiteId = $websiteId ?? (int)$this->getData(self::fields_WEBSITE_ID);
        
        $homePage = clone $this;
        $homePage->clear()
            ->where(self::fields_WEBSITE_ID, $websiteId)
            ->where(self::fields_TYPE, self::TYPE_HOME);
        
        // 仅在前台渲染时检查发布状态，后台编辑时允许访问草稿状态的首页
        if ($publishedOnly) {
            $homePage->where(self::fields_STATUS, self::STATUS_PUBLISHED);
        }
        
        $homePage->find()->fetch();
        
        return $homePage->getId() ? $homePage : null;
    }
    
    /**
     * 获取首页的样式配置（用于博客等页面继承）
     * 
     * @return array 包含 style, style_setting, layout_config 等配置
     */
    public function getHomePageConfig(): array
    {
        $homePage = $this->getHomePage();
        if (!$homePage) {
            return [];
        }
        
        return [
            'style' => $homePage->getData(self::fields_STYLE) ?: 'default',
            'style_setting' => $homePage->getStyleSetting(),
            'layout_config' => $homePage->getLayoutConfig(),
            'logo' => $homePage->getData(self::fields_LOGO),
            'icon' => $homePage->getData(self::fields_ICON),
            'ga4_id' => $homePage->getData(self::fields_GA4_ID),
            'gtm_id' => $homePage->getData(self::fields_GTM_ID),
            'fb_pixel_id' => $homePage->getData(self::fields_FB_PIXEL_ID),
            'header_custom_code' => $homePage->getData(self::fields_HEADER_CUSTOM_CODE),
            'footer_custom_code' => $homePage->getData(self::fields_FOOTER_CUSTOM_CODE),
        ];
    }
    
    /**
     * 获取布局配置
     */
    public function getLayoutConfig(): array
    {
        $config = $this->getData(self::fields_LAYOUT_CONFIG);
        if (empty($config)) {
            return [];
        }
        return json_decode($config, true) ?: [];
    }
    
    /**
     * 保存布局配置
     * 
     * 特殊处理：
     * - 子页面修改 header/footer 时，实际保存到首页
     * - 子页面只保存自己的 content 配置
     * 
     * @param array $layoutConfig 布局配置
     * @param bool $syncHeaderFooterToHome 是否将 header/footer 同步到首页
     * @return self
     */
    public function saveLayoutConfig(array $layoutConfig, bool $syncHeaderFooterToHome = true): self
    {
        $pageType = $this->getData(self::fields_TYPE);
        $isHomePage = ($pageType === self::TYPE_HOME);
        
        if ($isHomePage || !$syncHeaderFooterToHome) {
            // 首页直接保存完整配置
            $this->setData(self::fields_LAYOUT_CONFIG, json_encode($layoutConfig));
            return $this;
        }
        
        // 子页面：header/footer 保存到首页，content 保存到自己
        $homePage = $this->getHomePage();
        
        if ($homePage && $homePage->getId()) {
            // 获取首页当前的布局配置
            $homeLayout = $homePage->getLayoutConfig();
            $needSaveHome = false;
            
            // 如果子页面提交了 header 配置，同步到首页
            if (!empty($layoutConfig['header'])) {
                $homeLayout['header'] = $layoutConfig['header'];
                $needSaveHome = true;
            }
            
            // 如果子页面提交了 footer 配置，同步到首页
            if (!empty($layoutConfig['footer'])) {
                $homeLayout['footer'] = $layoutConfig['footer'];
                $needSaveHome = true;
            }
            
            // 保存首页的 header/footer 配置
            if ($needSaveHome) {
                $homePage->setData(self::fields_LAYOUT_CONFIG, json_encode($homeLayout));
                $homePage->save();
            }
        }
        
        // 子页面只保存自己的 content 配置
        $currentLayout = $this->getLayoutConfig();
        if (!empty($layoutConfig['content'])) {
            $currentLayout['content'] = $layoutConfig['content'];
        }
        // 不保存 header/footer 到子页面（因为它们来自首页）
        unset($currentLayout['header'], $currentLayout['footer']);
        
        $this->setData(self::fields_LAYOUT_CONFIG, json_encode($currentLayout));
        
        return $this;
    }
    
    /**
     * 获取完整的布局配置（包含从首页继承的 header/footer）
     * 
     * @return array
     */
    public function getFullLayoutConfig(): array
    {
        $currentLayout = $this->getLayoutConfig();
        $pageType = $this->getData(self::fields_TYPE);
        
        // 首页直接返回自己的配置
        if ($pageType === self::TYPE_HOME) {
            return $currentLayout;
        }
        
        // 子页面：从首页继承 header/footer
        $homeConfig = $this->getHomePageConfig();
        $homeLayout = $homeConfig['layout_config'] ?? [];
        
        // header/footer 从首页继承
        if (!empty($homeLayout['header'])) {
            $currentLayout['header'] = $homeLayout['header'];
        }
        if (!empty($homeLayout['footer'])) {
            $currentLayout['footer'] = $homeLayout['footer'];
        }
        
        return $currentLayout;
    }
    
    /**
     * 获取博客分类列表
     * 
     * @return array 分类列表
     */
    public function getBlogCategories(): array
    {
        try {
            $categoryClass = '\\GuoLaiRen\\Blog\\Model\\Category';
            if (!class_exists($categoryClass)) {
                return [];
            }
            
            $websiteId = \Weline\Websites\Data\WebsiteData::getWebsiteId();
            $categoryModel = \Weline\Framework\Manager\ObjectManager::getInstance($categoryClass);
            
            $query = $categoryModel->clear()
                ->where('status', 1); // 启用的分类
            
            // 根据当前网站过滤
            if ($websiteId) {
                $query->where('site_id', $websiteId);
            }
            
            $categories = $query->order('sort_order', 'ASC')
                ->select()
                ->fetch()
                ->getItems();
            
            $result = [];
            foreach ($categories as $cat) {
                $slug = $cat->getData('slug');
                $result[] = [
                    'category_id' => $cat->getId(),
                    'name' => $cat->getData('name'),
                    'slug' => $slug,
                    'url' => '/blog/category/' . $slug,
                    'description' => $cat->getData('description'),
                ];
            }
            
            return $result;
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    /**
     * 获取布局页面ID
     * 
     * @return int|null
     */
    public function getLayoutPageId(): ?int
    {
        $layoutPageId = $this->getData(self::fields_LAYOUT_PAGE_ID);
        return $layoutPageId ? (int)$layoutPageId : null;
    }
    
    /**
     * 设置布局页面ID
     * 
     * @param int|null $layoutPageId
     * @return self
     */
    public function setLayoutPageId(?int $layoutPageId): self
    {
        return $this->setData(self::fields_LAYOUT_PAGE_ID, $layoutPageId);
    }
    
    /**
     * 获取布局页面（被引用的布局模板页面）
     * 
     * @return Page|null
     */
    public function getLayoutPage(): ?Page
    {
        $layoutPageId = $this->getLayoutPageId();
        if (!$layoutPageId) {
            return null;
        }
        
        $layoutPage = clone $this;
        $layoutPage->clear()->load($layoutPageId);
        return $layoutPage->getId() ? $layoutPage : null;
    }
    
    /**
     * 获取布局拥有者页面ID（用于可视化编辑和渲染）
     * 
     * 解析逻辑：
     * - 如果设置了 layout_page_id，则返回该页面ID
     * - 否则返回自身页面ID
     * 
     * @return int
     */
    public function getLayoutOwnerPageId(): int
    {
        $layoutPageId = $this->getLayoutPageId();
        if ($layoutPageId) {
            return $layoutPageId;
        }
        return (int)$this->getId();
    }
    
    /**
     * 获取可作为布局模板的页面列表
     * 
     * @param int|null $excludePageId 要排除的页面ID（通常是当前编辑的页面）
     * @return array
     */
    public function getAvailableLayoutPages(?int $excludePageId = null): array
    {
        $websiteId = $this->getData(self::fields_WEBSITE_ID);
        
        $pages = clone $this;
        $query = $pages->clear();
        
        // 只获取同站点的已发布页面
        if ($websiteId) {
            $query->where(self::fields_WEBSITE_ID, $websiteId);
        }
        $query->where(self::fields_STATUS, self::STATUS_PUBLISHED);
        
        // 排除当前页面
        if ($excludePageId) {
            $query->where(self::fields_ID, $excludePageId, '!=');
        }
        
        $items = $query->order(self::fields_TYPE, 'ASC')
            ->order(self::fields_NAME, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
        
        $result = [];
        foreach ($items as $item) {
            $result[] = [
                'page_id' => $item->getId(),
                'name' => $item->getData(self::fields_NAME),
                'title' => $item->getData(self::fields_TITLE),
                'handle' => $item->getData(self::fields_HANDLE),
                'type' => $item->getData(self::fields_TYPE),
                'type_name' => $item->getTypeName(),
            ];
        }
        
        return $result;
    }
    
    /**
     * 获取样式配置
     */
    public function getStyleSetting(): array
    {
        $setting = $this->getData(self::fields_STYLE_SETTING);
        if (empty($setting)) {
            return [];
        }
        return json_decode($setting ?? '', true) ?: [];
    }
    
    /**
     * 设置样式配置
     */
    public function setStyleSetting(array $setting): self
    {
        return $this->setData(self::fields_STYLE_SETTING, json_encode($setting));
    }
    
    /**
     * 获取样式配置的某个值
     */
    public function getStyleSettingValue(string $key, $default = null)
    {
        $settings = $this->getStyleSetting();
        return $settings[$key] ?? $default;
    }

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        // 删除旧表（如果存在）- 仅在重建表结构时临时启用
        // $setup->dropTable();
        
        // 检查表是否已存在
        if ($setup->tableExist()) {
            return;
        }
        
        // 创建新表
        $setup->createTable('页面构建器-页面表')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '页面ID'
            )
            ->addColumn(
                self::fields_HANDLE,
                TableInterface::column_type_VARCHAR,
                100,
                'unique', // 允许NULL，支持首页类型可以不填写handle
                '页面句柄'
            )
            ->addColumn(
                self::fields_TYPE,
                TableInterface::column_type_VARCHAR,
                50,
                'not null',
                '页面类型'
            )
            ->addColumn(
                self::fields_NAME,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '页面名称'
            )
            ->addColumn(
                self::fields_TITLE,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '页面标题'
            )
            ->addColumn(
                self::fields_CONTENT,
                TableInterface::column_type_TEXT,
                0,
                '',
                '页面内容'
            )
            ->addColumn(
                self::fields_PARENT_ID,
                TableInterface::column_type_INTEGER,
                0,
                'default 0',
                '父页面ID'
            )
            ->addColumn(
                self::fields_WEBSITE_ID,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 1',
                '关联站点ID'
            )
            ->addColumn(
                self::fields_GA4_ID,
                TableInterface::column_type_VARCHAR,
                100,
                '',
                'Google Analytics 4 ID'
            )
            ->addColumn(
                self::fields_GTM_ID,
                TableInterface::column_type_VARCHAR,
                100,
                '',
                'Google Tag Manager ID'
            )
            ->addColumn(
                self::fields_FB_PIXEL_ID,
                TableInterface::column_type_VARCHAR,
                100,
                '',
                'Facebook Pixel ID'
            )
            ->addColumn(
                self::fields_CTA_EVENT_NAME,
                TableInterface::column_type_VARCHAR,
                100,
                '',
                'CTA转化事件名称'
            )
            ->addColumn(
                self::fields_LOGO,
                TableInterface::column_type_VARCHAR,
                255,
                '',
                'Logo图片路径'
            )
            ->addColumn(
                self::fields_ICON,
                TableInterface::column_type_VARCHAR,
                255,
                '',
                'Icon图标路径'
            )
            ->addColumn(
                self::fields_LOCALES,
                TableInterface::column_type_TEXT,
                0,
                '',
                '选中的语言列表(JSON)'
            )
            ->addColumn(
                self::fields_DEFAULT_LOCALE,
                TableInterface::column_type_VARCHAR,
                10,
                '',
                '默认语言代码'
            )
            ->addColumn(
                self::fields_STYLE,
                TableInterface::column_type_VARCHAR,
                100,
                '',
                '页面样式模板名称'
            )
            ->addColumn(
                self::fields_STYLE_SETTING,
                TableInterface::column_type_TEXT,
                0,
                '',
                '页面样式配置(JSON)'
            )
            ->addColumn(
                self::fields_META_TITLE,
                TableInterface::column_type_VARCHAR,
                255,
                '',
                'SEO标题'
            )
            ->addColumn(
                self::fields_META_DESCRIPTION,
                TableInterface::column_type_TEXT,
                0,
                '',
                'SEO描述'
            )
            ->addColumn(
                self::fields_META_KEYWORDS,
                TableInterface::column_type_VARCHAR,
                255,
                '',
                'SEO关键词'
            )
            ->addColumn(
                self::fields_REDIRECT_URL,
                TableInterface::column_type_VARCHAR,
                500,
                '',
                '表单提交后跳转URL'
            )
            ->addColumn(
                self::fields_HEADER_CUSTOM_CODE,
                TableInterface::column_type_TEXT,
                0,
                '',
                'Header自定义代码（GSC验证、统计代码等）'
            )
            ->addColumn(
                self::fields_FOOTER_CUSTOM_CODE,
                TableInterface::column_type_TEXT,
                0,
                '',
                'Footer自定义代码（GSC验证、统计代码等）'
            )
            ->addColumn(
                self::fields_STATUS,
                TableInterface::column_type_SMALLINT,
                1,
                'not null default 0',
                '状态:0草稿,1已发布'
            )
            ->addColumn(
                self::fields_CREATE_TIME,
                TableInterface::column_type_DATETIME,
                0,
                'not null default CURRENT_TIMESTAMP',
                '创建时间'
            )
            ->addColumn(
                self::fields_UPDATE_TIME,
                TableInterface::column_type_DATETIME,
                0,
                'not null default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP',
                '更新时间'
            )
            ->addIndex(TableInterface::index_type_UNIQUE, 'idx_handle_unique', [self::fields_HANDLE], '句柄唯一索引（允许多个NULL值）')
            ->addIndex(TableInterface::index_type_KEY, 'idx_type', [self::fields_TYPE], '类型索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_parent_id', [self::fields_PARENT_ID], '父页面索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_website_id', [self::fields_WEBSITE_ID], '站点索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_status', [self::fields_STATUS], '状态索引')
            ->create();
    }

    /**
     * 升级表结构
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 修改 handle 字段，允许 NULL（支持首页类型可以不填写handle）
        if ($setup->tableExist() && $setup->hasField(self::fields_HANDLE)) {
            try {
                $tableName = $setup->getTable();
                $connector = $this->getConnection()->getConnector();
                
                // 检查字段当前是否允许NULL（通过查询系统表）
                $checkSql = "SELECT is_nullable FROM information_schema.columns 
                            WHERE table_schema = 'public' 
                            AND table_name = '{$tableName}' 
                            AND column_name = '" . self::fields_HANDLE . "'";
                $result = $connector->query($checkSql)->fetchArray();
                $isNullable = !empty($result) && ($result[0]['is_nullable'] ?? 'NO') === 'YES';
                
                // 如果字段当前不允许NULL，需要修改
                if (!$isNullable) {
                    // 先删除旧的唯一索引（如果存在）
                    try {
                        $connector->query("DROP INDEX IF EXISTS idx_handle")->fetchArray();
                        $connector->query("DROP INDEX IF EXISTS idx_handle_unique")->fetchArray();
                        // 也可能存在通过UNIQUE约束创建的索引
                        $connector->query("ALTER TABLE {$tableName} DROP CONSTRAINT IF EXISTS {$tableName}_handle_key")->fetchArray();
                    } catch (\Exception $e) {
                        // 索引或约束可能不存在，忽略错误
                    }
                    
                    // 使用 alterTable 方法修改字段允许 NULL
                    $alter = $setup->alterTable();
                    $alter->alterColumn(
                        self::fields_HANDLE,
                        self::fields_HANDLE,
                        '',
                        TableInterface::column_type_VARCHAR,
                        100,
                        '', // 移除 'not null unique'，改为允许 NULL
                        '页面句柄'
                    );
                    $alter->alter();
                    
                    // 显式设置字段允许NULL（PostgreSQL需要单独执行）
                    try {
                        $connector->query("ALTER TABLE {$tableName} ALTER COLUMN \"" . self::fields_HANDLE . "\" DROP NOT NULL")->fetchArray();
                    } catch (\Exception $e) {
                        // 可能已经允许NULL，忽略错误
                    }
                    
                    // 重新创建唯一索引（允许多个 NULL 值，因为 NULL 不参与唯一性约束）
                    try {
                        $indexSql = "CREATE UNIQUE INDEX IF NOT EXISTS idx_handle_unique ON {$tableName} (\"" . self::fields_HANDLE . "\")";
                        $connector->query($indexSql)->fetchArray();
                    } catch (\Exception $e) {
                        // 索引可能已存在，忽略错误
                    }
                }
            } catch (\Exception $e) {
                // 升级失败，记录日志但不中断
                if (defined('DEV') && DEV) {
                    error_log('PageBuilder Page Model Upgrade Error (handle null): ' . $e->getMessage());
                }
            }
        }
        
        // 添加 website_id 字段（如果不存在）
        if ($setup->tableExist() && !$setup->hasField(self::fields_WEBSITE_ID)) {
            try {
                $setup->alterTable()->addColumn(
                    self::fields_WEBSITE_ID,
                    '',
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null default 1',
                    '关联站点ID'
                )->alter();
                
                // 如果通过 AlterWithBackup 仍未成功添加字段，则降级为直接执行 ALTER TABLE 语句作为兜底
                if (!$setup->hasField(self::fields_WEBSITE_ID)) {
                    $tableName = $setup->getTable();
                    // PostgreSQL 需要双引号包裹字段名，表名已经包含引号
                    $fieldName = '"' . self::fields_WEBSITE_ID . '"';
                    $sql = sprintf(
                        'ALTER TABLE %s ADD COLUMN %s INTEGER NOT NULL DEFAULT 1',
                        $tableName,
                        $fieldName
                    );
                    $this->getConnection()
                        ->getConnector()
                        ->query($sql)
                        ->fetchArray();
                    
                    // 添加索引
                    $indexName = 'idx_' . self::fields_WEBSITE_ID;
                    $indexSql = sprintf(
                        'CREATE INDEX IF NOT EXISTS %s ON %s (%s)',
                        $indexName,
                        $tableName,
                        $fieldName
                    );
                    try {
                        $this->getConnection()
                            ->getConnector()
                            ->query($indexSql)
                            ->fetchArray();
                    } catch (\Exception $e) {
                        // 索引可能已存在，忽略错误
                    }
                }
            } catch (\Throwable $e) {
                throw $e;
            }
        }

        // 添加 default_locale 字段（如果不存在）
        if ($setup->tableExist() && !$setup->hasField(self::fields_DEFAULT_LOCALE)) {
            $setup->alterTable()->addColumn(
                self::fields_DEFAULT_LOCALE,
                '',
                TableInterface::column_type_VARCHAR,
                10,
                '',
                '默认语言代码'
            )->alter();
        }
        
        // 添加 header_custom_code 字段（如果不存在）
        // 默认会自动恢复之前备份的数据（如果有）
        if ($setup->tableExist() && !$setup->hasField(self::fields_HEADER_CUSTOM_CODE)) {
            try {
                $setup->alterTable()->addColumn(
                    self::fields_HEADER_CUSTOM_CODE,
                    '',
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    'Header自定义代码（GSC验证、统计代码等）'
                )->alter();
                
                // 如果通过 AlterWithBackup 仍未成功添加字段，则降级为直接执行 ALTER TABLE 语句作为兜底
                if (!$setup->hasField(self::fields_HEADER_CUSTOM_CODE)) {
                    $tableName = $setup->getTable();
                    // PostgreSQL 需要双引号包裹字段名，表名已经包含引号
                    $fieldName = '"' . self::fields_HEADER_CUSTOM_CODE . '"';
                    $sql = sprintf(
                        'ALTER TABLE %s ADD COLUMN %s TEXT',
                        $tableName,
                        $fieldName
                    );
                    $this->getConnection()
                        ->getConnector()
                        ->query($sql)
                        ->fetchArray();
                }
            } catch (\Throwable $e) {
                throw $e;
            }
        }
        
        // 添加 footer_custom_code 字段（如果不存在）
        // 默认会自动恢复之前备份的数据（如果有）
        if ($setup->tableExist() && !$setup->hasField(self::fields_FOOTER_CUSTOM_CODE)) {
            try {
                $setup->alterTable()->addColumn(
                    self::fields_FOOTER_CUSTOM_CODE,
                    '',
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    'Footer自定义代码（GSC验证、统计代码等）'
                )->alter();
                
                // 如果通过 AlterWithBackup 仍未成功添加字段，则降级为直接执行 ALTER TABLE 语句作为兜底
                if (!$setup->hasField(self::fields_FOOTER_CUSTOM_CODE)) {
                    $tableName = $setup->getTable();
                    // PostgreSQL 需要双引号包裹字段名，表名已经包含引号
                    $fieldName = '"' . self::fields_FOOTER_CUSTOM_CODE . '"';
                    $sql = sprintf(
                        'ALTER TABLE %s ADD COLUMN %s TEXT',
                        $tableName,
                        $fieldName
                    );
                    $this->getConnection()
                        ->getConnector()
                        ->query($sql)
                        ->fetchArray();
                }
            } catch (\Throwable $e) {
                throw $e;
            }
        }
        
        // 添加 layout_config 字段（如果不存在）- 用于存储组件拖拽配置
        if ($setup->tableExist() && !$setup->hasField(self::fields_LAYOUT_CONFIG)) {
            try {
                $setup->alterTable()->addColumn(
                    self::fields_LAYOUT_CONFIG,
                    '',
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    '布局配置JSON（组件拖拽配置）'
                )->alter();
                
                // 如果通过 AlterWithBackup 仍未成功添加字段，则降级为直接执行 ALTER TABLE 语句作为兜底
                if (!$setup->hasField(self::fields_LAYOUT_CONFIG)) {
                    $tableName = $setup->getTable();
                    $fieldName = '"' . self::fields_LAYOUT_CONFIG . '"';
                    $sql = sprintf(
                        'ALTER TABLE %s ADD COLUMN %s TEXT',
                        $tableName,
                        $fieldName
                    );
                    $this->getConnection()
                        ->getConnector()
                        ->query($sql)
                        ->fetchArray();
                }
            } catch (\Throwable $e) {
                // 字段可能已存在，忽略错误
            }
        }
        
        // 添加 layout_page_id 字段（如果不存在）- 用于引用其他页面作为布局模板
        if ($setup->tableExist() && !$setup->hasField(self::fields_LAYOUT_PAGE_ID)) {
            try {
                $setup->alterTable()->addColumn(
                    self::fields_LAYOUT_PAGE_ID,
                    '',
                    TableInterface::column_type_INTEGER,
                    0,
                    'default null',
                    '布局页面ID（引用其他页面作为布局模板）'
                )->alter();
                
                // 如果通过 AlterWithBackup 仍未成功添加字段，则降级为直接执行 ALTER TABLE 语句作为兜底
                if (!$setup->hasField(self::fields_LAYOUT_PAGE_ID)) {
                    $tableName = $setup->getTable();
                    $fieldName = '"' . self::fields_LAYOUT_PAGE_ID . '"';
                    $sql = sprintf(
                        'ALTER TABLE %s ADD COLUMN %s INTEGER DEFAULT NULL',
                        $tableName,
                        $fieldName
                    );
                    $this->getConnection()
                        ->getConnector()
                        ->query($sql)
                        ->fetchArray();
                    
                    // 添加索引
                    $indexName = 'idx_' . self::fields_LAYOUT_PAGE_ID;
                    $indexSql = sprintf(
                        'CREATE INDEX IF NOT EXISTS %s ON %s (%s)',
                        $indexName,
                        $tableName,
                        $fieldName
                    );
                    try {
                        $this->getConnection()
                            ->getConnector()
                            ->query($indexSql)
                            ->fetchArray();
                    } catch (\Exception $e) {
                        // 索引可能已存在，忽略错误
                    }
                }
            } catch (\Throwable $e) {
                // 字段可能已存在，忽略错误
            }
        }
    }

    /**
     * 设置表结构
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        // 先安装表（如果是全新安装）
        $this->install($setup, $context);
        // 再执行升级逻辑（为已存在的表补充新字段）
        $this->upgrade($setup, $context);
    }
    
    /**
     * 保存后钩子 - 自动提交已发布页面的 URL 到 SEO 模块
     */
    public function save_after(): void
    {
        parent::save_after();
        
        // 仅当页面为已发布状态时，提交 URL 到 SEO 模块
        $status = (int)$this->getData(self::fields_STATUS);
        if ($status !== self::STATUS_PUBLISHED) {
            return;
        }
        
        try {
            // 检查 SEO 模块是否可用
            if (!class_exists(\Weline\Seo\Service\UrlSubmitService::class)) {
                return;
            }
            
            // 获取页面 URL
            $pageUrl = $this->getFullUrl();
            if (empty($pageUrl)) {
                return;
            }
            
            /** @var \Weline\Seo\Service\UrlSubmitService $urlSubmitService */
            $urlSubmitService = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Seo\Service\UrlSubmitService::class
            );
            
            $urlSubmitService->requestSubmit(
                $pageUrl,
                'page_builder',              // scope
                [
                    'subject_type' => 'page',
                    'subject_id'   => (int)$this->getId(),
                ]
            );
        } catch (\Throwable $e) {
            // SEO 提交失败不应影响页面保存流程，静默处理
            if (defined('DEV') && DEV) {
                error_log('PageBuilder SEO URL Submit Error: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * 获取页面的完整 URL
     * 
     * @return string|null 完整 URL
     */
    public function getFullUrl(): ?string
    {
        $websiteId = (int)$this->getData(self::fields_WEBSITE_ID);
        $handle = $this->getData(self::fields_HANDLE);
        $pageType = $this->getData(self::fields_TYPE);
        
        try {
            // 获取站点 URL
            $websiteModel = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Websites\Model\Website::class
            );
            $website = clone $websiteModel;
            $website->load($websiteId);
            
            if (!$website->getId()) {
                return null;
            }
            
            $baseUrl = rtrim($website->getUrl(), '/');
            
            // 首页
            if ($pageType === self::TYPE_HOME) {
                return $baseUrl . '/';
            }
            
            // 其他页面使用 handle
            if (!empty($handle)) {
                return $baseUrl . '/' . ltrim((string)$handle, '/');
            }
            
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}

