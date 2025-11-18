<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\DeveloperWorkspace\Service;

use Weline\Api\Service\ApiDocService;
use Weline\DeveloperWorkspace\Model\Document;
use Weline\DeveloperWorkspace\Model\Document\Catalog;
use Weline\Framework\Api\Validator\ApiSpecValidator;
use Weline\Framework\Manager\ObjectManager;

/**
 * API文档导入服务
 * 
 * 用于将API文档导入到DeveloperWorkspace文档系统
 */
class ApiDocImporter
{
    private ApiDocService $apiDocService;
    private ApiSpecValidator $validator;
    private Document $documentModel;
    private Catalog $catalogModel;
    
    /**
     * 进度回调函数
     */
    private $progressCallback = null;
    
    public function __construct(
        ApiDocService $apiDocService,
        ApiSpecValidator $validator,
        Document $documentModel,
        Catalog $catalogModel
    ) {
        $this->apiDocService = $apiDocService;
        $this->validator = $validator;
        $this->documentModel = $documentModel;
        $this->catalogModel = $catalogModel;
    }
    
    /**
     * 设置进度回调函数
     */
    public function setProgressCallback(callable $callback): self
    {
        $this->progressCallback = $callback;
        return $this;
    }
    
    /**
     * 输出进度信息
     */
    private function progress(string $message, string $type = 'info'): void
    {
        if ($this->progressCallback) {
            call_user_func($this->progressCallback, $message, $type);
        }
    }
    
    /**
     * 导入所有API文档
     * 
     * @param bool $force 是否强制重新导入
     * @return array 导入结果
     */
    public function importAll(bool $force = false): array
    {
        $result = [
            'scanned' => 0,
            'new' => 0,
            'updated' => 0,
            'deleted' => 0,
            'modules' => []
        ];
        
        // 如果强制重新导入，先删除旧的API文档
        if ($force) {
            $this->progress(__('正在清理旧的API文档...'), 'warning');
            $this->documentModel->where(Document::fields_IS_AUTO_IMPORTED, 1)
                ->where(Document::fields_MODULE_NAME, 'API', 'like')
                ->delete()
                ->fetch();
            $this->progress(__('清理完成'), 'success');
        }
        
        // 确保API文档分类存在
        $apiCatalog = $this->ensureApiCatalog();
        $apiCatalogId = (int)$apiCatalog->getId();
        
        // 生成所有API文档
        $this->progress(__('正在生成API文档...'), 'info');
        $allApis = $this->apiDocService->generateAll($force);
        
        // 按模块组织并导入
        foreach ($allApis as $moduleName => $apis) {
            // 提取displayName（去掉排序号）
            $sortKey = $this->extractSortKey($moduleName);
            $displayName = $sortKey['displayName'];
            
            $moduleResult = $this->importModuleApis($moduleName, $apis, $apiCatalog);
            $result['scanned'] += $moduleResult['scanned'];
            $result['new'] += $moduleResult['new'];
            $result['updated'] += $moduleResult['updated'];
            $result['modules'][] = [
                'name' => $moduleName,
                'display_name' => $displayName,
                'scanned' => $moduleResult['scanned'],
                'new' => $moduleResult['new'],
                'updated' => $moduleResult['updated']
            ];
        }
        
        return $result;
    }
    
    /**
     * 导入模块的API文档
     */
    private function importModuleApis(string $moduleName, array $apis, Catalog $parentCatalog): array
    {
        $result = [
            'scanned' => 0,
            'new' => 0,
            'updated' => 0
        ];
        
        // 确保模块分类存在
        $moduleCatalog = $this->ensureModuleApiCatalog($moduleName, $parentCatalog);
        
        // 按版本分组
        $apisByVersion = [];
        foreach ($apis as $api) {
            $version = $api['version'] ?? 'v1';
            if (!isset($apisByVersion[$version])) {
                $apisByVersion[$version] = [];
            }
            $apisByVersion[$version][] = $api;
        }
        
        // 按版本导入
        foreach ($apisByVersion as $version => $versionApis) {
            // 确保版本分类存在
            $versionCatalog = $this->ensureVersionCatalog($version, $moduleCatalog);
            
            // 按类分组
            $apisByClass = [];
            foreach ($versionApis as $api) {
                $className = $api['class'] ?? '';
                if (!isset($apisByClass[$className])) {
                    $apisByClass[$className] = [];
                }
                $apisByClass[$className][] = $api;
            }
            
            // 按类导入
            foreach ($apisByClass as $className => $classApis) {
                // 确保类分类存在
                $classCatalog = $this->ensureClassCatalog($className, $versionCatalog, $classApis[0] ?? []);
                
                // 导入每个API方法
                foreach ($classApis as $api) {
                    $this->importApiDocument($api, $classCatalog, $result);
                }
            }
        }
        
        return $result;
    }
    
    /**
     * 导入单个API文档
     */
    private function importApiDocument(array $api, Catalog $catalog, array &$result): void
    {
        $result['scanned']++;
        
        // 构建文档唯一标识
        $documentKey = $this->buildApiDocumentKey($api);
        
        // 生成文档内容（Markdown格式）
        $content = $this->generateApiDocumentContent($api);
        
        // 提取标题和摘要
        $title = $api['document']['summary'] ?? ($api['method'] ?? '');
        $summary = $api['document']['description'] ?? '';
        
        // 检查是否已存在
        $existingDoc = $this->documentModel->clear()
            ->where(Document::fields_MODULE_NAME, 'API_' . ($api['module'] ?? ''))
            ->where(Document::fields_FILE_PATH, $documentKey)
            ->where(Document::fields_IS_AUTO_IMPORTED, 1)
            ->find()
            ->fetch();
        
        if ($existingDoc && $existingDoc->getId()) {
            // 更新现有文档
            $existingDoc->setTitle($title)
                ->setSummary($summary)
                ->setContent($content)
                ->setCategoryId((string)$catalog->getId())
                ->save();
            $result['updated']++;
            $this->progress("      ↻ " . __('更新API文档: %{method}', ['method' => $api['method'] ?? '']), 'info');
        } else {
            // 创建新文档
            $newDoc = ObjectManager::getInstance(Document::class);
            $newDoc->setTitle($title)
                ->setSummary($summary)
                ->setContent($content)
                ->setModuleName('API_' . ($api['module'] ?? ''))
                ->setFilePath($documentKey)
                ->setFileName($api['method'] ?? '')
                ->setCategoryId((string)$catalog->getId())
                ->setIsAutoImported(true)
                ->setSortOrder(0)
                ->save();
            $result['new']++;
            $this->progress("      ✨ " . __('新增API文档: %{method}', ['method' => $api['method'] ?? '']), 'success');
        }
    }
    
    /**
     * 生成API文档内容（Markdown格式）
     */
    private function generateApiDocumentContent(array $api): string
    {
        $content = "# " . ($api['document']['summary'] ?? '') . "\n\n";
        
        // 描述
        if (!empty($api['document']['description'])) {
            $content .= $api['document']['description'] . "\n\n";
        }
        
        // 基本信息
        $content .= "## 基本信息\n\n";
        $content .= "- **模块**: " . ($api['module'] ?? '') . "\n";
        $content .= "- **版本**: " . ($api['version'] ?? 'v1') . "\n";
        $content .= "- **类**: `" . ($api['class'] ?? '') . "`\n";
        $content .= "- **方法**: `" . ($api['method'] ?? '') . "`\n";
        $content .= "- **路由**: `" . ($api['route']['method'] ?? '') . " " . ($api['route']['path'] ?? '') . "`\n\n";
        
        // 参数
        if (!empty($api['parameters'])) {
            $content .= "## 参数\n\n";
            $content .= "| 参数名 | 类型 | 必填 | 说明 |\n";
            $content .= "|--------|------|------|------|\n";
            foreach ($api['parameters'] as $param) {
                $content .= "| `" . ($param['name'] ?? '') . "` | " . ($param['type'] ?? 'mixed') . " | " . (($param['required'] ?? false) ? '是' : '否') . " | " . ($param['description'] ?? '') . " |\n";
            }
            $content .= "\n";
        }
        
        // 响应
        if (!empty($api['responses'])) {
            $content .= "## 响应\n\n";
            foreach ($api['responses'] as $code => $response) {
                $content .= "### {$code}\n\n";
                $content .= ($response['description'] ?? '') . "\n\n";
            }
        }
        
        // 示例
        if (!empty($api['example'])) {
            $content .= "## 示例\n\n";
            $example = $api['example'];
            
            if (!empty($example['method']) && !empty($example['path'])) {
                $content .= "**请求**:\n\n";
                $content .= "```\n";
                $content .= ($example['method'] ?? '') . " " . ($example['path'] ?? '') . "\n";
                if (!empty($example['headers'])) {
                    foreach ($example['headers'] as $name => $value) {
                        $content .= "{$name}: {$value}\n";
                    }
                }
                if (!empty($example['body'])) {
                    $content .= "\n" . json_encode($example['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
                }
                $content .= "```\n\n";
            }
            
            if (!empty($example['response'])) {
                $content .= "**响应**:\n\n";
                $content .= "```json\n";
                $content .= json_encode($example['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
                $content .= "```\n\n";
            }
        }
        
        return $content;
    }
    
    /**
     * 构建API文档唯一标识
     */
    private function buildApiDocumentKey(array $api): string
    {
        return ($api['module'] ?? '') . '/' . ($api['version'] ?? 'v1') . '/' . ($api['class'] ?? '') . '/' . ($api['method'] ?? '');
    }
    
    /**
     * 确保API文档顶层分类存在
     */
    private function ensureApiCatalog(): Catalog
    {
        $catalog = $this->catalogModel->clear()
            ->where(Catalog::fields_NAME, 'API文档')
            ->where(Catalog::fields_is_system, 1)
            ->find()
            ->fetch();
        
        if (!$catalog->getId()) {
            $catalog->setName('API文档')
                ->setDescription('自动导入的API接口文档')
                ->setIsSystem(true)
                ->setSortOrder(0)
                ->save();
        }
        
        return $catalog;
    }
    
    /**
     * 提取文件名/目录名的排序键（支持 {数字}- 前缀排序）
     * 
     * @param string $name 文件名或目录名
     * @return array [sortOrder, displayName] sortOrder用于排序，displayName用于显示
     */
    private function extractSortKey(string $name): array
    {
        // 检查是否以 {数字}- 开头
        if (preg_match('/^(\d+)-(.+)$/', $name, $matches)) {
            return [
                'sortOrder' => (int)$matches[1],
                'displayName' => $matches[2],
                'originalName' => $name
            ];
        }
        // 没有数字前缀，使用大数字确保排在后面
        return [
            'sortOrder' => 999999,
            'displayName' => $name,
            'originalName' => $name
        ];
    }
    
    /**
     * 确保模块API分类存在
     */
    private function ensureModuleApiCatalog(string $moduleName, Catalog $parentCatalog): Catalog
    {
        // 提取displayName（去掉排序号）
        $sortKey = $this->extractSortKey($moduleName);
        $displayName = $sortKey['displayName'];
        $sortOrder = $sortKey['sortOrder'];
        
        $catalog = $this->catalogModel->clear()
            ->where(Catalog::fields_NAME, $displayName)
            ->where(Catalog::fields_PID, $parentCatalog->getId())
            ->where(Catalog::fields_is_system, 1)
            ->find()
            ->fetch();
        
        if (!$catalog->getId()) {
            $catalog->setName($displayName)
                ->setDescription("模块 {$displayName} 的API文档")
                ->setParentId((string)$parentCatalog->getId())
                ->setIsSystem(true)
                ->setSortOrder($sortOrder)
                ->save();
            $this->progress("   📁 " . __('处理模块分类: %{name}', ['name' => $displayName]), 'info');
        }
        
        return $catalog;
    }
    
    /**
     * 确保版本分类存在
     */
    private function ensureVersionCatalog(string $version, Catalog $parentCatalog): Catalog
    {
        $catalog = $this->catalogModel->clear()
            ->where(Catalog::fields_NAME, $version)
            ->where(Catalog::fields_PID, $parentCatalog->getId())
            ->where(Catalog::fields_is_system, 1)
            ->find()
            ->fetch();
        
        if (!$catalog->getId()) {
            $catalog->setName($version)
                ->setDescription("API版本 {$version}")
                ->setParentId((string)$parentCatalog->getId())
                ->setIsSystem(true)
                ->setSortOrder(0)
                ->save();
        }
        
        return $catalog;
    }
    
    /**
     * 确保类分类存在
     */
    private function ensureClassCatalog(string $className, Catalog $parentCatalog, array $apiInfo): Catalog
    {
        $shortClassName = substr(strrchr($className, '\\'), 1) ?: $className;
        $category = $apiInfo['document']['category'] ?? $shortClassName;
        
        $catalog = $this->catalogModel->clear()
            ->where(Catalog::fields_NAME, $category)
            ->where(Catalog::fields_PID, $parentCatalog->getId())
            ->where(Catalog::fields_is_system, 1)
            ->find()
            ->fetch();
        
        if (!$catalog->getId()) {
            $catalog->setName($category)
                ->setDescription("API类 {$shortClassName}")
                ->setParentId((string)$parentCatalog->getId())
                ->setIsSystem(true)
                ->setSortOrder(0)
                ->save();
        }
        
        return $catalog;
    }
}

