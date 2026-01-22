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

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class PageLayout extends Model
{
    public const table = 'guolairen_page_builder_page_layout';
    
    // 字段定义
    public const fields_ID = 'layout_id';
    public const fields_PAGE_ID = 'page_id';               // 关联的页面ID
    public const fields_LAYOUT_CONFIG = 'layout_config';   // 布局配置（JSON）
    public const fields_HEADER_COMPONENT = 'header_component';    // Header组件代码
    public const fields_HEADER_CONFIG = 'header_config';          // Header组件配置（JSON）
    public const fields_FOOTER_COMPONENT = 'footer_component';    // Footer组件代码
    public const fields_FOOTER_CONFIG = 'footer_config';          // Footer组件配置（JSON）
    public const fields_CONTENT_COMPONENTS = 'content_components'; // 内容组件列表（JSON）
    public const fields_USE_ORIGINAL_TEMPLATE = 'use_original_template'; // 是否使用原始模板
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_CREATE_TIME = 'create_time';
    public const fields_UPDATE_TIME = 'update_time';
    
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
        $config = $this->getData(self::fields_LAYOUT_CONFIG);
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
        return $this->setData(self::fields_LAYOUT_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * 获取Header组件配置
     */
    public function getHeaderConfig(): array
    {
        $config = $this->getData(self::fields_HEADER_CONFIG);
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
        return $this->setData(self::fields_HEADER_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * 获取Footer组件配置
     */
    public function getFooterConfig(): array
    {
        $config = $this->getData(self::fields_FOOTER_CONFIG);
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
        return $this->setData(self::fields_FOOTER_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * 获取内容组件列表
     */
    public function getContentComponents(): array
    {
        $components = $this->getData(self::fields_CONTENT_COMPONENTS);
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
        return $this->setData(self::fields_CONTENT_COMPONENTS, json_encode($components, JSON_UNESCAPED_UNICODE));
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
        return (bool)$this->getData(self::fields_USE_ORIGINAL_TEMPLATE);
    }
    
    /**
     * 切换到原始模板模式
     */
    public function useOriginalTemplate(bool $use = true): self
    {
        return $this->setData(self::fields_USE_ORIGINAL_TEMPLATE, $use ? 1 : 0);
    }
    
    /**
     * 根据页面ID获取布局
     */
    public static function getByPageId(int $pageId): ?self
    {
        $layoutModel = \Weline\Framework\Manager\ObjectManager::getInstance(self::class);
        $layout = clone $layoutModel;
        $layout->clear()
            ->where(self::fields_PAGE_ID, $pageId)
            ->where(self::fields_IS_ACTIVE, 1)
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
            ->setData(self::fields_PAGE_ID, $pageId)
            ->setData(self::fields_USE_ORIGINAL_TEMPLATE, 1) // 默认使用原始模板
            ->setData(self::fields_IS_ACTIVE, 1)
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
        $styleCode = $page->getData(Page::fields_STYLE);
        if (empty($styleCode)) {
            return $this;
        }
        
        // 扫描模板的组件
        Component::scanAndRegister($styleCode);
        
        // 获取模板的系统组件（header/footer）
        $headerComponent = $styleCode . '-header';
        $footerComponent = $styleCode . '-footer';
        
        // 设置基础布局
        $this->setData(self::fields_HEADER_COMPONENT, $headerComponent);
        $this->setData(self::fields_FOOTER_COMPONENT, $footerComponent);
        
        // 获取内容组件
        $components = Component::getByStyleCode($styleCode, false, true);
        $contentComponents = [];
        $sortOrder = 10;
        
        foreach ($components['own'] as $component) {
            $category = $component->getData(Component::fields_CATEGORY);
            
            // 跳过系统组件
            if ($category === Component::CATEGORY_HEADER || $category === Component::CATEGORY_FOOTER) {
                continue;
            }
            
            $contentComponents[] = [
                'id' => uniqid('comp_'),
                'component' => $component->getData(Component::fields_CODE),
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
     */
    public function exportConfig(): array
    {
        return [
            'version' => '1.0',
            'page_id' => $this->getData(self::fields_PAGE_ID),
            'use_original_template' => $this->isUsingOriginalTemplate(),
            'header' => [
                'component' => $this->getData(self::fields_HEADER_COMPONENT),
                'config' => $this->getHeaderConfig(),
            ],
            'content' => $this->getContentComponents(),
            'footer' => [
                'component' => $this->getData(self::fields_FOOTER_COMPONENT),
                'config' => $this->getFooterConfig(),
            ],
        ];
    }
    
    /**
     * 从JSON配置导入布局
     */
    public function importConfig(array $config): self
    {
        if (isset($config['use_original_template'])) {
            $this->useOriginalTemplate((bool)$config['use_original_template']);
        }
        
        if (isset($config['header'])) {
            $this->setData(self::fields_HEADER_COMPONENT, $config['header']['component'] ?? '');
            $this->setHeaderConfig($config['header']['config'] ?? []);
        }
        
        if (isset($config['footer'])) {
            $this->setData(self::fields_FOOTER_COMPONENT, $config['footer']['component'] ?? '');
            $this->setFooterConfig($config['footer']['config'] ?? []);
        }
        
        if (isset($config['content'])) {
            $this->setContentComponents($config['content']);
        }
        
        return $this;
    }

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }
        
        $setup->createTable('页面构建器-页面布局表')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '布局ID'
            )
            ->addColumn(
                self::fields_PAGE_ID,
                TableInterface::column_type_INTEGER,
                0,
                'not null',
                '关联页面ID'
            )
            ->addColumn(
                self::fields_LAYOUT_CONFIG,
                TableInterface::column_type_TEXT,
                0,
                '',
                '布局配置(JSON)'
            )
            ->addColumn(
                self::fields_HEADER_COMPONENT,
                TableInterface::column_type_VARCHAR,
                100,
                '',
                'Header组件代码'
            )
            ->addColumn(
                self::fields_HEADER_CONFIG,
                TableInterface::column_type_TEXT,
                0,
                '',
                'Header组件配置(JSON)'
            )
            ->addColumn(
                self::fields_FOOTER_COMPONENT,
                TableInterface::column_type_VARCHAR,
                100,
                '',
                'Footer组件代码'
            )
            ->addColumn(
                self::fields_FOOTER_CONFIG,
                TableInterface::column_type_TEXT,
                0,
                '',
                'Footer组件配置(JSON)'
            )
            ->addColumn(
                self::fields_CONTENT_COMPONENTS,
                TableInterface::column_type_TEXT,
                0,
                '',
                '内容组件列表(JSON)'
            )
            ->addColumn(
                self::fields_USE_ORIGINAL_TEMPLATE,
                TableInterface::column_type_SMALLINT,
                1,
                'not null default 1',
                '是否使用原始模板:0自定义布局,1原始模板'
            )
            ->addColumn(
                self::fields_IS_ACTIVE,
                TableInterface::column_type_SMALLINT,
                1,
                'not null default 1',
                '是否启用'
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
            ->addIndex(TableInterface::index_type_UNIQUE, 'uk_page_id', [self::fields_PAGE_ID], '页面ID唯一索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_is_active', [self::fields_IS_ACTIVE], '状态索引')
            ->create();
    }

    /**
     * 升级表结构
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 预留升级逻辑
    }

    /**
     * 设置表结构
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }
}
