<?php

declare(strict_types=1);

/*
 * AI 组件实体文件管理器
 * 
 * 负责管理 AI 生成组件的实体文件：
 * 1. 将数据库中的组件模板内容同步到文件系统
 * 2. 检测变更并按需更新实体文件
 * 3. 维护组件文件的映射关系
 */

namespace GuoLaiRen\PageBuilder\Service\AI;

use GuoLaiRen\PageBuilder\Model\Component;

class EntityFileManager
{
    // AI 组件实体文件存储基础路径（相对于模块目录）
    public const ENTITY_BASE_PATH = 'view/templates/style/_ai_generated/components/';
    
    // 模块路径
    private const MODULE_PATH = 'app/code/GuoLaiRen/PageBuilder/';
    
    /**
     * 获取完整的实体文件基础目录
     */
    public function getEntityBasePath(): string
    {
        return BP . self::MODULE_PATH . self::ENTITY_BASE_PATH;
    }
    
    /**
     * 获取组件的实体文件相对路径
     * 
     * @param Component $component 组件模型
     * @return string 相对路径（如 content/ai-2601291030.phtml）
     */
    public function getEntityRelativePath(Component $component): string
    {
        $category = $component->getData(Component::fields_CATEGORY) ?: 'content';
        $code = $component->getData(Component::fields_CODE);
        
        // 从组件代码中提取文件名
        $fileName = $this->codeToFileName($code);
        
        return "{$category}/{$fileName}.phtml";
    }
    
    /**
     * 获取组件的实体文件完整路径
     * 
     * @param Component $component 组件模型
     * @return string 完整文件路径
     */
    public function getEntityFullPath(Component $component): string
    {
        return $this->getEntityBasePath() . $this->getEntityRelativePath($component);
    }
    
    /**
     * 获取组件的模板路径（用于渲染）
     * 
     * @param Component $component 组件模型
     * @return string 用于 Weline 模板引擎的路径
     */
    public function getTemplatePath(Component $component): string
    {
        $relativePath = $this->getEntityRelativePath($component);
        return 'style/_ai_generated/components/' . $relativePath;
    }
    
    /**
     * 检查组件是否需要更新实体文件
     * 
     * @param Component $component 组件模型
     * @return bool
     */
    public function needsUpdate(Component $component): bool
    {
        // 检查模板内容
        $templateContent = $component->getTemplateContent();
        if (empty($templateContent)) {
            return false;
        }
        
        // 计算当前内容哈希
        $currentHash = $this->calculateContentHash($component);
        
        // 检查哈希是否变化
        if ($component->needsEntityFileUpdate($currentHash)) {
            return true;
        }
        
        // 检查文件是否存在
        $filePath = $this->getEntityFullPath($component);
        if (!file_exists($filePath)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 同步组件的实体文件
     * 
     * @param Component $component 组件模型
     * @param bool $updateModel 是否更新模型中的哈希和时间
     * @return string 生成的文件路径
     * @throws \Exception 如果无法写入文件
     */
    public function syncEntityFile(Component $component, bool $updateModel = true): string
    {
        $templateContent = $component->getTemplateContent();
        if (empty($templateContent)) {
            throw new \Exception('组件模板内容为空，无法生成实体文件');
        }
        
        $filePath = $this->getEntityFullPath($component);
        $directory = dirname($filePath);
        
        // 确保目录存在
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true)) {
                throw new \Exception("无法创建目录: {$directory}");
            }
        }
        
        // 写入文件
        if (file_put_contents($filePath, $templateContent) === false) {
            throw new \Exception("无法写入文件: {$filePath}");
        }
        
        // 更新模型中的哈希和时间
        if ($updateModel) {
            $hash = $this->calculateContentHash($component);
            $component->setEntityFileHash($hash);
            $component->setData(Component::fields_ENTITY_GENERATED_AT, date('Y-m-d H:i:s'));
            
            // 更新组件的路径字段，指向实体文件
            $component->setData(
                Component::fields_PATH, 
                'style/_ai_generated/components/' . $this->getEntityRelativePath($component)
            );
            
            $component->save();
        }
        
        return $filePath;
    }
    
    /**
     * 确保实体文件存在且是最新的
     * 
     * @param Component $component 组件模型
     * @return string 实体文件路径
     */
    public function ensureEntityFile(Component $component): string
    {
        if ($this->needsUpdate($component)) {
            return $this->syncEntityFile($component);
        }
        
        return $this->getEntityFullPath($component);
    }
    
    /**
     * 删除组件的实体文件
     * 
     * @param Component $component 组件模型
     * @return bool 是否成功删除
     */
    public function deleteEntityFile(Component $component): bool
    {
        $filePath = $this->getEntityFullPath($component);
        
        if (file_exists($filePath)) {
            return unlink($filePath);
        }
        
        return true;
    }
    
    /**
     * 清理过期的实体文件
     * 删除数据库中不存在的 AI 组件对应的文件
     * 
     * @return int 删除的文件数量
     */
    public function cleanup(): int
    {
        $basePath = $this->getEntityBasePath();
        $deletedCount = 0;
        
        if (!is_dir($basePath)) {
            return 0;
        }
        
        // 获取数据库中所有 AI 组件的代码
        $componentModel = \Weline\Framework\Manager\ObjectManager::getInstance(Component::class);
        $aiComponents = clone $componentModel;
        $aiComponents->clear()
            ->where(Component::fields_IS_AI_GENERATED, 1)
            ->select()
            ->fetch();
        
        $validCodes = [];
        foreach ($aiComponents->getItems() as $component) {
            $validCodes[] = $component->getData(Component::fields_CODE);
        }
        
        // 扫描实体文件目录
        $categories = ['header', 'content', 'footer', 'widget'];
        foreach ($categories as $category) {
            $categoryPath = $basePath . $category;
            if (!is_dir($categoryPath)) {
                continue;
            }
            
            $files = glob($categoryPath . '/*.phtml');
            foreach ($files as $file) {
                $fileName = basename($file, '.phtml');
                $code = $category . '-' . $fileName;
                
                // 如果代码不在有效列表中，删除文件
                if (!in_array($code, $validCodes)) {
                    if (unlink($file)) {
                        $deletedCount++;
                    }
                }
            }
        }
        
        return $deletedCount;
    }
    
    /**
     * 更新 component.json 配置文件
     * 用于记录所有 AI 生成的组件信息
     * 
     * @return bool
     */
    public function updateComponentJson(): bool
    {
        $basePath = $this->getEntityBasePath();
        $jsonPath = $basePath . 'component.json';
        
        // 确保目录存在
        if (!is_dir($basePath)) {
            if (!mkdir($basePath, 0755, true)) {
                return false;
            }
        }
        
        // 获取所有 AI 组件
        $componentModel = \Weline\Framework\Manager\ObjectManager::getInstance(Component::class);
        $aiComponents = clone $componentModel;
        $aiComponents->clear()
            ->where(Component::fields_IS_AI_GENERATED, 1)
            ->where(Component::fields_IS_ACTIVE, 1)
            ->order(Component::fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetch();
        
        $components = [];
        foreach ($aiComponents->getItems() as $component) {
            $code = $component->getData(Component::fields_CODE);
            $category = $component->getData(Component::fields_CATEGORY);
            
            $components[$code] = [
                'name' => $component->getData(Component::fields_NAME),
                'description' => $component->getData(Component::fields_DESCRIPTION),
                'region' => $component->getData(Component::fields_CATEGORY),
                'category' => $category,
                'type' => $component->getData(Component::fields_TYPE) ?: 'section',
                'file' => $this->getEntityRelativePath($component),
                'icon' => 'bi-robot', // AI 组件统一使用机器人图标
                'ai_generated' => true,
                'ai_version' => $component->getData(Component::fields_AI_VERSION),
                'compatible_styles' => ['*'],
                'sort_order' => (int)$component->getData(Component::fields_SORT_ORDER),
            ];
        }
        
        $jsonData = [
            '$schema' => '2.1.0',
            'template' => '_ai_generated',
            'name' => 'AI 生成组件',
            'description' => 'AI 自动生成的可视化编辑组件',
            'version' => '1.0.0',
            'auto_generated' => true,
            'updated_at' => date('Y-m-d H:i:s'),
            'regions' => [
                'header' => [
                    'name' => '头部区域',
                    'accepts' => ['header'],
                ],
                'content' => [
                    'name' => '内容区域',
                    'accepts' => ['content', 'widget'],
                ],
                'footer' => [
                    'name' => '底部区域',
                    'accepts' => ['footer'],
                ],
            ],
            'components' => $components,
        ];
        
        $jsonContent = json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        return file_put_contents($jsonPath, $jsonContent) !== false;
    }
    
    /**
     * 计算组件内容的哈希值
     * 
     * @param Component $component 组件模型
     * @return string MD5 哈希
     */
    public function calculateContentHash(Component $component): string
    {
        $content = $component->getTemplateContent();
        $configSchema = $component->getData(Component::fields_CONFIG_SCHEMA) ?: '';
        
        return md5($content . $configSchema);
    }
    
    /**
     * 将组件代码转换为文件名
     * 
     * @param string $code 组件代码（如 content-ai-2601291030）
     * @return string 文件名（如 ai-2601291030）
     */
    private function codeToFileName(string $code): string
    {
        // 移除分类前缀（如 content-、header-、footer-）
        $parts = explode('-', $code, 2);
        if (count($parts) >= 2) {
            $category = $parts[0];
            if (in_array($category, ['header', 'content', 'footer', 'widget'])) {
                return $parts[1];
            }
        }
        
        return $code;
    }
    
    /**
     * 批量同步所有需要更新的 AI 组件
     * 
     * @return array ['synced' => int, 'errors' => array]
     */
    public function syncAll(): array
    {
        $result = [
            'synced' => 0,
            'errors' => [],
        ];
        
        // 获取所有 AI 组件
        $componentModel = \Weline\Framework\Manager\ObjectManager::getInstance(Component::class);
        $aiComponents = clone $componentModel;
        $aiComponents->clear()
            ->where(Component::fields_IS_AI_GENERATED, 1)
            ->select()
            ->fetch();
        
        foreach ($aiComponents->getItems() as $component) {
            try {
                if ($this->needsUpdate($component)) {
                    $this->syncEntityFile($component);
                    $result['synced']++;
                }
            } catch (\Exception $e) {
                $result['errors'][] = [
                    'code' => $component->getData(Component::fields_CODE),
                    'message' => $e->getMessage(),
                ];
            }
        }
        
        // 更新 component.json
        $this->updateComponentJson();
        
        return $result;
    }
}
