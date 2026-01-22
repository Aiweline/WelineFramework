<?php

declare(strict_types=1);

/*
 * 组件服务类 - 负责组件相关的业务逻辑
 * 
 * 支持以下组件来源：
 * 1. 模板专属组件（style/{template}/components/）
 * 2. 共享组件（style/_shared/components/）
 * 3. 其他模板的兼容组件
 * 
 * 遵循单一职责原则(SRP)
 */

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Component;
use GuoLaiRen\PageBuilder\Model\Layout;
use Weline\Framework\Manager\ObjectManager;

class ComponentService
{
    private Component $componentModel;
    
    // 共享组件模板代码
    public const SHARED_STYLE_CODE = '_shared';
    
    public function __construct()
    {
        $this->componentModel = ObjectManager::getInstance(Component::class);
    }
    
    /**
     * 扫描并注册模板组件
     */
    public function scanAndRegister(string $styleCode): void
    {
        if (!empty($styleCode)) {
            Component::scanAndRegister($styleCode);
        }
    }
    
    /**
     * 扫描并注册所有模板的组件（包括共享组件）
     */
    public function scanAndRegisterAll(): array
    {
        $results = [];
        $styleDir = BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/style/';
        
        if (!is_dir($styleDir)) {
            return $results;
        }
        
        $dirs = scandir($styleDir);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..' || $dir === '_layouts') {
                continue;
            }
            
            $fullPath = $styleDir . $dir;
            if (is_dir($fullPath)) {
                $results[$dir] = Component::scanAndRegister($dir);
            }
        }
        
        return $results;
    }
    
    /**
     * 获取模板的组件列表（包含共享组件）
     * 
     * @param string $styleCode 模板代码
     * @param bool $includeCompatible 是否包含兼容组件
     * @return array ['own' => Component[], 'shared' => Component[], 'compatible' => [templateCode => Component[]]]
     */
    public function getComponentsByStyle(string $styleCode, bool $includeCompatible = true): array
    {
        $result = Component::getByStyleCode($styleCode, $includeCompatible, true);
        
        // 添加共享组件
        if ($styleCode !== self::SHARED_STYLE_CODE) {
            $sharedComponents = $this->getSharedComponents();
            $result['shared'] = $sharedComponents;
        }
        
        return $result;
    }
    
    /**
     * 获取共享组件列表
     * 
     * @return array Component[]
     */
    public function getSharedComponents(): array
    {
        $components = clone $this->componentModel;
        return $components->clear()
            ->where(Component::fields_STYLE_CODE, self::SHARED_STYLE_CODE)
            ->where(Component::fields_IS_ACTIVE, 1)
            ->order(Component::fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
    }
    
    /**
     * 获取用于可视化构建器的组件数据
     * 
     * @param string $styleCode 模板代码
     * @param string|null $layoutCode 布局代码（可选，用于过滤适合的组件）
     * @return array 组织好的组件数据
     */
    public function getComponentsForBuilder(string $styleCode, ?string $layoutCode = null): array
    {
        $allComponents = $this->getComponentsByStyle($styleCode, true);
        
        $result = [
            // 当前模板的组件（推荐）
            'recommended' => [
                'label' => '推荐组件',
                'description' => '当前模板专属组件，样式最契合',
                'components' => $this->toArrayBatch($allComponents['own'] ?? []),
            ],
            // 共享组件（通用）
            'shared' => [
                'label' => '通用组件',
                'description' => '跨模板通用组件',
                'components' => $this->toArrayBatch($allComponents['shared'] ?? []),
            ],
            // 其他模板的兼容组件
            'other_templates' => [],
        ];
        
        // 整理其他模板的组件
        if (!empty($allComponents['compatible'])) {
            foreach ($allComponents['compatible'] as $templateCode => $components) {
                if ($templateCode === self::SHARED_STYLE_CODE) {
                    continue; // 跳过共享组件（已单独处理）
                }
                
                $result['other_templates'][$templateCode] = [
                    'label' => $this->getTemplateName($templateCode),
                    'components' => $this->toArrayBatch($components),
                ];
            }
        }
        
        // 如果指定了布局，按区域分组
        if ($layoutCode) {
            $result['by_region'] = $this->groupComponentsByRegion($allComponents, $layoutCode);
        }
        
        // 按分类分组
        $result['by_category'] = $this->groupComponentsByCategory($allComponents);
        
        return $result;
    }
    
    /**
     * 按区域分组组件
     */
    private function groupComponentsByRegion(array $allComponents, string $layoutCode): array
    {
        $regions = Layout::getLayoutRegions($layoutCode);
        $grouped = [];
        
        foreach ($regions as $regionCode => $region) {
            $grouped[$regionCode] = [
                'label' => $region['name'] ?? ucfirst($regionCode),
                'components' => [],
            ];
        }
        
        // 合并所有组件
        $all = array_merge(
            $allComponents['own'] ?? [],
            $allComponents['shared'] ?? []
        );
        
        foreach ($all as $component) {
            $category = $component->getData(Component::fields_CATEGORY);
            $regionCode = $this->categoryToRegion($category);
            
            if (isset($grouped[$regionCode])) {
                $grouped[$regionCode]['components'][] = $this->toArray($component);
            }
        }
        
        return $grouped;
    }
    
    /**
     * 按分类分组组件
     */
    private function groupComponentsByCategory(array $allComponents): array
    {
        $categories = Component::getCategories();
        $grouped = [];
        
        foreach ($categories as $code => $label) {
            $grouped[$code] = [
                'label' => $label,
                'components' => [],
            ];
        }
        
        // 合并所有组件
        $all = array_merge(
            $allComponents['own'] ?? [],
            $allComponents['shared'] ?? []
        );
        
        foreach ($all as $component) {
            $category = $component->getData(Component::fields_CATEGORY);
            if (isset($grouped[$category])) {
                $grouped[$category]['components'][] = $this->toArray($component);
            }
        }
        
        return $grouped;
    }
    
    /**
     * 分类映射到区域
     */
    private function categoryToRegion(string $category): string
    {
        return match($category) {
            Component::CATEGORY_HEADER => Layout::REGION_HEADER,
            Component::CATEGORY_FOOTER => Layout::REGION_FOOTER,
            default => Layout::REGION_CONTENT,
        };
    }
    
    /**
     * 根据组件代码获取组件
     */
    public function getByCode(string $componentCode): ?Component
    {
        $component = clone $this->componentModel;
        $component->clear()
            ->where(Component::fields_CODE, $componentCode)
            ->find()
            ->fetch();
        
        return $component->getId() ? $component : null;
    }
    
    /**
     * 渲染组件预览
     */
    public function renderPreview(string $componentCode, array $config = []): string
    {
        $component = $this->getByCode($componentCode);
        if (!$component) {
            throw new \Exception('组件不存在: ' . $componentCode);
        }
        
        return $component->render($config);
    }
    
    /**
     * 将组件模型转换为数组格式
     */
    public function toArray(Component $component): array
    {
        $styleCode = $component->getData(Component::fields_STYLE_CODE);
        $thumbnail = $component->getData(Component::fields_THUMBNAIL);
        $category = $component->getData(Component::fields_CATEGORY);
        
        // 构建缩略图完整路径
        $thumbnailUrl = '';
        if ($thumbnail) {
            $basePath = '/app/code/GuoLaiRen/PageBuilder/view/templates/style/' . $styleCode . '/';
            $thumbnailUrl = $basePath . $thumbnail;
        }
        
        // 从 config_schema 中提取 region（如果有的话）
        $configSchema = $component->getConfigSchema();
        $region = $configSchema['region'] ?? $this->categoryToRegion($category);
        
        return [
            'id' => $component->getId(),
            'code' => $component->getData(Component::fields_CODE),
            'name' => $component->getData(Component::fields_NAME),
            'description' => $component->getData(Component::fields_DESCRIPTION),
            'style_code' => $styleCode,
            'category' => $category,
            'region' => $region,  // 添加 region 字段
            'type' => $component->getData(Component::fields_TYPE),
            'thumbnail' => $thumbnail,
            'thumbnail_url' => $thumbnailUrl,
            'config_schema' => $configSchema,
            'default_config' => $component->getDefaultConfig(),
            'compatible_styles' => $component->getCompatibleStyles(),
            'is_system' => (bool)$component->getData(Component::fields_IS_SYSTEM),
            'is_shared' => $styleCode === self::SHARED_STYLE_CODE,
            'sort_order' => (int)$component->getData(Component::fields_SORT_ORDER),
        ];
    }
    
    /**
     * 批量转换组件为数组
     */
    public function toArrayBatch(array $components): array
    {
        return array_map(fn($c) => $this->toArray($c), $components);
    }
    
    /**
     * 获取模板名称
     */
    private function getTemplateName(string $styleCode): string
    {
        // 尝试从 readme.md 或 component.json 获取名称
        $basePath = BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/style/' . $styleCode . '/';
        
        // 尝试从 component.json 获取
        $componentJson = $basePath . 'components/component.json';
        if (file_exists($componentJson)) {
            $content = file_get_contents($componentJson);
            $config = json_decode($content, true);
            if (!empty($config['name'])) {
                return $config['name'];
            }
        }
        
        // 格式化代码为名称
        return ucwords(str_replace(['-', '_'], ' ', $styleCode));
    }
    
    /**
     * 获取页面已保存的组件配置
     * 
     * @param int $pageId 页面ID
     * @return array 组件配置
     */
    public function getPageComponents(int $pageId): array
    {
        // TODO: 从 PageLayout 模型获取页面的组件配置
        return [];
    }
    
    /**
     * 保存页面的组件配置
     * 
     * @param int $pageId 页面ID
     * @param array $components 组件配置
     * @return bool
     */
    public function savePageComponents(int $pageId, array $components): bool
    {
        // TODO: 保存到 PageLayout 模型
        return true;
    }
}
