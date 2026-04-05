<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * 页面模型
 */

namespace GuoLaiRen\PageBuilder\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

#[Table(comment: '页面构建器-页面表')]
#[Index(name: 'idx_website_handle_unique', columns: ['website_id', 'handle'], type: 'UNIQUE', comment: '同一站点内句柄唯一，无站点时 website_id=0')]
#[Index(name: 'idx_type', columns: ['type'], comment: '类型索引')]
#[Index(name: 'idx_parent_id', columns: ['parent_id'], comment: '父页面索引')]
#[Index(name: 'idx_website_id', columns: ['website_id'], comment: '站点索引')]
#[Index(name: 'idx_status', columns: ['status'], comment: '状态索引')]
#[Index(name: 'idx_layout_page_id', columns: ['layout_page_id'], comment: '布局页面索引')]
class Page extends Model
{
    public const schema_table = 'guolairen_page_builder_page';
    public const schema_primary_key = 'page_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '页面ID')]
    public const schema_fields_ID = 'page_id';
    #[Col(type: 'varchar', length: 100, nullable: true, comment: '页面句柄')]
    public const schema_fields_HANDLE = 'handle';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '页面类型')]
    public const schema_fields_TYPE = 'type';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '页面名称')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '页面标题')]
    public const schema_fields_TITLE = 'title';
    #[Col(type: 'text', nullable: true, comment: '页面内容')]
    public const schema_fields_CONTENT = 'content';
    #[Col(type: 'int', nullable: true, default: 0, comment: '父页面ID')]
    public const schema_fields_PARENT_ID = 'parent_id';
    #[Col(type: 'int', nullable: false, default: 1, comment: '关联站点ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col(type: 'varchar', length: 100, nullable: true, comment: 'Google Analytics 4 ID')]
    public const schema_fields_GA4_ID = 'ga4_id';
    #[Col(type: 'varchar', length: 100, nullable: true, comment: 'Google Tag Manager ID')]
    public const schema_fields_GTM_ID = 'gtm_id';
    #[Col(type: 'varchar', length: 100, nullable: true, comment: 'Facebook Pixel ID')]
    public const schema_fields_FB_PIXEL_ID = 'fb_pixel_id';
    #[Col(type: 'varchar', length: 100, nullable: true, comment: 'CTA转化事件名称')]
    public const schema_fields_CTA_EVENT_NAME = 'cta_event_name';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Logo图片路径')]
    public const schema_fields_LOGO = 'logo';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Icon图标路径')]
    public const schema_fields_ICON = 'icon';
    #[Col(type: 'text', nullable: true, comment: '选中的语言列表(JSON)')]
    public const schema_fields_LOCALES = 'locales';
    #[Col(type: 'varchar', length: 10, nullable: true, comment: '默认语言代码')]
    public const schema_fields_DEFAULT_LOCALE = 'default_locale';
    #[Col(type: 'varchar', length: 100, nullable: true, comment: '页面样式模板名称')]
    public const schema_fields_STYLE = 'style';
    #[Col(type: 'text', nullable: true, comment: '页面样式配置(JSON)')]
    public const schema_fields_STYLE_SETTING = 'style_setting';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'SEO标题')]
    public const schema_fields_META_TITLE = 'meta_title';
    #[Col(type: 'text', nullable: true, comment: 'SEO描述')]
    public const schema_fields_META_DESCRIPTION = 'meta_description';
    #[Col(type: 'text', nullable: true, comment: 'AI生成用页面描述（可随时编辑，生成前自动保存）')]
    public const schema_fields_AI_DESCRIPTION = 'ai_description';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'SEO关键词')]
    public const schema_fields_META_KEYWORDS = 'meta_keywords';
    #[Col(type: 'varchar', length: 500, nullable: true, comment: '表单提交后跳转URL')]
    public const schema_fields_REDIRECT_URL = 'redirect_url';
    #[Col(type: 'text', nullable: true, comment: 'Header自定义代码（GSC验证、统计代码等）')]
    public const schema_fields_HEADER_CUSTOM_CODE = 'header_custom_code';
    #[Col(type: 'text', nullable: true, comment: 'Footer自定义代码（GSC验证、统计代码等）')]
    public const schema_fields_FOOTER_CUSTOM_CODE = 'footer_custom_code';
    #[Col(type: 'text', nullable: true, comment: '布局配置JSON（组件拖拽配置）')]
    public const schema_fields_LAYOUT_CONFIG = 'layout_config';
    #[Col(type: 'int', nullable: true, comment: '布局页面ID（引用其他页面作为布局模板）')]
    public const schema_fields_LAYOUT_PAGE_ID = 'layout_page_id';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: '状态:0草稿,1已发布')]
    public const schema_fields_STATUS = 'status';
    /** 空字符串表示传统主题渲染；ai_html 为 HTML 区块拼接轨 */
    #[Col(type: 'varchar', length: 32, nullable: false, default: '', comment: '前台渲染模式：空=主题，ai_html=区块HTML')]
    public const schema_fields_RENDER_MODE = 'render_mode';
    #[Col(type: 'text', nullable: true, comment: 'AI 页面区块编辑态 JSON：blocks[] 等')]
    public const schema_fields_AI_LAYOUT = 'ai_layout';
    #[Col(type: 'text', nullable: true, comment: '最近 N 次发布快照 JSON 数组（消毒后）')]
    public const schema_fields_AI_PUBLISH_SNAPSHOTS = 'ai_publish_snapshots';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATE_TIME = 'create_time';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATE_TIME = 'update_time';

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

    public const RENDER_MODE_THEME = '';
    public const RENDER_MODE_AI_HTML = 'ai_html';
    /** 发布快照保留份数上限（与计划「最近 N」一致，可调） */
    public const AI_PUBLISH_SNAPSHOT_MAX = 5;
    
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
     * 获取各页面类型对应的 AI 生成提示词（用于弹窗展示与后端提示构建）
     * @return array<string, string> type => instruction
     */
    public static function getPageTypePromptInstructionsMap(): array
    {
        return [
            self::TYPE_HOME => __('【首页】内容应突出核心价值主张、产品/服务亮点、主要卖点；文案简短有力，适合首屏与首屏以下关键区块；注重转化与品牌印象。'),
            self::TYPE_ABOUT => __('【关于我们】内容应包含：公司/品牌介绍、使命与愿景、核心团队或创始人、发展历程/里程碑、核心价值观或特色；语气专业且富有亲和力。'),
            self::TYPE_CONTACT => __('【联系我们】内容应包含：联系表单说明、客服/销售邮箱与电话、办公地址、营业时间、常见联系方式提示；确保用户能快速找到联系渠道。'),
            self::TYPE_PRIVACY_POLICY => __('【隐私政策】内容应包含：数据收集范围、使用目的、存储与安全措施、用户权利（访问/更正/删除）、第三方共享说明、Cookie 与追踪说明、政策更新方式。'),
            self::TYPE_TERMS_OF_SERVICE => __('【服务条款】内容应包含：服务范围与说明、用户责任与禁止行为、知识产权、免责声明、争议解决、条款变更通知。'),
            self::TYPE_REFUND_POLICY => __('【退款政策】内容应包含：退款适用条件、申请流程与时限、退款方式与到账时间、不可退款情形、客服联系方式。'),
            self::TYPE_SHIPPING_POLICY => __('【配送政策】内容应包含：配送范围、配送时效、配送费用说明、物流跟踪、签收与退货相关说明。'),
            self::TYPE_COOKIE_POLICY => __('【Cookie 政策】内容应包含：Cookie 用途与类型、必要与非必要 Cookie 说明、用户如何管理与禁用 Cookie、第三方 Cookie 说明。'),
            self::TYPE_BLOG => __('【博客文章】内容为单篇文章正文：标题、引言、分段正文、配图占位提示；符合博客文章风格与可读性。'),
            self::TYPE_BLOG_LIST => __('【博客列表】内容为列表页说明：栏目介绍、文章摘要展示说明；引导用户浏览文章列表。'),
            self::TYPE_BLOG_CATEGORY => __('【博客分类】内容为分类介绍：该分类主题说明、典型内容范围；引导用户浏览该分类下文章。'),
            self::TYPE_CUSTOM => __('【自定义页面】根据页面描述生成符合主题的完整内容；结构清晰、信息完整。'),
        ];
    }
    
    /**
     * 根据页面类型返回默认 handle（仅子页面用；首页允许 handle 为空）
     * 子页面 handle 为空时用类型对应的友好路径，如 about_page -> about
     *
     * @param string $type 页面类型
     * @return string 用作 URL 的 handle，首页返回空字符串
     */
    public static function getDefaultHandleForType(string $type): string
    {
        if ($type === self::TYPE_HOME) {
            return '';
        }
        $map = [
            self::TYPE_ABOUT => 'about',
            self::TYPE_CONTACT => 'contact',
            self::TYPE_PRIVACY_POLICY => 'privacy',
            self::TYPE_TERMS_OF_SERVICE => 'terms',
            self::TYPE_REFUND_POLICY => 'refund',
            self::TYPE_SHIPPING_POLICY => 'shipping',
            self::TYPE_COOKIE_POLICY => 'cookies',
            self::TYPE_BLOG => 'post',
            self::TYPE_BLOG_CATEGORY => 'blog-category',
            self::TYPE_BLOG_LIST => 'blog',
            self::TYPE_CUSTOM => 'page',
        ];
        if (isset($map[$type])) {
            return $map[$type];
        }
        return str_replace('_', '-', $type);
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
        $type = $this->getData(self::schema_fields_TYPE);
        return in_array($type, [self::TYPE_BLOG, self::TYPE_BLOG_CATEGORY, self::TYPE_BLOG_LIST]);
    }
    
    /**
     * 获取页面类型名称
     */
    public function getTypeName(): string
    {
        $types = self::getPageTypes();
        return $types[$this->getData(self::schema_fields_TYPE)] ?? $this->getData(self::schema_fields_TYPE);
    }
    
    /**
     * 获取状态名称
     */
    public function getStatusName(): string
    {
        return $this->getData(self::schema_fields_STATUS) == self::STATUS_PUBLISHED ? __('已发布') : __('草稿');
    }

    public function isAiHtmlRenderMode(): bool
    {
        return \trim((string)$this->getData(self::schema_fields_RENDER_MODE)) === self::RENDER_MODE_AI_HTML;
    }

    /**
     * @return array<string, mixed> 含 blocks:list<array{block_id:string,type:string,html:string}>
     */
    public function getAiLayoutArray(): array
    {
        $raw = $this->getData(self::schema_fields_AI_LAYOUT);
        if ($raw === null || $raw === '') {
            return ['blocks' => []];
        }
        $decoded = \is_string($raw) ? \json_decode($raw, true) : null;

        return \is_array($decoded) ? $decoded : ['blocks' => []];
    }

    /**
     * @param array<string, mixed> $layout
     */
    public function setAiLayoutArray(array $layout): self
    {
        return $this->setData(self::schema_fields_AI_LAYOUT, \json_encode($layout, \JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAiPublishSnapshotsList(): array
    {
        $raw = $this->getData(self::schema_fields_AI_PUBLISH_SNAPSHOTS);
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = \is_string($raw) ? \json_decode($raw, true) : null;

        return \is_array($decoded) ? \array_values($decoded) : [];
    }

    /**
     * @param array<string, mixed> $sanitizedLayout 已消毒的 ai_layout 结构
     */
    public function appendAiPublishSnapshot(array $sanitizedLayout): self
    {
        $list = $this->getAiPublishSnapshotsList();
        $list[] = [
            'published_at' => \date('Y-m-d H:i:s'),
            'ai_layout' => $sanitizedLayout,
        ];
        if (\count($list) > self::AI_PUBLISH_SNAPSHOT_MAX) {
            $list = \array_slice($list, -self::AI_PUBLISH_SNAPSHOT_MAX);
        }

        return $this->setData(self::schema_fields_AI_PUBLISH_SNAPSHOTS, \json_encode($list, \JSON_UNESCAPED_UNICODE));
    }

    /**
     * 已发布页读最后一次快照；无快照时回退编辑态 ai_layout
     *
     * @return array<string, mixed>
     */
    public function resolveAiLayoutForFrontend(bool $useDraftInsteadOfSnapshot = false): array
    {
        if ($useDraftInsteadOfSnapshot) {
            return $this->getAiLayoutArray();
        }
        if ((int)$this->getData(self::schema_fields_STATUS) !== self::STATUS_PUBLISHED) {
            return $this->getAiLayoutArray();
        }
        $snapshots = $this->getAiPublishSnapshotsList();
        if ($snapshots === []) {
            return $this->getAiLayoutArray();
        }
        $last = $snapshots[\array_key_last($snapshots)];
        $layout = $last['ai_layout'] ?? null;

        return \is_array($layout) ? $layout : $this->getAiLayoutArray();
    }
    
    /**
     * 获取选中的语言列表
     */
    public function getSelectedLocales(): array
    {
        $locales = $this->getData(self::schema_fields_LOCALES);
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
        return $this->setData(self::schema_fields_LOCALES, json_encode($locales));
    }
    
    /**
     * 获取父页面
     */
    public function getParentPage(): ?Page
    {
        $parentId = $this->getData(self::schema_fields_PARENT_ID);
        if (!$parentId) {
            return null;
        }
        
        $parent = clone $this;
        $parent->clear()->load($parentId);
        return $parent->getId() ? $parent : null;
    }

    /**
     * 获取所属站点根页面（主页，parent_id=0 的顶级页）
     * 子页面主题继承自此根页面，不可单独切换
     */
    public function getRootPage(): ?Page
    {
        $current = $this;
        while (true) {
            $parent = $current->getParentPage();
            if ($parent === null) {
                return $current->getId() ? $current : null;
            }
            $current = $parent;
        }
    }
    
    /**
     * 获取子页面列表
     */
    public function getChildPages(): array
    {
        $children = clone $this;
        return $children->clear()
            ->where(self::schema_fields_PARENT_ID, $this->getId())
            ->select()
            ->fetch()
            ->getItems();
    }

    /**
     * 获取当前页面的子页面列表（不区分发布状态），用于 nav/链接 给 AI 参考
     * 返回格式与 getNavigationPages 一致：title, handle, url, type, page_id
     */
    public function getChildPagesForNav(int $limit = 50): array
    {
        $websiteId = (int)$this->getData(self::schema_fields_WEBSITE_ID);
        $parentId = (int)$this->getId();
        if ($parentId <= 0) {
            return [];
        }
        $pages = clone $this;
        $items = $pages->clear()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->where(self::schema_fields_PARENT_ID, $parentId)
            ->order(self::schema_fields_TYPE, 'ASC')
            ->limit($limit)
            ->select()
            ->fetch()
            ->getItems();
        $result = [];
        foreach ($items as $item) {
            $handle = $item->getData(self::schema_fields_HANDLE);
            $h = $handle === null || $handle === '' ? '' : (string)$handle;
            $type = $item->getData(self::schema_fields_TYPE);
            $result[] = [
                'title' => $item->getData(self::schema_fields_TITLE) ?: $item->getData(self::schema_fields_NAME),
                'handle' => $h,
                'url' => $type === self::TYPE_HOME ? '/' : ($h === '' ? '/' : '/' . $h),
                'type' => $type,
                'page_id' => $item->getId(),
                'status' => (int)$item->getData(self::schema_fields_STATUS),
            ];
        }
        return $result;
    }

    /**
     * 获取当前页面的同级子页面列表（同一 parent_id 下，含自身），用于 nav/链接 给 AI 参考
     * 若父页是首页，会在列表前追加父页（首页），保证导航有「首页」项
     */
    public function getSiblingPagesForNav(int $limit = 50): array
    {
        $parentId = (int)$this->getData(self::schema_fields_PARENT_ID);
        if ($parentId <= 0) {
            return [];
        }
        $websiteId = (int)$this->getData(self::schema_fields_WEBSITE_ID);
        $pages = clone $this;
        $items = $pages->clear()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->where(self::schema_fields_PARENT_ID, $parentId)
            ->order(self::schema_fields_TYPE, 'ASC')
            ->limit($limit)
            ->select()
            ->fetch()
            ->getItems();
        $result = [];
        foreach ($items as $item) {
            $handle = $item->getData(self::schema_fields_HANDLE);
            $h = $handle === null || $handle === '' ? '' : (string)$handle;
            $type = $item->getData(self::schema_fields_TYPE);
            $result[] = [
                'title' => $item->getData(self::schema_fields_TITLE) ?: $item->getData(self::schema_fields_NAME),
                'handle' => $h,
                'url' => $type === self::TYPE_HOME ? '/' : ($h === '' ? '/' : '/' . $h),
                'type' => $type,
                'page_id' => $item->getId(),
                'status' => (int)$item->getData(self::schema_fields_STATUS),
            ];
        }
        // 父页是首页时，把首页插到最前；首页链接直接用域名，不拼 handle
        $parent = clone $this;
        $parent->clear()->load($parentId);
        if ($parent->getId() && $parent->getData(self::schema_fields_TYPE) === self::TYPE_HOME) {
            array_unshift($result, [
                'title' => $parent->getData(self::schema_fields_TITLE) ?: $parent->getData(self::schema_fields_NAME),
                'handle' => $parent->getData(self::schema_fields_HANDLE) ?? '',
                'url' => '/',
                'type' => self::TYPE_HOME,
                'page_id' => $parent->getId(),
                'status' => (int)$parent->getData(self::schema_fields_STATUS),
            ]);
        }
        return $result;
    }

    /**
     * 获取当前页面下所有后代页面的 ID（递归：子页面 + 子页面的子页面 + …）
     * 用于主页换主题时同步更新该站点下所有子页面的 theme
     */
    public function getDescendantIds(): array
    {
        $ids = [];
        foreach ($this->getChildPages() as $child) {
            $id = $child->getId();
            if ($id) {
                $ids[] = $id;
                $ids = array_merge($ids, $child->getDescendantIds());
            }
        }
        return array_values(array_unique($ids));
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
        $websiteId = (int)$this->getData(self::schema_fields_WEBSITE_ID);
        
        // 默认排除博客文章详情页
        if (empty($excludeTypes)) {
            $excludeTypes = [self::TYPE_BLOG, self::TYPE_BLOG_CATEGORY];
        }
        
        $pages = clone $this;
        $query = $pages->clear()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->where(self::schema_fields_STATUS, self::STATUS_PUBLISHED)
            ->where(self::schema_fields_PARENT_ID, 0); // 只获取顶级页面
        
        // 排除指定类型
        if (!empty($excludeTypes)) {
            $query->where(self::schema_fields_TYPE, $excludeTypes, 'NOT IN');
        }
        
        $items = $query->order(self::schema_fields_TYPE, 'ASC') // 首页排前面
            ->limit($limit)
            ->select()
            ->fetch()
            ->getItems();
        
        $result = [];
        foreach ($items as $item) {
            $handle = $item->getData(self::schema_fields_HANDLE);
            $type = $item->getData(self::schema_fields_TYPE);
            $result[] = [
                'title' => $item->getData(self::schema_fields_TITLE) ?: $item->getData(self::schema_fields_NAME),
                'handle' => $handle,
                'url' => $type === self::TYPE_HOME ? '/' : ('/' . ($handle ?? '')), // 首页直接用域名，不拼 handle
                'type' => $type,
                'page_id' => $item->getId(),
            ];
        }
        
        return $result;
    }

    /**
     * 页头导航允许的页面类型（关于我们、博客、联系我们、条款、隐私政策等）
     * 用于 header 菜单，只展示这些类型中当前站点已存在的页面。
     */
    public static function getHeaderMenuTypes(): array
    {
        return [
            self::TYPE_HOME,
            self::TYPE_ABOUT,
            self::TYPE_BLOG_LIST,
            self::TYPE_CONTACT,
            self::TYPE_TERMS_OF_SERVICE,
            self::TYPE_PRIVACY_POLICY,
        ];
    }

    /**
     * 获取页头导航页面列表（仅当前站点下已存在的指定类型页面，按固定顺序）
     *
     * @param int $limit 数量上限
     * @return array 页面列表，包含 title, handle, url, type
     */
    public function getHeaderNavigationPages(int $limit = 10): array
    {
        $websiteId = (int)$this->getData(self::schema_fields_WEBSITE_ID);
        $allowedTypes = self::getHeaderMenuTypes();

        $pages = clone $this;
        $items = $pages->clear()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->where(self::schema_fields_STATUS, self::STATUS_PUBLISHED)
            // ->where(self::schema_fields_PARENT_ID, 0)
            ->where(self::schema_fields_TYPE, $allowedTypes, 'IN')
            ->order(self::schema_fields_TYPE, 'ASC')
            ->limit($limit * 2)
            ->select()
            ->fetch()
            ->getItems();
        $byType = [];
        foreach ($items as $item) {
            $type = $item->getData(self::schema_fields_TYPE);
            $handle = $item->getData(self::schema_fields_HANDLE);
            $byType[$type] = [
                'title' => $item->getData(self::schema_fields_TITLE) ?: $item->getData(self::schema_fields_NAME),
                'handle' => $handle,
                'url' => $type === self::TYPE_HOME ? '/' : ('/' . ($handle ?: '')),
                'type' => $type,
                'page_id' => $item->getId(),
            ];
        }

        $result = [];
        foreach ($allowedTypes as $type) {
            if (isset($byType[$type]) && count($result) < $limit) {
                $result[] = $byType[$type];
            }
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
            $websiteId = (int)\Weline\Websites\Data\WebsiteData::getWebsiteId();
            $result = w_query('blog', 'getPostList', [
                'site_id' => $websiteId,
                'category_id' => null,
                'page' => 1,
                'page_size' => $limit,
            ]);
            return $result['items'] ?? [];
        } catch (\Throwable $e) {
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
        $websiteId = $websiteId ?? (int)$this->getData(self::schema_fields_WEBSITE_ID);
        
        $homePage = clone $this;
        $homePage->clear()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->where(self::schema_fields_TYPE, self::TYPE_HOME);
        
        // 仅在前台渲染时检查发布状态，后台编辑时允许访问草稿状态的首页
        if ($publishedOnly) {
            $homePage->where(self::schema_fields_STATUS, self::STATUS_PUBLISHED);
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
            'style' => $homePage->getData(self::schema_fields_STYLE) ?: 'default',
            'style_setting' => $homePage->getStyleSetting(),
            'layout_config' => $homePage->getLayoutConfig(),
            'logo' => $homePage->getData(self::schema_fields_LOGO),
            'icon' => $homePage->getData(self::schema_fields_ICON),
            'ga4_id' => $homePage->getData(self::schema_fields_GA4_ID),
            'gtm_id' => $homePage->getData(self::schema_fields_GTM_ID),
            'fb_pixel_id' => $homePage->getData(self::schema_fields_FB_PIXEL_ID),
            'header_custom_code' => $homePage->getData(self::schema_fields_HEADER_CUSTOM_CODE),
            'footer_custom_code' => $homePage->getData(self::schema_fields_FOOTER_CUSTOM_CODE),
        ];
    }
    
    /**
     * 获取布局配置
     */
    public function getLayoutConfig(): array
    {
        $config = $this->getData(self::schema_fields_LAYOUT_CONFIG);
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
        $pageType = $this->getData(self::schema_fields_TYPE);
        $isHomePage = ($pageType === self::TYPE_HOME);
        
        if ($isHomePage || !$syncHeaderFooterToHome) {
            // 首页直接保存完整配置
            $this->setData(self::schema_fields_LAYOUT_CONFIG, json_encode($layoutConfig));
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
                $homePage->setData(self::schema_fields_LAYOUT_CONFIG, json_encode($homeLayout));
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
        
        $this->setData(self::schema_fields_LAYOUT_CONFIG, json_encode($currentLayout));
        
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
        $pageType = $this->getData(self::schema_fields_TYPE);
        
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
            $websiteId = (int)\Weline\Websites\Data\WebsiteData::getWebsiteId();
            return w_query('blog', 'getCategoryList', ['site_id' => $websiteId]);
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
        $layoutPageId = $this->getData(self::schema_fields_LAYOUT_PAGE_ID);
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
        return $this->setData(self::schema_fields_LAYOUT_PAGE_ID, $layoutPageId);
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
        $websiteId = $this->getData(self::schema_fields_WEBSITE_ID);
        
        $pages = clone $this;
        $query = $pages->clear();
        
        // 只获取同站点的已发布页面
        if ($websiteId) {
            $query->where(self::schema_fields_WEBSITE_ID, $websiteId);
        }
        $query->where(self::schema_fields_STATUS, self::STATUS_PUBLISHED);
        
        // 排除当前页面
        if ($excludePageId) {
            $query->where(self::schema_fields_ID, $excludePageId, '!=');
        }
        
        $items = $query->order(self::schema_fields_TYPE, 'ASC')
            ->order(self::schema_fields_NAME, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
        
        $result = [];
        foreach ($items as $item) {
            $result[] = [
                'page_id' => $item->getId(),
                'name' => $item->getData(self::schema_fields_NAME),
                'title' => $item->getData(self::schema_fields_TITLE),
                'handle' => $item->getData(self::schema_fields_HANDLE),
                'type' => $item->getData(self::schema_fields_TYPE),
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
        $setting = $this->getData(self::schema_fields_STYLE_SETTING);
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
        return $this->setData(self::schema_fields_STYLE_SETTING, json_encode($setting));
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
     * 保存后钩子 - 自动提交已发布页面的 URL 到 SEO 模块
     */
    public function save_after(): void
    {
        parent::save_after();
        
        // 仅当页面为已发布状态时，提交 URL 到 SEO 模块
        $status = (int)$this->getData(self::schema_fields_STATUS);
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
                w_log_error('PageBuilder SEO URL Submit Error: ' . $e->getMessage());
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
        $websiteId = (int)$this->getData(self::schema_fields_WEBSITE_ID);
        $handle = $this->getData(self::schema_fields_HANDLE);
        $pageType = $this->getData(self::schema_fields_TYPE);
        
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
            
            $baseUrl = \GuoLaiRen\PageBuilder\Helper\PageHelper::normalizeUrlDefaultPort(rtrim($website->getUrl(), '/'));
            
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

