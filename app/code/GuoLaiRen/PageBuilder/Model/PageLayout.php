<?php
declare(strict_types=1);
/*
 * GuoLaiRen PageBuilder Module
 * 页面布局模型 - 用于存储可视化页面构建器的布局配置
 * 
 * 设计说明：
 * 1. 每个页面可以有一个布局配置，定义使用哪些组件以及它们的排列顺序
 * 2. 支持跨模板引用组件
 * 3. 支持组件的自定义配置覆盖
 * 4. 支持恢复到原始模板状态
 */
namespace GuoLaiRen\PageBuilder\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
#[Table(comment: '页面构建器-页面布局表')]
#[Index(name: 'idx_page_id', columns: ['page_id'], comment: '页面ID索引')]
#[Index(name: 'idx_is_active', columns: ['is_active'], comment: '状态索引')]
class PageLayout extends Model
{
    public const schema_table = 'guolairen_page_builder_page_layout';
    public const schema_primary_key = 'layout_id';
    // 字段定义
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '布局ID')]
    public const schema_fields_ID = 'layout_id';
    #[Col(type: 'int', nullable: false, comment: '关联的页面ID')]
    public const schema_fields_PAGE_ID = 'page_id';
    #[Col(type: 'text', nullable: true, comment: '布局配置JSON')]
    public const schema_fields_LAYOUT_CONFIG = 'layout_config';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Header组件代码')]
    public const schema_fields_HEADER_COMPONENT = 'header_component';
    #[Col(type: 'text', nullable: true, comment: 'Header组件配置JSON')]
    public const schema_fields_HEADER_CONFIG = 'header_config';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Footer组件代码')]
    public const schema_fields_FOOTER_COMPONENT = 'footer_component';
    #[Col(type: 'text', nullable: true, comment: 'Footer组件配置JSON')]
    public const schema_fields_FOOTER_CONFIG = 'footer_config';
    #[Col(type: 'text', nullable: true, comment: '内容组件列表JSON')]
    public const schema_fields_CONTENT_COMPONENTS = 'content_components';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 1, comment: '是否使用原始模板')]
    public const schema_fields_USE_ORIGINAL_TEMPLATE = 'use_original_template';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATE_TIME = 'create_time';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATE_TIME = 'update_time';
    
    /**
     * 布局配置示例结构：
     * {
     *   "version": "1.0",
     *   "header": {
     *     "component": "tpmst-header",          // 组件代码
     *     "config": { ... },                    // 自定义配置
     *     "from_template": "tpmst"              // 来源模板
     *   },
     *   "content": [
     *     {
     *       "id": "uuid-1",                     // 实例唯一ID
     *       "component": "tpmst-slider",        // 组件代码
     *       "config": { ... },                  // 自定义配置
     *       "from_template": "tpmst",           // 来源模板
     *       "sort_order": 10
     *     },
     *     {
     *       "id": "uuid-2",
     *       "component": "jion-landing-banner", // 跨模板引用
     *       "config": { ... },
     *       "from_template": "jion-landing",
     *       "sort_order": 20
     *     }
     *   ],
     *   "footer": {
     *     "component": "tpmst-footer",
     *     "config": { ... },
     *     "from_template": "tpmst"
     *   }
     * }
     */
    
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
     * 设置布局配置
     */
    public function setLayoutConfig(array $config): self
    {
        return $this->setData(self::schema_fields_LAYOUT_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * 获取Header组件配置
     */
    public function getHeaderConfig(): array
    {
        $config = $this->getData(self::schema_fields_HEADER_CONFIG);
        if (empty($config)) {
            return [];
        }
        return json_decode($config, true) ?: [];
    }
    
    /**
     * 设置Header组件配置
     */
    public function setHeaderConfig(array $config): self
    {
        return $this->setData(self::schema_fields_HEADER_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * 获取Footer组件配置
     */
    public function getFooterConfig(): array
    {
        $config = $this->getData(self::schema_fields_FOOTER_CONFIG);
        if (empty($config)) {
            return [];
        }
        return json_decode($config, true) ?: [];
    }
    
    /**
     * 设置Footer组件配置
     */
    public function setFooterConfig(array $config): self
    {
        return $this->setData(self::schema_fields_FOOTER_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * 获取内容组件列表
     */
    public function getContentComponents(): array
    {
        $components = $this->getData(self::schema_fields_CONTENT_COMPONENTS);
        if (empty($components)) {
            return [];
        }
        $decoded = json_decode($components, true) ?: [];
        
        // 按 sort_order 排序
        usort($decoded, function($a, $b) {
            return ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0);
        });
        
        return $decoded;
    }
    
    /**
     * 设置内容组件列表
     */
    public function setContentComponents(array $components): self
    {
        return $this->setData(self::schema_fields_CONTENT_COMPONENTS, json_encode($components, JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * 添加内容组件
     * 
     * @param string $componentCode 组件代码
     * @param array $config 自定义配置
     * @param string $fromTemplate 来源模板
     * @param int|null $sortOrder 排序
     * @return string 返回组件实例ID
     */
    public function addContentComponent(string $componentCode, array $config = [], string $fromTemplate = '', ?int $sortOrder = null): string
    {
        $components = $this->getContentComponents();
        
        // 生成唯一ID
        $instanceId = uniqid('comp_');
        
        // 计算排序
        if ($sortOrder === null) {
            $maxSort = 0;
            foreach ($components as $comp) {
                $maxSort = max($maxSort, $comp['sort_order'] ?? 0);
            }
            $sortOrder = $maxSort + 10;
        }
        
        $components[] = [
            'id' => $instanceId,
            'component' => $componentCode,
            'config' => $config,
            'from_template' => $fromTemplate,
            'sort_order' => $sortOrder,
        ];
        
        $this->setContentComponents($components);
        
        return $instanceId;
    }
    
    /**
     * 移除内容组件
     */
    public function removeContentComponent(string $instanceId): bool
    {
        $components = $this->getContentComponents();
        $found = false;
        
        $components = array_filter($components, function($comp) use ($instanceId, &$found) {
            if ($comp['id'] === $instanceId) {
                $found = true;
                return false;
            }
            return true;
        });
        
        if ($found) {
            $this->setContentComponents(array_values($components));
        }
        
        return $found;
    }
    
    /**
     * 更新内容组件配置
     */
    public function updateContentComponent(string $instanceId, array $config): bool
    {
        $components = $this->getContentComponents();
        $found = false;
        
        foreach ($components as &$comp) {
            if ($comp['id'] === $instanceId) {
                $comp['config'] = array_merge($comp['config'] ?? [], $config);
                $found = true;
                break;
            }
        }
        
        if ($found) {
            $this->setContentComponents($components);
        }
        
        return $found;
    }
    
    /**
     * 重新排序内容组件
     * 
     * @param array $order 组件ID数组，按新顺序排列
     */
    public function reorderContentComponents(array $order): void
    {
        $components = $this->getContentComponents();
        $componentMap = [];
        
        // 建立ID到组件的映射
        foreach ($components as $comp) {
            $componentMap[$comp['id']] = $comp;
        }
        
        // 按新顺序重建数组
        $newComponents = [];
        $sortOrder = 10;
        
        foreach ($order as $instanceId) {
            if (isset($componentMap[$instanceId])) {
                $comp = $componentMap[$instanceId];
                $comp['sort_order'] = $sortOrder;
                $newComponents[] = $comp;
                $sortOrder += 10;
            }
        }
        
        $this->setContentComponents($newComponents);
    }
    
    /**
     * 是否使用原始模板
     */
    public function isUsingOriginalTemplate(): bool
    {
        return (bool)$this->getData(self::schema_fields_USE_ORIGINAL_TEMPLATE);
    }
    
    /**
     * 切换到原始模板模式
     */
    public function useOriginalTemplate(bool $use = true): self
    {
        return $this->setData(self::schema_fields_USE_ORIGINAL_TEMPLATE, $use ? 1 : 0);
    }
    
    /**
     * 根据页面ID获取布局
     */
    public static function getByPageId(int $pageId): ?self
    {
        $layoutModel = \Weline\Framework\Manager\ObjectManager::getInstance(self::class);
        $layout = clone $layoutModel;
        $layout->clear()
            ->where(self::schema_fields_PAGE_ID, $pageId)
            ->where(self::schema_fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();
        
        return $layout->getId() ? $layout : null;
    }
    
    /**
     * 为页面创建或获取布局
     */
    public static function getOrCreateForPage(int $pageId): self
    {
        $existing = self::getByPageId($pageId);
        if ($existing) {
            return $existing;
        }
        
        // 创建新布局
        $layoutModel = \Weline\Framework\Manager\ObjectManager::getInstance(self::class);
        $layout = clone $layoutModel;
        $layout->clearData()
            ->setData(self::schema_fields_PAGE_ID, $pageId)
            ->setData(self::schema_fields_USE_ORIGINAL_TEMPLATE, 1) // 默认使用原始模板
            ->setData(self::schema_fields_IS_ACTIVE, 1)
            ->save(true);
        
        return $layout;
    }
    
    /**
     * 从页面的当前模板初始化布局
     * 
     * @param Page $page 页面对象
     * @return self
     */
    public function initializeFromPage(Page $page): self
    {
        $styleCode = $page->getData(Page::schema_fields_STYLE);
        if (empty($styleCode)) {
            return $this;
        }
        
        // 扫描模板的组件
        Component::scanAndRegister($styleCode);
        
        // 获取模板的系统组件（header/footer）
        $headerComponent = $styleCode . '-header';
        $footerComponent = $styleCode . '-footer';
        
        // 设置基础布局
        $this->setData(self::schema_fields_HEADER_COMPONENT, $headerComponent);
        $this->setData(self::schema_fields_FOOTER_COMPONENT, $footerComponent);
        
        // 获取页面类型
        $pageType = $page->getData(Page::schema_fields_TYPE);
        
        // 如果是博客类型页面，设置对应的默认组件
        if (in_array($pageType, [Page::TYPE_BLOG, Page::TYPE_BLOG_CATEGORY, Page::TYPE_BLOG_LIST])) {
            $blogComponent = null;
            
            // 根据页面类型选择对应的博客组件
            switch ($pageType) {
                case Page::TYPE_BLOG:
                    // 博客文章详情页 -> blog-detail
                    $blogComponent = 'blog-detail';
                    break;
                case Page::TYPE_BLOG_CATEGORY:
                    // 博客分类页 -> blog-category
                    $blogComponent = 'blog-category';
                    break;
                case Page::TYPE_BLOG_LIST:
                    // 博客列表页 -> blog-list
                    $blogComponent = 'blog-list';
                    break;
            }
            
            // 如果找到了对应的博客组件，只添加该组件
            if ($blogComponent) {
                // 检查组件是否存在
                $components = Component::getByStyleCode($styleCode, false, true);
                $componentExists = false;
                $actualComponentCode = null;
                
                foreach ($components['own'] as $component) {
                    $componentCode = $component->getData(Component::schema_fields_CODE);
                    if ($componentCode === $blogComponent || 
                        $componentCode === $styleCode . '-' . $blogComponent) {
                        $componentExists = true;
                        $actualComponentCode = $componentCode;
                        break;
                    }
                }
                
                // 如果组件不存在，尝试不带前缀的组件代码
                if (!$componentExists) {
                    foreach ($components['own'] as $component) {
                        $componentCode = $component->getData(Component::schema_fields_CODE);
                        // 去掉模板前缀后比较
                        $codeWithoutPrefix = strpos($componentCode, $styleCode . '-') === 0 
                            ? substr($componentCode, strlen($styleCode) + 1) 
                            : $componentCode;
                        if ($codeWithoutPrefix === $blogComponent) {
                            $componentExists = true;
                            $actualComponentCode = $componentCode;
                            break;
                        }
                    }
                }
                
                // 如果还是找不到，尝试直接使用组件代码（可能模板中没有前缀）
                if (!$componentExists) {
                    $actualComponentCode = $blogComponent;
                }
                
                if ($actualComponentCode) {
                    $contentComponents = [
                        [
                            'id' => uniqid('comp_'),
                            'component' => $actualComponentCode,
                            'config' => [],
                            'from_template' => $styleCode,
                            'sort_order' => 10,
                        ]
                    ];
                    $this->setContentComponents($contentComponents);
                    return $this;
                }
            }
        }
        
        // 非博客类型页面或博客组件不存在时，使用默认逻辑
        // 获取内容组件
        $components = Component::getByStyleCode($styleCode, false, true);
        $contentComponents = [];
        $sortOrder = 10;
        
        foreach ($components['own'] as $component) {
            $category = $component->getData(Component::schema_fields_CATEGORY);
            
            // 跳过系统组件
            if ($category === Component::CATEGORY_HEADER || $category === Component::CATEGORY_FOOTER) {
                continue;
            }
            
            $contentComponents[] = [
                'id' => uniqid('comp_'),
                'component' => $component->getData(Component::schema_fields_CODE),
                'config' => [],
                'from_template' => $styleCode,
                'sort_order' => $sortOrder,
            ];
            $sortOrder += 10;
        }
        
        $this->setContentComponents($contentComponents);
        
        return $this;
    }
    
    /**
     * 导出布局配置为完整的JSON结构
     * 
     * 注意：content 数组中的组件会被规范化为使用 'code' 字段
     * 以保持与前端模板（layout.phtml）和默认JSON配置的一致性
     */
    public function exportConfig(): array
    {
        // 规范化 content 组件，确保使用 'code' 字段名
        $contentComponents = $this->getContentComponents();
        $normalizedContent = [];
        
        foreach ($contentComponents as $comp) {
            $normalizedContent[] = [
                'code' => $comp['component'] ?? $comp['code'] ?? '',
                'enabled' => $comp['enabled'] ?? true,
                'config' => $comp['config'] ?? [],
                'instance_id' => $comp['id'] ?? $comp['instance_id'] ?? '',
                'from_template' => $comp['from_template'] ?? '',
                'sort_order' => $comp['sort_order'] ?? 0,
            ];
        }
        
        return [
            'version' => '1.0',
            'page_id' => $this->getData(self::schema_fields_PAGE_ID),
            'use_original_template' => $this->isUsingOriginalTemplate(),
            'header' => [
                'component' => $this->getData(self::schema_fields_HEADER_COMPONENT),
                'config' => $this->getHeaderConfig(),
            ],
            'content' => $normalizedContent,
            'footer' => [
                'component' => $this->getData(self::schema_fields_FOOTER_COMPONENT),
                'config' => $this->getFooterConfig(),
            ],
        ];
    }
    
    /**
     * 从JSON配置导入布局
     * 
     * 注意：content 数组会被转换为数据库存储格式（使用 'component' 字段）
     */
    public function importConfig(array $config): self
    {
        if (isset($config['use_original_template'])) {
            $this->useOriginalTemplate((bool)$config['use_original_template']);
        }
        
        if (isset($config['header'])) {
            $this->setData(self::schema_fields_HEADER_COMPONENT, $config['header']['component'] ?? '');
            $this->setHeaderConfig($config['header']['config'] ?? []);
        }
        
        if (isset($config['footer'])) {
            $this->setData(self::schema_fields_FOOTER_COMPONENT, $config['footer']['component'] ?? '');
            $this->setFooterConfig($config['footer']['config'] ?? []);
        }
        
        if (isset($config['content'])) {
            // 规范化为数据库存储格式（使用 'component' 字段）
            $normalizedContent = [];
            foreach ($config['content'] as $comp) {
                $normalizedContent[] = [
                    'id' => $comp['instance_id'] ?? $comp['id'] ?? uniqid('comp_'),
                    'component' => $comp['code'] ?? $comp['component'] ?? '',
                    'config' => $comp['config'] ?? [],
                    'from_template' => $comp['from_template'] ?? '',
                    'sort_order' => $comp['sort_order'] ?? 0,
                    'enabled' => $comp['enabled'] ?? true,
                ];
            }
            $this->setContentComponents($normalizedContent);
        }
        
        return $this;
    }
}
