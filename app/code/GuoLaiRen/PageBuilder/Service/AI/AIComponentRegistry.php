<?php

declare(strict_types=1);

/*
 * AI 组件注册表
 * 
 * 负责管理 AI 组件的注册和查找：
 * 1. 维护 AI 组件的映射关系
 * 2. 确保实体文件存在且最新
 * 3. 提供组件路径解析
 */

namespace GuoLaiRen\PageBuilder\Service\AI;

use GuoLaiRen\PageBuilder\Model\Component;
use Weline\Framework\Manager\ObjectManager;

class AIComponentRegistry
{
    private EntityFileManager $entityFileManager;
    
    // 组件缓存
    private static array $componentCache = [];
    
    public function __construct()
    {
        $this->entityFileManager = ObjectManager::getInstance(EntityFileManager::class);
    }
    
    /**
     * 注册 AI 组件到系统
     * 
     * @param Component $component 组件模型
     * @return void
     */
    public function register(Component $component): void
    {
        // 确保是 AI 组件
        if (!$component->isAIGenerated()) {
            throw new \Exception('只能注册 AI 生成的组件');
        }
        
        // 确保实体文件存在
        $this->entityFileManager->ensureEntityFile($component);
        
        // 更新 component.json
        $this->entityFileManager->updateComponentJson();
        
        // 更新缓存
        $code = $component->getData(Component::schema_fields_CODE);
        self::$componentCache[$code] = $component;
    }
    
    /**
     * 确保实体文件存在且最新
     * 
     * @param string $componentCode 组件代码
     * @return string 实体文件路径
     */
    public function ensureEntityFile(string $componentCode): string
    {
        $component = $this->getComponent($componentCode);
        
        if (!$component) {
            throw new \Exception('AI 组件不存在: ' . $componentCode);
        }
        
        return $this->entityFileManager->ensureEntityFile($component);
    }
    
    /**
     * 获取组件的实际渲染路径
     * 
     * @param string $componentCode 组件代码
     * @return string 模板路径
     */
    public function resolveRenderPath(string $componentCode): string
    {
        $component = $this->getComponent($componentCode);
        
        if (!$component) {
            return '';
        }
        
        // 确保实体文件存在
        $this->entityFileManager->ensureEntityFile($component);
        
        return $this->entityFileManager->getTemplatePath($component);
    }
    
    /**
     * 检查是否是 AI 组件
     * 
     * @param string $componentCode 组件代码
     * @return bool
     */
    public function isAIComponent(string $componentCode): bool
    {
        $component = $this->getComponent($componentCode);
        return $component !== null && $component->isAIGenerated();
    }
    
    /**
     * 获取 AI 组件
     * 
     * @param string $componentCode 组件代码
     * @return Component|null
     */
    public function getComponent(string $componentCode): ?Component
    {
        // 检查缓存
        if (isset(self::$componentCache[$componentCode])) {
            return self::$componentCache[$componentCode];
        }
        
        // 从数据库查找
        $componentModel = ObjectManager::getInstance(Component::class);
        $component = clone $componentModel;
        $component->clear()
            ->where(Component::schema_fields_CODE, $componentCode)
            ->where(Component::schema_fields_IS_AI_GENERATED, 1)
            ->find()
            ->fetch();
        
        if ($component->getId()) {
            self::$componentCache[$componentCode] = $component;
            return $component;
        }
        
        return null;
    }
    
    /**
     * 获取所有 AI 组件
     * 
     * @param bool $activeOnly 是否只返回启用的组件
     * @return array Component[]
     */
    public function getAllComponents(bool $activeOnly = true): array
    {
        $componentModel = ObjectManager::getInstance(Component::class);
        $components = clone $componentModel;
        $query = $components->clear()
            ->where(Component::schema_fields_IS_AI_GENERATED, 1);
        
        if ($activeOnly) {
            $query->where(Component::schema_fields_IS_ACTIVE, 1);
        }
        
        $query->order(Component::schema_fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetch();
        
        return $components->getItems();
    }
    
    /**
     * 按分类获取 AI 组件
     * 
     * @param string $category 分类
     * @param bool $activeOnly 是否只返回启用的组件
     * @return array Component[]
     */
    public function getComponentsByCategory(string $category, bool $activeOnly = true): array
    {
        $componentModel = ObjectManager::getInstance(Component::class);
        $components = clone $componentModel;
        $query = $components->clear()
            ->where(Component::schema_fields_IS_AI_GENERATED, 1)
            ->where(Component::schema_fields_CATEGORY, $category);
        
        if ($activeOnly) {
            $query->where(Component::schema_fields_IS_ACTIVE, 1);
        }
        
        $query->order(Component::schema_fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetch();
        
        return $components->getItems();
    }
    
    /**
     * 获取 AI 组件的完整文件路径
     * 
     * @param string $componentCode 组件代码
     * @return string|null 文件路径
     */
    public function getComponentFilePath(string $componentCode): ?string
    {
        $component = $this->getComponent($componentCode);
        
        if (!$component) {
            return null;
        }
        
        return $this->entityFileManager->getEntityFullPath($component);
    }
    
    /**
     * 同步所有 AI 组件的实体文件
     * 
     * @return array ['synced' => int, 'errors' => array]
     */
    public function syncAll(): array
    {
        return $this->entityFileManager->syncAll();
    }
    
    /**
     * 清理无效的 AI 组件实体文件
     * 
     * @return int 删除的文件数量
     */
    public function cleanup(): int
    {
        return $this->entityFileManager->cleanup();
    }
    
    /**
     * 刷新组件缓存
     */
    public function clearCache(): void
    {
        self::$componentCache = [];
    }
    
    /**
     * 获取 AI 组件的统计信息
     * 
     * @return array
     */
    public function getStatistics(): array
    {
        $componentModel = ObjectManager::getInstance(Component::class);
        
        // 获取总数
        $total = clone $componentModel;
        $total->clear()
            ->where(Component::schema_fields_IS_AI_GENERATED, 1)
            ->select('COUNT(*) as count')
            ->find()
            ->fetch();
        $totalCount = (int)$total->getData('count');
        
        // 获取启用数
        $active = clone $componentModel;
        $active->clear()
            ->where(Component::schema_fields_IS_AI_GENERATED, 1)
            ->where(Component::schema_fields_IS_ACTIVE, 1)
            ->select('COUNT(*) as count')
            ->find()
            ->fetch();
        $activeCount = (int)$active->getData('count');
        
        // 按分类统计
        $byCategory = [];
        foreach (['header', 'content', 'footer', 'widget'] as $category) {
            $cat = clone $componentModel;
            $cat->clear()
                ->where(Component::schema_fields_IS_AI_GENERATED, 1)
                ->where(Component::schema_fields_CATEGORY, $category)
                ->select('COUNT(*) as count')
                ->find()
                ->fetch();
            $byCategory[$category] = (int)$cat->getData('count');
        }
        
        return [
            'total' => $totalCount,
            'active' => $activeCount,
            'inactive' => $totalCount - $activeCount,
            'by_category' => $byCategory,
        ];
    }
    
    /**
     * 验证所有 AI 组件的实体文件
     * 
     * @return array ['valid' => int, 'missing' => array, 'outdated' => array]
     */
    public function validateEntityFiles(): array
    {
        $result = [
            'valid' => 0,
            'missing' => [],
            'outdated' => [],
        ];
        
        $components = $this->getAllComponents(false);
        
        foreach ($components as $component) {
            $code = $component->getData(Component::schema_fields_CODE);
            $filePath = $this->entityFileManager->getEntityFullPath($component);
            
            if (!file_exists($filePath)) {
                $result['missing'][] = $code;
            } elseif ($this->entityFileManager->needsUpdate($component)) {
                $result['outdated'][] = $code;
            } else {
                $result['valid']++;
            }
        }
        
        return $result;
    }
}
