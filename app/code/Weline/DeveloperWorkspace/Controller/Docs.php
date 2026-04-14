<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Controller;

use Weline\DeveloperWorkspace\Model\Document;
use Weline\DeveloperWorkspace\Model\Document\Catalog;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locale;
use Weline\Websites\Data\WebsiteData;

/**
 * 前端文档浏览控制器
 * 从Index控制器迁移而来
 */
class Docs extends FrontendController
{
    private Document $documentModel;
    private Catalog $catalogModel;
    
    /**
     * 存储最后一次错误信息
     */
    private ?string $lastError = null;
    
    /**
     * 缓存分类树原始数据，避免重复查询
     */
    private static ?array $cachedCatalogTree = null;
    
    /**
     * 缓存清理后的分类树数据，避免重复处理
     */
    private static ?array $cachedCleanedCatalogTree = null;
    
    public function __construct(
        Document $documentModel,
        Catalog $catalogModel
    ) {
        $this->documentModel = $documentModel;
        $this->catalogModel = $catalogModel;
        $this->assign('title', __('WelineFramework开发文档'));
    }
    
    /**
     * 文档浏览主页
     * /dev/tool/docs
     */
    public function index()
    {
        // 获取当前货币和语言
        $currentCurrency = \w_env('user.currency', 'CNY');
        $currentLanguage = \w_env('user.lang', 'zh_Hans_CN');
        
        // 获取网站默认货币和语言
        $defaultCurrency = \w_env('website.currency', 'CNY');
        $defaultLanguage = \w_env('website.lang', 'zh_Hans_CN');
        
        // 从数据库获取可用的货币列表
        $availableCurrencies = $this->getAvailableCurrencies();
        
        // 从数据库获取可用的语言列表
        $availableLocales = $this->getAvailableLocales();
        
        // 传递给模板
        $this->assign('currentCurrency', $currentCurrency);
        $this->assign('currentLanguage', $currentLanguage);
        $this->assign('defaultCurrency', $defaultCurrency);
        $this->assign('defaultLanguage', $defaultLanguage);
        $this->assign('availableCurrencies', $availableCurrencies);
        $this->assign('availableLocales', $availableLocales);
        
        // 获取文档ID（如果有）
        $documentId = $this->request->getParam('id');
        $catalogId = $this->request->getParam('catalog_id');
        
        // 如果指定了文档ID，加载文档
        if ($documentId) {
            $document = $this->documentModel->clear()->load($documentId);
            if ($document->getId()) {
                // 如果是自动导入的文档，从文件系统加载内容
                $isAutoImported = (int)($document->getData('is_auto_imported') ?? 0) === 1;
                if ($isAutoImported) {
                    $moduleName = $document->getModuleName() ?? '';
                    $filePath = $document->getFilePath() ?? '';
                    if ($moduleName && $filePath) {
                        $content = $this->loadDocumentFromFile($moduleName, $filePath);
                        if ($content !== false) {
                            // 临时设置内容，用于模板渲染
                            $document->setData('content', htmlspecialchars($content ?? ''));
                        }
                    }
                }
                $this->assign('document', $document);
                // 获取文档所属分类ID
                $catalogId = $document->getCategoryId();
            }
        }
        
        // 如果指定了分类ID，加载该分类下的文档列表
        if ($catalogId) {
            $documents = $this->documentModel->clear()
                ->where(Document::schema_fields_CATEGORY_ID, $catalogId)
                ->order('sort_order', 'ASC')
                ->order('id', 'DESC')
                ->select()
                ->fetch()
                ->getItems();
            $this->assign('documents', $documents);
        }
        
        return $this->fetch();
    }
    
    /**
     * 获取分类树（API）
     * /dev/tool/docs/tree
     */
    public function tree()
    {
        try {
            // 使用缓存的清理后的分类树，避免重复查询和处理
            if (self::$cachedCleanedCatalogTree === null) {
                // 使用缓存的原始分类树，避免重复查询
                if (self::$cachedCatalogTree === null) {
                    self::$cachedCatalogTree = $this->catalogModel->clear()->getTree('pid');
                }
                $trees = self::$cachedCatalogTree;
                
                // 清理数据，只返回必要字段
                self::$cachedCleanedCatalogTree = $this->cleanTreeData($trees);
            }
            
            return $this->fetchJson(self::$cachedCleanedCatalogTree);
        } catch (\Exception $e) {
            return $this->fetchJson(['error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * 获取分类下的文档列表（API）
     * 包括该分类及其所有子分类下的文档
     * /dev/tool/docs/documents?catalog_id=xxx
     */
    public function documents()
    {
        try {
            $catalogId = (int)($this->request->getParam('catalog_id') ?? 0);
            if (!$catalogId) {
                return $this->fetchJson(['error' => __('分类ID不能为空')], 400);
            }
            
            // 获取该分类及其所有子分类的ID列表
            $catalogIds = $this->getCatalogIdsWithChildren($catalogId);
            
            if (empty($catalogIds)) {
                return $this->fetchJson([]);
            }
            
            // 查询所有这些分类下的文档
            $documents = $this->documentModel->clear()
                ->where(Document::schema_fields_CATEGORY_ID, $catalogIds, 'in')
                ->order('sort_order', 'ASC')
                ->order('id', 'DESC')
                ->select()
                ->fetch()
                ->getItems();
            
            $result = [];
            foreach ($documents as $doc) {
                $result[] = [
                    'id' => $doc->getId(),
                    'title' => $doc->getTitle() ?? '',
                    'summary' => $doc->getData('summary') ?? '',
                    'category_id' => $doc->getCategoryId(),
                    'module_name' => $doc->getModuleName() ?? '',
                ];
            }
            
            return $this->fetchJson($result);
        } catch (\Exception $e) {
            return $this->fetchJson(['error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * 递归获取分类及其所有子分类的ID列表
     * 优化：使用缓存避免重复查询所有分类
     */
    private function getCatalogIdsWithChildren(int $catalogId): array
    {
        $catalogIds = [$catalogId];
        
        // 使用缓存的分类树，避免每次查询所有分类
        if (self::$cachedCatalogTree === null) {
            self::$cachedCatalogTree = $this->catalogModel->clear()->getTree('pid');
        }
        $tree = self::$cachedCatalogTree;
        
        // 如果树为空，直接返回当前分类ID
        if (empty($tree)) {
            return $catalogIds;
        }
        
        // 从树中查找指定分类并收集所有子分类ID
        $this->collectChildrenIdsFromTree($catalogId, $tree, $catalogIds);
        
        return $catalogIds;
    }
    
    /**
     * 清除分类树缓存（当分类数据更新时调用）
     */
    public function clearCatalogTreeCache(): void
    {
        self::$cachedCatalogTree = null;
        self::$cachedCleanedCatalogTree = null;
    }
    
    /**
     * 从树结构中递归收集子分类ID
     */
    private function collectChildrenIdsFromTree(int $parentId, array $tree, array &$catalogIds): void
    {
        foreach ($tree as $node) {
            $nodeId = (int)($node['id'] ?? 0);
            
            // 如果找到目标节点，收集其所有子节点
            if ($nodeId === $parentId) {
                // 递归收集所有子节点
                if (!empty($node['nodes']) && is_array($node['nodes'])) {
                    $this->extractAllChildrenIds($node['nodes'], $catalogIds);
                }
                return;
            }
            
            // 如果节点有子节点，继续在子树中查找
            if (!empty($node['nodes']) && is_array($node['nodes'])) {
                $this->collectChildrenIdsFromTree($parentId, $node['nodes'], $catalogIds);
            }
        }
    }
    
    /**
     * 从节点数组中提取所有子节点的ID
     */
    private function extractAllChildrenIds(array $nodes, array &$catalogIds): void
    {
        foreach ($nodes as $node) {
            $nodeId = (int)($node['id'] ?? 0);
            if ($nodeId > 0) {
                $catalogIds[] = $nodeId;
            }
            
            // 递归处理子节点
            if (!empty($node['nodes']) && is_array($node['nodes'])) {
                $this->extractAllChildrenIds($node['nodes'], $catalogIds);
            }
        }
    }
    
    /**
     * 获取文档详情（API）
     * 如果是自动导入的文档，从文件系统实时读取内容
     * 如果是用户创建的文档，从数据库读取内容
     * /dev/tool/docs/document?id=xxx
     */
    public function document()
    {
        try {
            $documentId = (int)($this->request->getParam('id') ?? 0);
            if (!$documentId) {
                return $this->fetchJson(['error' => __('文档ID不能为空')], 400);
            }
            
            $document = $this->documentModel->clear()->load($documentId);
            if (!$document->getId()) {
                return $this->fetchJson(['error' => __('文档不存在')], 404);
            }
            
            $content = '';
            $isAutoImported = (int)($document->getData('is_auto_imported') ?? 0) === 1;
            
            if ($isAutoImported) {
                // 自动导入的文档：从文件系统实时读取内容
                $moduleName = $document->getModuleName() ?? '';
                $filePath = $document->getFilePath() ?? '';
                
                if ($moduleName && $filePath) {
                    // 重置错误信息
                    $this->lastError = null;
                    $result = $this->loadDocumentFromFile($moduleName, $filePath);
                    if ($result === false) {
                        // 获取详细错误信息
                        $errorMsg = $this->getDocumentLoadError($moduleName, $filePath);
                        return $this->fetchJson(['error' => $errorMsg], 500);
                    }
                    $content = $result;
                } else {
                    $errorMsg = __('文档路径信息不完整');
                    if (empty($moduleName)) {
                        $errorMsg .= '：模块名为空';
                    }
                    if (empty($filePath)) {
                        $errorMsg .= '：文件路径为空';
                    }
                    return $this->fetchJson(['error' => $errorMsg], 500);
                }
            } else {
                // 用户创建的文档：从数据库读取内容
                $content = $document->getDecodeContent() ?? '';
                // 清理HTML注释（数据库中的内容也可能包含HTML注释）
                $content = $this->cleanHtmlComments($content);
            }
            
            return $this->fetchJson([
                'id' => $document->getId(),
                'title' => $document->getTitle() ?? '',
                'summary' => $document->getData('summary') ?? '',
                'content' => $content,
                'category_id' => $document->getCategoryId(),
                'module_name' => $document->getModuleName() ?? '',
                'file_name' => $document->getFileName() ?? '',
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson(['error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * 从文件系统加载文档内容
     * 
     * @param string $moduleName 模块名
     * @param string $relativePath 相对于模块根目录的路径
     * @return string|false 文档内容，失败返回false
     */
    private function loadDocumentFromFile(string $moduleName, string $relativePath): string|false
    {
        try {
            // 获取模块信息
            $modules = \Weline\Framework\App\Env::getInstance()->getModuleList();
            if (!isset($modules[$moduleName])) {
                $this->lastError = "模块 '{$moduleName}' 不存在";
                return false;
            }
            
            $module = $modules[$moduleName];
            $moduleBasePath = $module['base_path'] ?? '';
            if (empty($moduleBasePath)) {
                $this->lastError = "模块 '{$moduleName}' 的 base_path 为空";
                return false;
            }
            
            // 构建完整文件路径：模块根目录/相对路径
            $moduleBasePath = rtrim($moduleBasePath, '/\\');
            $fullPath = $moduleBasePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            
            // 安全检查：确保文件在模块目录内
            $realModulePath = realpath($moduleBasePath);
            $realFullPath = realpath($fullPath);
            if ($realModulePath === false) {
                $this->lastError = "模块目录不存在：{$moduleBasePath}";
                return false;
            }
            if ($realFullPath === false) {
                $this->lastError = "文档文件不存在：{$fullPath}";
                return false;
            }
            
            // 确保文件路径在模块目录内（防止路径遍历攻击）
            if (strpos($realFullPath, $realModulePath) !== 0) {
                $this->lastError = "文件路径不在模块目录内：{$realFullPath}";
                return false;
            }
            
            // 检查文件扩展名，只允许文档文件格式
            $fileExtension = strtolower(pathinfo($realFullPath, PATHINFO_EXTENSION));
            $allowedExtensions = ['md', 'markdown', 'txt'];
            if (!in_array($fileExtension, $allowedExtensions)) {
                $this->lastError = "只允许读取文档格式的文件（" . implode(', ', $allowedExtensions) . "），当前文件扩展名：{$fileExtension}";
                return false;
            }
            
            // 读取文件内容
            if (!is_file($realFullPath)) {
                $this->lastError = "不是有效的文件：{$realFullPath}";
                return false;
            }
            if (!is_readable($realFullPath)) {
                $this->lastError = "文件不可读：{$realFullPath}";
                return false;
            }
            
            // 读取并转换为UTF-8编码
            $content = file_get_contents($realFullPath);
            if ($content === false) {
                $this->lastError = "无法读取文件内容：{$realFullPath}";
                return false;
            }
            
            // 如果文件为空，直接返回
            if (empty($content)) {
                return '';
            }
            
            // 检查是否已经是有效的UTF-8编码
            if (mb_check_encoding($content, 'UTF-8')) {
                return $content;
            }
            
            // 检测文件编码并转换
            $detectEncodings = ['UTF-8', 'GBK', 'GB2312', 'BIG5', 'ISO-8859-1', 'Windows-1252', 'ASCII'];
            $encoding = mb_detect_encoding($content, $detectEncodings, true);
            
            if ($encoding === false || empty($encoding)) {
                $encoding = 'GBK';
            }
            
            $encoding = (string)$encoding;
            
            // 转换为UTF-8
            $utf8Content = mb_convert_encoding($content, 'UTF-8', $encoding);
            
            // 验证转换结果
            if ($utf8Content === false || !mb_check_encoding($utf8Content ?? '', 'UTF-8')) {
                // 尝试其他编码
                $fallbackEncodings = ['GBK', 'GB2312', 'BIG5', 'ISO-8859-1', 'Windows-1252', 'ASCII'];
                foreach ($fallbackEncodings as $enc) {
                    if ($enc === $encoding) {
                        continue;
                    }
                    $testContent = @mb_convert_encoding($content, 'UTF-8', $enc);
                    if ($testContent !== false && mb_check_encoding($testContent, 'UTF-8')) {
                        return $testContent;
                    }
                }
                
                // 最后的备选方案
                $utf8Content = mb_convert_encoding($content, 'UTF-8', 'UTF-8//IGNORE');
                if ($utf8Content === false) {
                    $utf8Content = mb_convert_encoding($content, 'UTF-8', 'GBK//IGNORE');
                }
            }
            
            // 确保返回的是有效的UTF-8字符串
            if ($utf8Content === false || !mb_check_encoding($utf8Content ?? '', 'UTF-8')) {
                $contentToClean = $content; // 如果转换失败，使用原始内容
            } else {
                $contentToClean = $utf8Content;
            }
            
            // 清理HTML注释
            $cleanedContent = $this->cleanHtmlComments($contentToClean);
            
            return $cleanedContent;
        } catch (\Exception $e) {
            $this->lastError = "读取文档时发生异常：" . $e->getMessage();
            return false;
        }
    }
    
    /**
     * 获取文档加载错误信息
     * 
     * @param string $moduleName 模块名
     * @param string $filePath 文件路径
     * @return string 错误信息
     */
    private function getDocumentLoadError(string $moduleName, string $filePath): string
    {
        if ($this->lastError) {
            return __('无法读取文档文件') . '：' . $this->lastError;
        }
        return __('无法读取文档文件') . "（模块：{$moduleName}，路径：{$filePath}）";
    }
    
    /**
     * 清理HTML注释
     * 
     * @param string|null $content 原始内容
     * @return string 清理后的内容
     */
    private function cleanHtmlComments(?string $content): string
    {
        if (empty($content)) {
            return '';
        }
        
        // 清理HTML注释（包括单行和多行注释）
        // 匹配格式：<!-- ... --> 或 <!-- ... \n ... -->
        $cleanedContent = preg_replace('/<!--[\s\S]*?-->/', '', $content ?? '');
        
        // preg_replace 可能返回 null，需要处理
        if ($cleanedContent === null) {
            $cleanedContent = $content ?? '';
        }
        
        // 清理后可能产生多余的空行，移除连续的空行（保留单个空行）
        $cleanedContent = preg_replace('/\n{3,}/', "\n\n", $cleanedContent ?? '');
        
        // preg_replace 可能返回 null，需要处理
        if ($cleanedContent === null) {
            $cleanedContent = '';
        }
        
        // 清理首尾空白
        $cleanedContent = trim($cleanedContent ?? '');
        
        return $cleanedContent;
    }
    
    /**
     * 搜索文档（API）
     * /dev/tool/docs/search?keyword=xxx
     */
    public function search()
    {
        try {
            $keyword = trim($this->request->getParam('keyword') ?? '');
            if (empty($keyword)) {
                return $this->fetchJson(['error' => __('搜索关键词不能为空')], 400);
            }
            
            $documents = $this->documentModel->clear()
                ->where(Document::schema_fields_TITLE, '%' . $keyword . '%', 'LIKE')
                ->where(Document::schema_fields_summary, '%' . $keyword . '%', 'LIKE', 'OR')
                ->where(Document::schema_fields_CONTEND, '%' . $keyword . '%', 'LIKE', 'OR')
                ->order('id', 'DESC')
                ->limit(50)
                ->select()
                ->fetch()
                ->getItems();
            
            $result = [];
            foreach ($documents as $doc) {
                $result[] = [
                    'id' => $doc->getId(),
                    'title' => $doc->getTitle() ?? '',
                    'summary' => $doc->getData('summary') ?? '',
                    'category_id' => $doc->getCategoryId(),
                    'module_name' => $doc->getModuleName() ?? '',
                ];
            }
            
            return $this->fetchJson($result);
        } catch (\Exception $e) {
            return $this->fetchJson(['error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * 清理树形数据，只返回必要字段
     * 直接使用数据中的level字段，不进行递增计算（数据已经是正确的层级值）
     */
    private function cleanTreeData(array $trees): array
    {
        $result = [];
        foreach ($trees as $tree) {
            // 直接使用数据中的level字段（数据已经是正确的层级值）
            $itemLevel = isset($tree['level']) && $tree['level'] !== null && $tree['level'] !== '' 
                ? (int)$tree['level'] 
                : 1;
            
            $item = [
                'id' => $tree['id'] ?? 0,
                'name' => $tree['name'] ?? '',
                'description' => $tree['description'] ?? '',
                'pid' => $tree['pid'] ?? 0,
                'level' => $itemLevel, // 使用数据中的level值
                'is_active' => $tree['is_active'] ?? 0,
            ];
            
            // 递归处理子节点，子节点的level值已经在数据中了，不需要传递level参数
            if (isset($tree['nodes']) && is_array($tree['nodes']) && count($tree['nodes']) > 0) {
                $item['nodes'] = $this->cleanTreeData($tree['nodes']);
            }
            
            $result[] = $item;
        }
        return $result;
    }
    
    /**
     * API文档管理界面（新版，参照开发文档页面布局）
     * /dev/tool/docs/api
     */
    public function api()
    {
        try {
            // 获取当前货币和语言
            $currentCurrency = \w_env('user.currency', 'CNY');
            $currentLanguage = \w_env('user.lang', 'zh_Hans_CN');
            
            // 获取网站默认货币和语言
            $defaultCurrency = \w_env('website.currency', 'CNY');
            $defaultLanguage = \w_env('website.lang', 'zh_Hans_CN');
            
            // 从数据库获取可用的货币列表
            $availableCurrencies = $this->getAvailableCurrencies();
            
            // 从数据库获取可用的语言列表
            $availableLocales = $this->getAvailableLocales();
            
            // 获取 REST API 区域配置（使用新的 area_routes 配置）
            $apiArea = \Weline\Framework\App\Env::getAreaRoutePrefix('rest_frontend') ?: 'api';
            $apiAdminArea = \Weline\Framework\App\Env::getAreaRoutePrefix('rest_backend') ?: 'api_admin';
            
            // 传递给模板
            $this->assign('currentCurrency', $currentCurrency);
            $this->assign('currentLanguage', $currentLanguage);
            $this->assign('defaultCurrency', $defaultCurrency);
            $this->assign('defaultLanguage', $defaultLanguage);
            $this->assign('availableCurrencies', $availableCurrencies);
            $this->assign('availableLocales', $availableLocales);
            $this->assign('apiArea', $apiArea);
            $this->assign('apiAdminArea', $apiAdminArea);
            
            // 获取API文档数据
            /** @var \Weline\Api\Service\ApiDocService $apiDocService */
            $apiDocService = ObjectManager::getInstance(\Weline\Api\Service\ApiDocService::class);
            $allApis = $apiDocService->generateAll(true); // 强制重新生成，忽略缓存
            
            // 提取displayName的辅助函数
            $extractDisplayName = function(string $name): string {
                // 检查是否以 {数字}- 开头
                if (preg_match('/^(\d+)-(.+)$/', $name, $matches)) {
                    return $matches[2]; // 返回去掉数字前缀的部分
                }
                return $name;
            };
            
            // 按模块和版本组织数据
            $organizedApis = [];
            $moduleDisplayNames = []; // 存储模块的display_name映射
            $apiMap = []; // 用于快速查找API
            
            if (!empty($allApis) && is_array($allApis)) {
                foreach ($allApis as $moduleName => $apis) {
                    if (empty($apis) || !is_array($apis)) {
                        continue;
                    }
                    
                    // 提取并存储display_name
                    $displayName = $extractDisplayName($moduleName);
                    $moduleDisplayNames[$moduleName] = $displayName;
                    
                    foreach ($apis as $api) {
                        if (empty($api) || !is_array($api)) {
                            continue;
                        }
                        $version = $api['version'] ?? 'v1';
                        $className = $api['class'] ?? '';
                        $methodName = $api['method'] ?? '';
                        
                        if (empty($className)) {
                            continue;
                        }
                        
                        // 生成API唯一ID
                        $apiId = 'api_' . $moduleName . '_' . $version . '_' . $className . '_' . $methodName;
                        $api['id'] = $apiId;
                        
                        if (!isset($organizedApis[$moduleName])) {
                            $organizedApis[$moduleName] = [];
                        }
                        if (!isset($organizedApis[$moduleName][$version])) {
                            $organizedApis[$moduleName][$version] = [];
                        }
                        if (!isset($organizedApis[$moduleName][$version][$className])) {
                            $organizedApis[$moduleName][$version][$className] = [];
                        }
                        
                        $organizedApis[$moduleName][$version][$className][] = $api;
                        $apiMap[$apiId] = $api;
                    }
                }
            }
            
            // 获取选中的API
            $apiId = $this->request->getParam('api_id');
            $selectedApi = null;
            if (!empty($apiId) && isset($apiMap[$apiId])) {
                $selectedApi = $apiMap[$apiId];
            }
            
            $this->assign('apis', $organizedApis);
            $this->assign('moduleDisplayNames', $moduleDisplayNames); // 传递模块display_name映射
            $this->assign('selectedApi', $selectedApi);
            $this->assign('apiId', $apiId);
            $this->assign('title', __('API文档管理'));
            $this->assign('error', null);
            
            // 使用新的API文档管理模板
            return $this->fetch('Weline_DeveloperWorkspace::templates/Docs/api-manager');
        } catch (\Exception $e) {
            $this->assign('apis', []);
            $this->assign('selectedApi', null);
            $this->assign('title', __('API文档管理'));
            $this->assign('error', $e->getMessage());
            return $this->fetch('Weline_DeveloperWorkspace::templates/Docs/api-manager');
        }
    }
    
    /**
     * 获取可用的货币列表（从当前网站关联的货币中获取）
     * 
     * @return array 货币列表 [['code' => 'CNY', 'name' => '人民币'], ...]
     */
    private function getAvailableCurrencies(): array
    {
        try {
            // 从当前网站获取关联的货币列表
            $currencies = WebsiteData::getCurrencies();
            
            // 如果网站没有关联货币，返回默认列表
            if (empty($currencies)) {
                return [
                    ['code' => 'CNY', 'name' => '人民币'],
                    ['code' => 'USD', 'name' => '美元'],
                ];
            }
            
            // 转换为需要的格式
            $result = [];
            foreach ($currencies as $currency) {
                $result[] = [
                    'code' => $currency['code'] ?? '',
                    'name' => $currency['name'] ?? '',
                ];
            }
            
            return $result;
        } catch (\Exception $e) {
            // 如果查询失败，返回默认列表
            return [
                ['code' => 'CNY', 'name' => '人民币'],
                ['code' => 'USD', 'name' => '美元'],
            ];
        }
    }
    
    /**
     * 获取可用的语言列表（从当前网站关联的语言中获取）
     * 
     * @return array 语言列表 [['code' => 'zh_Hans_CN', 'name' => '简体中文'], ...]
     */
    private function getAvailableLocales(): array
    {
        try {
            $languageCodes = WebsiteData::getLanguageCodes();
            $displayLocaleCode = \w_env('user.lang', \w_env('website.lang', 'zh_Hans_CN'));

            /** @var Locale $localeModel */
            $localeModel = ObjectManager::getInstance(Locale::class);
            /** @var I18n $i18n */
            $i18n = ObjectManager::getInstance(I18n::class);

            $query = $localeModel->clear()
                ->order(Locale::schema_fields_CODE, 'ASC');

            if (empty($languageCodes)) {
                $query->where(Locale::schema_fields_IS_INSTALL, 1);
            } else {
                $query->where(Locale::schema_fields_CODE, array_values(array_unique($languageCodes)), 'in');
            }

            // Use fetchArray here to avoid cloning the model for every locale row.
            $rows = $query->select()->fetchArray();
            if (!is_array($rows) || empty($rows)) {
                return [
                    ['code' => 'zh_Hans_CN', 'name' => '简体中文'],
                    ['code' => 'en_US', 'name' => 'English'],
                ];
            }

            $namesByCode = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $code = trim((string)($row[Locale::schema_fields_CODE] ?? ''));
                if ($code === '' || isset($namesByCode[$code])) {
                    continue;
                }
                $name = (string)$i18n->getLocaleName($code, $displayLocaleCode);
                $namesByCode[$code] = $name !== '' ? $name : $code;
            }

            if (empty($namesByCode)) {
                return [
                    ['code' => 'zh_Hans_CN', 'name' => '简体中文'],
                    ['code' => 'en_US', 'name' => 'English'],
                ];
            }

            $result = [];
            if (!empty($languageCodes)) {
                foreach ($languageCodes as $code) {
                    $code = trim((string)$code);
                    if ($code === '') {
                        continue;
                    }
                    $result[] = [
                        'code' => $code,
                        'name' => $namesByCode[$code] ?? $code,
                    ];
                }
                if (!empty($result)) {
                    return $result;
                }
            }

            foreach ($namesByCode as $code => $name) {
                $result[] = [
                    'code' => $code,
                    'name' => $name,
                ];
            }

            return $result;
        } catch (\Exception $e) {
            return [
                ['code' => 'zh_Hans_CN', 'name' => '简体中文'],
                ['code' => 'en_US', 'name' => 'English'],
            ];
        }
    }
}

