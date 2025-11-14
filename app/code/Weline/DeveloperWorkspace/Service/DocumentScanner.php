<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Service;

use Weline\DeveloperWorkspace\Model\Document;
use Weline\DeveloperWorkspace\Model\Document\Catalog;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\Env;
use Weline\Framework\Register\Register;

/**
 * 文档扫描服务 - 自动扫描各模块的doc目录
 */
class DocumentScanner
{
    private Document $documentModel;
    private Catalog $catalogModel;
    
    /**
     * 需要忽略的目录
     */
    private array $ignoreDirs = [
        '.',
        '..',
        '.git',
        '.svn',
        'vendor',
        'node_modules',
        'var',
        'pub',
        'generated'
    ];
    
    /**
     * 进度回调函数
     */
    private $progressCallback = null;
    
    public function __construct(
        Document $documentModel,
        Catalog $catalogModel
    ) {
        $this->documentModel = $documentModel;
        $this->catalogModel = $catalogModel;
    }
    
    /**
     * 设置进度回调函数
     * 
     * @param callable $callback 回调函数，接收 (string $message, string $type = 'info') 参数
     * @return $this
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
     * 扫描所有模块的文档
     * 
     * @param bool $forceRescan 是否强制重新扫描（会删除旧的自动导入文档）
     * @return array ['scanned' => 总数, 'new' => 新增, 'updated' => 更新, 'deleted' => 删除, 'modules' => [模块列表]]
     */
    public function scanAllModules(bool $forceRescan = false): array
    {
        $result = [
            'scanned' => 0,
            'new' => 0,
            'updated' => 0,
            'deleted' => 0,
            'modules' => []
        ];
        
        // 如果强制重新扫描，先删除所有自动导入的文档和分类
        if ($forceRescan) {
            $this->progress(__('正在清理旧的自动导入文档和分类...'), 'warning');
            // 先删除文档
            $this->documentModel->where(Document::fields_IS_AUTO_IMPORTED, 1)->delete()->fetch();
            // 删除所有系统分类（除了顶层"模块文档"分类）
            $topCatalog = $this->ensureModuleDocumentCatalog();
            $topCatalogId = (int)$topCatalog->getId();
            $this->catalogModel->clear()
                ->where(Catalog::fields_is_system, 1)
                ->where(Catalog::fields_ID, $topCatalogId, '!=')
                ->delete()
                ->fetch();
            $this->progress(__('清理完成'), 'success');
        }
        
        // 收集所有扫描到的文档的唯一标识（module_name + file_path）
        $scannedDocumentKeys = [];
        // 收集所有扫描到的分类ID（用于后续删除不存在的分类）
        $scannedCatalogIds = [];
        
        // 获取所有已安装的模块
        $modules = Env::getInstance()->getModuleList();
        $totalModules = count($modules);
        $currentModule = 0;
        $modulesWithDoc = [];
        
        // 先统计有doc目录的模块数量（排除 Weline_Framework，它需要特殊处理）
        foreach ($modules as $moduleName => $module) {
            $modulePath = $module['base_path'] ?? '';
            if (empty($moduleName) || empty($modulePath) || !($module['status'] ?? false)) {
                continue;
            }
            $docPath = rtrim($modulePath, '/\\') . DIRECTORY_SEPARATOR . 'doc';
            if (is_dir($docPath)) {
                $modulesWithDoc[] = $moduleName;
            }
        }
        
        // 先确保"模块文档"顶层分类存在（Weline_Framework 需要用到）
        $topCatalog = $this->ensureModuleDocumentCatalog();
        $topCatalogId = (int)$topCatalog->getId();
        $scannedCatalogIds[] = $topCatalogId;
        
        // 特殊处理 Weline_Framework 模块（创建模块分类到"模块文档"下，但doc下的子目录仍作为顶层分类）
        if (isset($modules['Weline_Framework'])) {
            $frameworkModule = $modules['Weline_Framework'];
            $frameworkModulePath = $frameworkModule['base_path'] ?? '';
            if (!empty($frameworkModulePath) && ($frameworkModule['status'] ?? false)) {
                $frameworkDocPath = rtrim($frameworkModulePath, '/\\') . DIRECTORY_SEPARATOR . 'doc';
                if (is_dir($frameworkDocPath)) {
                    $this->progress("", 'info'); // 空行
                    $this->progress("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━", 'info');
                    $this->progress(__('正在扫描模块: Weline_Framework（创建模块分类到"模块文档"下，doc子目录作为顶层分类）'), 'info');
                    $this->progress(__('路径: %{path}', ['path' => $frameworkDocPath]), 'info');
                    
                    // 创建 Weline_Framework 模块分类（归属到"模块文档"下）
                    $frameworkModuleCatalog = $this->ensureModuleCatalog('Weline_Framework', $frameworkModulePath);
                    $frameworkModuleCatalogId = (int)$frameworkModuleCatalog->getId();
                    if (!in_array($frameworkModuleCatalogId, $scannedCatalogIds)) {
                        $scannedCatalogIds[] = $frameworkModuleCatalogId;
                    }
                    
                    // 特殊处理：doc下的子目录作为顶层分类，但doc根目录下的文档关联到模块分类
                    $frameworkResult = $this->scanFrameworkDocuments($frameworkDocPath, $frameworkModuleCatalog, $result, $scannedDocumentKeys, $scannedCatalogIds);
                    
                    // 扫描模块根目录下的外部文档（如 README.md 等）
                    $externalResult = $this->scanModuleExternalDocuments('Weline_Framework', $frameworkModulePath, $frameworkModuleCatalog, $result, $scannedDocumentKeys);
                    $frameworkResult['scanned'] += $externalResult['scanned'];
                    $frameworkResult['new'] += $externalResult['new'];
                    $frameworkResult['updated'] += $externalResult['updated'];
                    
                    $result['scanned'] += $frameworkResult['scanned'];
                    $result['new'] += $frameworkResult['new'];
                    $result['updated'] += $frameworkResult['updated'];
                    $result['modules'][] = [
                        'name' => 'Weline_Framework',
                        'scanned' => $frameworkResult['scanned'],
                        'new' => $frameworkResult['new'],
                        'updated' => $frameworkResult['updated']
                    ];
                    
                    $this->progress("   ✓ " . __('扫描完成: 文档 %{scanned} 个, 新增 %{new} 个, 更新 %{updated} 个', [
                        'scanned' => $frameworkResult['scanned'],
                        'new' => $frameworkResult['new'],
                        'updated' => $frameworkResult['updated']
                    ]), 'success');
                }
            }
        }
        
        $totalModulesWithDoc = count($modulesWithDoc);
        $this->progress(__('找到 %{count} 个模块包含 doc 目录，开始扫描...', ['count' => $totalModulesWithDoc]), 'info');
        foreach ($modules as $moduleName => $module) {
            // 跳过 Weline_Framework，它已经特殊处理过了
            if ($moduleName === 'Weline_Framework') {
                continue;
            }
            
            $modulePath = $module['base_path'] ?? '';
            
            if (empty($moduleName) || empty($modulePath) || !($module['status'] ?? false)) {
                continue;
            }
            
            $docPath = rtrim($modulePath, '/\\') . DIRECTORY_SEPARATOR . 'doc';
            
            // 检查doc目录是否存在
            if (!is_dir($docPath)) {
                continue;
            }
            
            $currentModule++;
            $this->progress("", 'info'); // 空行
            $this->progress("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━", 'info');
            $this->progress("📦 " . __('[%{current}/%{total}] 正在扫描模块: %{name}', [
                'current' => $currentModule,
                'total' => $totalModulesWithDoc,
                'name' => $moduleName
            ]), 'info');
            $this->progress("   " . __('路径: %{path}', ['path' => $docPath]), 'info');
            
            // 扫描该模块的文档
            $moduleResult = $this->scanModuleDocuments($moduleName, $docPath, $modulePath, $scannedDocumentKeys, $scannedCatalogIds);
            $result['scanned'] += $moduleResult['scanned'];
            $result['new'] += $moduleResult['new'];
            $result['updated'] += $moduleResult['updated'];
            $result['modules'][] = [
                'name' => $moduleName,
                'scanned' => $moduleResult['scanned'],
                'new' => $moduleResult['new'],
                'updated' => $moduleResult['updated']
            ];
            
            $this->progress("   ✓ " . __('扫描完成: 文档 %{scanned} 个, 新增 %{new} 个, 更新 %{updated} 个', [
                'scanned' => $moduleResult['scanned'],
                'new' => $moduleResult['new'],
                'updated' => $moduleResult['updated']
            ]), 'success');
        }
        
        // 删除不在扫描列表中的自动导入文档（跟随菜单一样的模式）
        $this->progress("", 'info'); // 空行
        $this->progress("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━", 'info');
        $this->progress("🧹 " . __('正在清理不存在的文档和分类...'), 'info');
        
        if (!empty($scannedDocumentKeys)) {
            // 构建查询条件：查找不在扫描列表中的文档
            // 使用 NOT IN 查询，但需要处理组合键的情况
            // 由于数据库不支持直接对组合键使用 NOT IN，我们需要先查询所有自动导入的文档，然后过滤
            
            $this->progress("   " . __('检查自动导入的文档...'), 'info');
            $allAutoImportedDocs = $this->documentModel->clear()
                ->where(Document::fields_IS_AUTO_IMPORTED, 1)
                ->select()
                ->fetchArray();
            
            $docsToDelete = [];
            foreach ($allAutoImportedDocs as $doc) {
                $docKey = $this->buildDocumentKey($doc[Document::fields_MODULE_NAME] ?? '', $doc[Document::fields_FILE_PATH] ?? '');
                if (!in_array($docKey, $scannedDocumentKeys)) {
                    $docsToDelete[] = $doc[Document::fields_ID];
                }
            }
            
            // 删除不在扫描列表中的文档
            if (!empty($docsToDelete)) {
                $deleteCount = count($docsToDelete);
                $this->progress("   " . __('删除 %{count} 个不存在的文档...', ['count' => $deleteCount]), 'warning');
                $deletedCount = $this->documentModel->clear()
                    ->where(Document::fields_ID, $docsToDelete, 'in')
                    ->delete()
                    ->fetch();
                $result['deleted'] = $deleteCount;
                $this->progress("   ✓ " . __('已删除 %{count} 个文档', ['count' => $result['deleted']]), 'success');
            } else {
                $this->progress("   ✓ " . __('无需删除文档'), 'success');
            }
        } else {
            // 如果没有任何扫描到的文档，删除所有自动导入的文档
            $this->progress("   " . __('删除所有自动导入的文档...'), 'warning');
            $this->documentModel->clear()
                ->where(Document::fields_IS_AUTO_IMPORTED, 1)
                ->delete()
                ->fetch();
        }
        
        // 删除不在扫描列表中的系统分类（避免脏数据）
        $this->progress("   " . __('检查系统分类...'), 'info');
        $deletedCatalogs = $this->cleanupOrphanCatalogs($scannedCatalogIds);
        $result['deleted_catalogs'] = $deletedCatalogs;
        if ($deletedCatalogs > 0) {
            $this->progress("   ✓ " . __('已删除 %{count} 个不存在的分类', ['count' => $deletedCatalogs]), 'success');
        } else {
            $this->progress("   ✓ " . __('无需删除分类'), 'success');
        }
        
        // 导入API文档
        try {
            $this->progress("", 'info'); // 空行
            $this->progress("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━", 'info');
            $this->progress(__('📡 开始导入API文档...'), 'info');
            $this->progress("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━", 'info');
            
            /** @var \Weline\DeveloperWorkspace\Service\ApiDocImporter $apiDocImporter */
            $apiDocImporter = ObjectManager::getInstance(\Weline\DeveloperWorkspace\Service\ApiDocImporter::class);
            $apiDocImporter->setProgressCallback(function(string $message, string $type = 'info') {
                $this->progress($message, $type);
            });
            
            $apiResult = $apiDocImporter->importAll($forceRescan);
            
            // 合并API文档导入结果
            $result['scanned'] += $apiResult['scanned'];
            $result['new'] += $apiResult['new'];
            $result['updated'] += $apiResult['updated'];
            
            if (!empty($apiResult['modules'])) {
                foreach ($apiResult['modules'] as $apiModule) {
                    $result['modules'][] = [
                        'name' => $apiModule['name'] . ' (API)',
                        'scanned' => $apiModule['scanned'],
                        'new' => $apiModule['new'],
                        'updated' => $apiModule['updated']
                    ];
                }
            }
            
            $this->progress("   ✓ " . __('API文档导入完成: 扫描 %{scanned}, 新增 %{new}, 更新 %{updated}', [
                'scanned' => $apiResult['scanned'],
                'new' => $apiResult['new'],
                'updated' => $apiResult['updated']
            ]), 'success');
        } catch (\Exception $e) {
            $this->progress("   ⚠️  " . __('API文档导入失败: %{error}', ['error' => $e->getMessage()]), 'warning');
        }
        
        return $result;
    }
    
    /**
     * 扫描单个模块的文档
     * 
     * @param string $moduleName 模块名称
     * @param string $docPath doc目录路径
     * @param string $modulePath 模块根目录路径
     * @param array &$scannedDocumentKeys 收集扫描到的文档唯一标识
     * @param array &$scannedCatalogIds 收集扫描到的分类ID
     * @return array
     */
    public function scanModuleDocuments(string $moduleName, string $docPath, string $modulePath, array &$scannedDocumentKeys, array &$scannedCatalogIds = []): array
    {
        $result = [
            'scanned' => 0,
            'new' => 0,
            'updated' => 0
        ];
        
        // 确保模块有对应的分类目录
        $moduleCatalog = $this->ensureModuleCatalog($moduleName, $modulePath);
        // 记录模块分类ID
        $scannedCatalogIds[] = (int)$moduleCatalog->getId();
        
        // 注意：Weline_Framework 模块已经在 scanAllModules 中特殊处理，不会进入这里
        // 其他模块：doc 目录下的子目录会创建分类（最多支持两层）
        $processedPaths = []; // 初始化已处理路径数组
        $this->scanDirectory($docPath, $moduleName, (int)$moduleCatalog->getId(), $result, '', $scannedDocumentKeys, $scannedCatalogIds, $processedPaths);
        
        // 扫描模块根目录下的外部文档（如 README.md 等）
        $externalResult = $this->scanModuleExternalDocuments($moduleName, $modulePath, $moduleCatalog, $result, $scannedDocumentKeys);
        $result['scanned'] += $externalResult['scanned'];
        $result['new'] += $externalResult['new'];
        $result['updated'] += $externalResult['updated'];
        
        return $result;
    }
    
    /**
     * 扫描模块根目录下的外部文档（如 README.md, CHANGELOG.md 等）
     * 这些文档位于模块根目录，不在 doc 目录下，用于描述模块
     * 
     * @param string $moduleName 模块名称
     * @param string $modulePath 模块根目录路径
     * @param Catalog $moduleCatalog 模块分类（用于关联外部文档）
     * @param array &$result 结果数组（用于累加统计）
     * @param array &$scannedDocumentKeys 收集扫描到的文档唯一标识
     * @return array 返回扫描结果
     */
    private function scanModuleExternalDocuments(string $moduleName, string $modulePath, Catalog $moduleCatalog, array &$result, array &$scannedDocumentKeys): array
    {
        $externalResult = [
            'scanned' => 0,
            'new' => 0,
            'updated' => 0
        ];
        
        if (!is_dir($modulePath)) {
            return $externalResult;
        }
        
        // 扫描模块根目录下的文档文件
        $files = scandir($modulePath);
        if ($files === false) {
            return $externalResult;
        }
        
        $externalFiles = [];
        foreach ($files as $file) {
            if (in_array($file, $this->ignoreDirs)) {
                continue;
            }
            
            $fullPath = $modulePath . DIRECTORY_SEPARATOR . $file;
            // 只处理根目录下的文档文件，不处理子目录
            if (is_file($fullPath) && $this->isDocumentFile($file)) {
                $externalFiles[] = [
                    'path' => $fullPath,
                    'name' => $file,
                    'relative' => $file  // 相对于模块根目录的路径（模块根目录下的文件）
                ];
            }
        }
        
        if (empty($externalFiles)) {
            return $externalResult;
        }
        
        // 对文件进行排序（按数字前缀）
        $externalFiles = $this->sortItemsByPrefix($externalFiles);
        
        $this->progress("   📄 " . __('扫描模块根目录下的外部文档: %{count} 个', ['count' => count($externalFiles)]), 'info');
        
        // 处理每个外部文档文件
        foreach ($externalFiles as $docFile) {
            $this->importDocumentFile($docFile['path'], $docFile['name'], $moduleName, $moduleCatalog, $docFile['relative'], $externalResult, $scannedDocumentKeys);
        }
        
        return $externalResult;
    }
    
    /**
     * 扫描 Weline_Framework 模块的文档（特殊处理）
     * doc 目录下的子目录直接作为顶层分类（PID=0, Level=1，与"模块文档"同级）
     * doc 根目录下的文档文件关联到 Weline_Framework 模块分类
     * 
     * @param string $docPath doc目录路径
     * @param Catalog $moduleCatalog Weline_Framework 模块分类（用于关联doc根目录下的文档）
     * @param array &$result 结果数组（用于累加统计）
     * @param array &$scannedDocumentKeys 收集扫描到的文档唯一标识
     * @param array &$scannedCatalogIds 收集扫描到的分类ID
     * @return array 返回该模块的扫描结果
     */
    private function scanFrameworkDocuments(string $docPath, Catalog $moduleCatalog, array &$result, array &$scannedDocumentKeys, array &$scannedCatalogIds): array
    {
        $moduleResult = [
            'scanned' => 0,
            'new' => 0,
            'updated' => 0
        ];
        
        if (!is_dir($docPath)) {
            return $moduleResult;
        }
        
        $this->progress("   📁 " . __('扫描 Weline_Framework/doc 目录（特殊处理模式：子目录作为顶层分类 PID=0，doc根目录文档关联到模块分类）'), 'info');
        
        // 获取 doc 目录下的所有文件和目录
        $files = scandir($docPath);
        $subDirs = [];
        $docFiles = [];
        $moduleName = 'Weline_Framework';
        
        // 分别收集文件和目录
        foreach ($files as $file) {
            if (in_array($file, $this->ignoreDirs)) {
                continue;
            }
            $fullPath = $docPath . DIRECTORY_SEPARATOR . $file;
            if (is_dir($fullPath)) {
                $subDirs[] = [
                    'path' => $fullPath,
                    'name' => $file,
                    'relative' => $file
                ];
            } elseif (is_file($fullPath) && $this->isDocumentFile($file)) {
                // 构建相对于模块根目录的路径（包含 doc/ 前缀）
                $docFiles[] = [
                    'path' => $fullPath,
                    'name' => $file,
                    'relative' => 'doc/' . $file  // 相对于模块根目录的路径
                ];
            }
        }
        
        // 对文件和目录进行排序（按数字前缀）
        $subDirs = $this->sortItemsByPrefix($subDirs);
        $docFiles = $this->sortItemsByPrefix($docFiles);
        
        // 先处理 doc 根目录下的文档文件（关联到 Weline_Framework 模块分类）
        if (!empty($docFiles)) {
            $this->progress("   📄 " . __('处理 doc 根目录下的 %{count} 个文档文件（关联到 Weline_Framework 模块分类）', ['count' => count($docFiles)]), 'info');
            foreach ($docFiles as $docFile) {
                $this->importDocumentFile($docFile['path'], $docFile['name'], $moduleName, $moduleCatalog, $docFile['relative'], $moduleResult, $scannedDocumentKeys);
            }
        }
        
        $this->progress("   📂 " . __('发现 %{count} 个子目录，将直接作为顶层分类（PID=0, Level=1）', ['count' => count($subDirs)]), 'info');
        
        // 初始化已处理路径数组（用于避免重复处理）
        $processedPaths = [];
        
        // 处理每个子目录：直接作为顶层分类（PID=0）
        foreach ($subDirs as $subDir) {
            // 提取显示名称（去掉数字前缀）
            $sortKey = $this->extractSortKey($subDir['name']);
            $displayName = $sortKey['displayName'];
            
            $this->progress("   📁 " . __('处理顶层分类: %{name}', ['name' => $displayName]), 'info');
            
            // 检查子目录是否包含文档
            if ($this->hasDocumentFiles($subDir['path'])) {
                // 创建顶层分类（PID=0, Level=1）
                $topLevelCatalog = $this->ensureTopLevelCatalog($subDir['name']);
                $topLevelCatalogId = (int)$topLevelCatalog->getId();
                $scannedCatalogIds[] = $topLevelCatalogId;
                
                $this->progress("      ✓ " . __('创建顶层分类: %{name} (ID: %{id}, PID=0, Level=1)', [
                    'name' => $displayName,
                    'id' => $topLevelCatalogId
                ]), 'success');
                
                // 使用正常的扫描逻辑处理子目录下的内容
                $this->scanDirectory($subDir['path'], $moduleName, $topLevelCatalogId, $moduleResult, $subDir['relative'], $scannedDocumentKeys, $scannedCatalogIds, $processedPaths);
            } else {
                // 子目录不包含文档，但可能包含子目录，也需要处理
                // 创建一个临时分类用于处理子目录
                $topLevelCatalog = $this->ensureTopLevelCatalog($subDir['name']);
                $topLevelCatalogId = (int)$topLevelCatalog->getId();
                $scannedCatalogIds[] = $topLevelCatalogId;
                
                $this->progress("      ✓ " . __('创建顶层分类（用于子目录）: %{name} (ID: %{id}, PID=0, Level=1)', [
                    'name' => $displayName,
                    'id' => $topLevelCatalogId
                ]), 'success');
                
                // 继续扫描子目录
                $this->scanDirectory($subDir['path'], $moduleName, $topLevelCatalogId, $moduleResult, $subDir['relative'], $scannedDocumentKeys, $scannedCatalogIds, $processedPaths);
            }
        }
        
        return $moduleResult;
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
     * 对文件/目录列表进行排序（按数字前缀）
     * 
     * @param array $items 文件/目录项数组
     * @return array 排序后的数组
     */
    private function sortItemsByPrefix(array $items): array
    {
        // 为每个项添加排序键
        $itemsWithSort = [];
        foreach ($items as $item) {
            $name = $item['name'] ?? $item;
            $sortKey = $this->extractSortKey($name);
            $itemsWithSort[] = array_merge($item, $sortKey);
        }
        
        // 按 sortOrder 排序
        usort($itemsWithSort, function($a, $b) {
            if ($a['sortOrder'] === $b['sortOrder']) {
                return strcmp($a['originalName'], $b['originalName']);
            }
            return $a['sortOrder'] <=> $b['sortOrder'];
        });
        
        return $itemsWithSort;
    }
    
    /**
     * 确保顶层分类存在（PID=0, Level=1）
     * 
     * @param string $catalogName 分类名称（可能包含数字前缀，如 "2-快速开始"）
     * @return Catalog
     */
    private function ensureTopLevelCatalog(string $catalogName): Catalog
    {
        // 提取显示名称（去掉数字前缀）
        $sortKey = $this->extractSortKey($catalogName);
        $displayName = $sortKey['displayName'];
        
        // 查找已存在的顶层分类（PID=0, Level=1），使用显示名称查找
        $catalog = $this->catalogModel->clear()
            ->where(Catalog::fields_NAME, $displayName)
            ->where(Catalog::fields_PID, 0)
            ->where(Catalog::fields_level, 1)
            ->where(Catalog::fields_is_system, 1)
            ->find()
            ->fetch();
        
        if ($catalog && $catalog->getId()) {
            return $catalog;
        }
        
        // 创建新的顶层分类，使用显示名称（去掉数字前缀）
        $catalog = ObjectManager::getInstance(Catalog::class);
        $catalog->setName($displayName)
            ->setDescription(__('顶层分类 %{name}', ['name' => $displayName]))
            ->setPid(0)
            ->setData(Catalog::fields_level, 1)
            ->setData(Catalog::fields_is_system, 1)
            ->setIsActive(true)
            ->save();
        
        return $catalog;
    }
    
    /**
     * 扫描目录（使用深度优先遍历，确保目录层级正确，避免重复处理）
     * 优化：只为包含文档的目录创建分类，避免空目录产生不必要的层级
     * 
     * @param string $dirPath 目录路径
     * @param string $moduleName 模块名称
     * @param int $parentCatalogId 父分类ID
     * @param array &$result 结果数组（用于累加统计）
     * @param string $relativePath 相对路径（相对于doc目录）
     * @param array &$scannedDocumentKeys 收集扫描到的文档唯一标识（用于去重）
     * @param array &$scannedCatalogIds 收集扫描到的分类ID（用于去重）
     * @param array &$processedPaths 已处理的路径（用于避免重复处理）
     */
    private function scanDirectory(string $dirPath, string $moduleName, int $parentCatalogId, array &$result, string $relativePath = '', array &$scannedDocumentKeys = [], array &$scannedCatalogIds = [], array &$processedPaths = []): void
    {
        if (!is_dir($dirPath)) {
            return;
        }
        
        // 检查目录层级深度（框架规约：doc 目录下最多支持两层目录）
        // relativePath 为空：层级 0（doc 根目录）
        // relativePath 不包含 '/'：层级 1（第一层子目录）
        // relativePath 包含 1 个 '/'：层级 2（第二层子目录）
        // relativePath 包含 2 个或更多 '/'：层级 3+（超过限制）
        $depth = 0;
        if (!empty($relativePath)) {
            $depth = substr_count($relativePath, '/') + 1;
        }
        
        // 如果层级超过 2 层，抛出致命错误
        if ($depth > 2) {
            $errorMessage = sprintf(
                "致命错误：目录层级超过框架规约限制！\n" .
                "框架规约：doc 目录下最多支持两层目录（doc/第一层/第二层）。\n" .
                "当前目录路径：%s\n" .
                "相对路径：%s\n" .
                "当前层级：%d（超过限制：最多 2 层）\n" .
                "请调整目录结构，将文档移动到符合规约的目录层级中。",
                $dirPath,
                $relativePath,
                $depth
            );
            throw new \Exception($errorMessage);
        }
        
        // 检查是否已经处理过这个目录（避免重复处理）
        $normalizedPath = realpath($dirPath);
        if ($normalizedPath === false) {
            $normalizedPath = $dirPath;
        }
        
        if (isset($processedPaths[$normalizedPath])) {
            return; // 已经处理过，直接返回
        }
        
        // 标记为已处理（在处理前标记，避免重复处理）
        $processedPaths[$normalizedPath] = true;
        
        // 加载父分类，确保获取最新的数据
        $parentCatalogId = (int)$parentCatalogId;
        $parentCatalog = $this->catalogModel->clear()->load($parentCatalogId);
        if (!$parentCatalog || !$parentCatalog->getId()) {
            throw new \Exception("父分类不存在: ID {$parentCatalogId}，目录: {$relativePath}");
        }
        
        // 显示当前扫描的目录（只在有相对路径时显示，避免重复显示根目录）
        if ($relativePath) {
            $this->progress("   📁 " . __('扫描目录: %{path}', ['path' => $relativePath]), 'info');
        }
        
        // 读取目录内容
        $files = scandir($dirPath);
        if ($files === false) {
            return;
        }
        
        $dirName = basename($dirPath);
        $isRootDir = empty($relativePath); // 根目录（doc目录）的relativePath为空
        
        // 分离文档文件和子目录
        $docFiles = [];
        $subDirs = [];
        
        foreach ($files as $file) {
            if (in_array($file, $this->ignoreDirs)) {
                continue;
            }
            
            $fullPath = $dirPath . DIRECTORY_SEPARATOR . $file;
            $currentRelativePath = $relativePath ? $relativePath . '/' . $file : $file;
            
            if (is_file($fullPath) && $this->isDocumentFile($file)) {
                // 构建相对于模块根目录的路径（包含 doc/ 前缀）
                $moduleRelativePath = 'doc/' . $currentRelativePath;
                $docFiles[] = [
                    'path' => $fullPath,
                    'name' => $file,
                    'relative' => $moduleRelativePath  // 相对于模块根目录的路径
                ];
            } elseif (is_dir($fullPath)) {
                $subDirs[] = [
                    'path' => $fullPath,
                    'name' => $file,
                    'relative' => $currentRelativePath
                ];
            }
        }
        
        // 对文件和目录进行排序（按数字前缀）
        $docFiles = $this->sortItemsByPrefix($docFiles);
        $subDirs = $this->sortItemsByPrefix($subDirs);
        
        // 确定当前目录使用的分类
        // 规则：
        // 1. 如果当前目录是根目录（doc目录），直接使用父分类（模块分类）
        // 2. 如果当前目录是 doc 根目录下的第一层子目录（depth=1），总是创建分类
        // 3. 如果当前目录是第二层子目录（depth=2），且包含文档文件或子目录，创建分类
        // 4. 其他情况使用父分类
        $currentDirCatalog = null;
        $currentDirCatalogId = $parentCatalogId;
        
        // 计算当前目录的层级深度
        $currentDepth = 0;
        if (!empty($relativePath)) {
            $currentDepth = substr_count($relativePath, '/') + 1;
        }
        
        // 判断是否需要创建分类
        $shouldCreateCatalog = false;
        if ($isRootDir) {
            // 根目录不创建分类，使用父分类（模块分类）
            $shouldCreateCatalog = false;
        } elseif ($currentDepth === 1) {
            // doc 根目录下的第一层子目录
            // 如果父分类是顶层分类（PID=0），说明已经在 scanModuleDocuments 中创建过了，不需要再创建
            // 如果父分类不是顶层分类，才需要创建分类
            $parentPid = (int)($parentCatalog->getPid() ?? -1);
            if ($parentPid === 0) {
                // 父分类是顶层分类（PID=0），已经在 scanModuleDocuments 中创建过了，直接使用父分类
                $shouldCreateCatalog = false;
            } else {
                // 父分类不是顶层分类，需要创建分类
                $shouldCreateCatalog = true;
            }
        } elseif ($currentDepth === 2) {
            // 第二层子目录，如果包含文档或子目录，创建分类
            $shouldCreateCatalog = !empty($docFiles) || !empty($subDirs);
        }
        
        if ($shouldCreateCatalog) {
            // 需要创建或获取当前目录的分类
            $this->progress("      📂 " . __('创建分类: %{name} (深度: %{depth})', [
                'name' => $dirName,
                'depth' => $currentDepth
            ]), 'info');
            
            $currentDirCatalog = $this->ensureCatalogByPath($dirName, $parentCatalog);
            $currentDirCatalogId = (int)$currentDirCatalog->getId();
            
            // 确保分类ID被记录（去重）
            if (!in_array($currentDirCatalogId, $scannedCatalogIds)) {
                $scannedCatalogIds[] = $currentDirCatalogId;
            }
            
            // 重新加载当前目录的分类对象，确保获取最新的数据
            $currentDirCatalog = $this->catalogModel->clear()->load($currentDirCatalogId);
            if (!$currentDirCatalog || !$currentDirCatalog->getId()) {
                throw new \Exception("创建分类后无法加载: ID {$currentDirCatalogId}，目录: {$relativePath}");
            }
            
            $this->progress("      ✓ " . __('分类已创建/获取: %{name} (ID: %{id})', [
                'name' => $dirName,
                'id' => $currentDirCatalogId
            ]), 'success');
        }
        
        // 处理当前目录下的文档文件
        // 使用当前目录的分类（如果存在）或父分类
        $docCatalog = $currentDirCatalog ?: $parentCatalog;
        foreach ($docFiles as $docFile) {
            // 检查文档是否已经处理过（避免重复导入）
            $documentKey = $this->buildDocumentKey($moduleName, $docFile['relative']);
            if (in_array($documentKey, $scannedDocumentKeys)) {
                continue; // 已经处理过，跳过
            }
            
            $this->importDocumentFile($docFile['path'], $docFile['name'], $moduleName, $docCatalog, $docFile['relative'], $result, $scannedDocumentKeys);
        }
        
        // 处理子目录（递归处理）
        // 所有子目录都应该创建分类（scanDirectory 方法会根据是否包含文档或子目录来决定）
        // 子目录应该使用当前目录的分类ID（如果存在）或父分类ID
        foreach ($subDirs as $subDir) {
            try {
                // 使用当前目录的分类（如果存在）或父分类作为子目录的父分类
                $subDirParent = $this->catalogModel->clear()->load($currentDirCatalogId);
                if (!$subDirParent || !$subDirParent->getId()) {
                    $this->progress("      ⚠️  " . __('父分类不存在: ID %{id}，目录: %{path}，跳过', [
                        'id' => $currentDirCatalogId,
                        'path' => $subDir['relative']
                    ]), 'warning');
                    continue; // 跳过这个子目录，继续处理下一个
                }
                
                // 递归处理子目录（scanDirectory 方法会自动创建分类）
                // 对于第一层子目录（depth=1），总是创建分类
                // 对于第二层子目录（depth=2），如果包含文档或子目录，创建分类
                $this->scanDirectory($subDir['path'], $moduleName, $currentDirCatalogId, $result, $subDir['relative'], $scannedDocumentKeys, $scannedCatalogIds, $processedPaths);
            } catch (\Exception $e) {
                // 如果处理子目录时出错，记录错误但继续处理其他子目录
                $this->progress("      ❌ " . __('处理子目录失败: %{path}，错误: %{error}，继续处理其他目录', [
                    'path' => $subDir['relative'],
                    'error' => $e->getMessage()
                ]), 'error');
                // 继续处理下一个子目录，不中断整个扫描过程
            }
        }
    }
    
    /**
     * 检查目录是否包含文档文件（递归检查子目录）
     * 注意：受框架规约限制，最多递归检查 1 层（只检查当前目录和直接子目录）
     * 
     * @param string $dirPath 目录路径
     * @param int $currentDepth 当前递归深度（内部使用，外部调用时不需要传递）
     * @return bool
     */
    private function hasDocumentFiles(string $dirPath, int $currentDepth = 0): bool
    {
        if (!is_dir($dirPath)) {
            return false;
        }
        
        // 框架规约：doc 目录下最多支持两层目录
        // hasDocumentFiles 用于检查子目录是否包含文档，最多递归 1 层
        // 如果当前深度超过 1 层，不再递归检查（避免检查超过框架规约限制的层级）
        if ($currentDepth > 1) {
            return false;
        }
        
        $files = scandir($dirPath);
        foreach ($files as $file) {
            if (in_array($file, $this->ignoreDirs)) {
                continue;
            }
            
            $fullPath = $dirPath . DIRECTORY_SEPARATOR . $file;
            
            if (is_file($fullPath) && $this->isDocumentFile($file)) {
                return true;
            }
            
            if (is_dir($fullPath)) {
                // 递归检查子目录，深度加 1
                if ($this->hasDocumentFiles($fullPath, $currentDepth + 1)) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * 判断是否是文档文件
     */
    private function isDocumentFile(string $fileName): bool
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        return in_array($extension, ['md', 'markdown', 'txt']);
    }
    
    /**
     * 读取文件内容并转换为UTF-8编码
     * 
     * @param string $filePath 文件路径
     * @return string|false UTF-8编码的文件内容，失败返回false
     */
    private function readFileAsUtf8(string $filePath): string|false
    {
        // 读取文件内容（二进制模式）
        $content = file_get_contents($filePath);
        if ($content === false) {
            return false;
        }
        
        // 如果文件为空，直接返回
        if (empty($content)) {
            return '';
        }
        
        // 首先检查是否已经是有效的UTF-8编码
        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }
        
        // 检测文件编码（按常见编码顺序检测）
        $detectEncodings = ['UTF-8', 'GBK', 'GB2312', 'BIG5', 'ISO-8859-1', 'Windows-1252', 'ASCII'];
        $encoding = mb_detect_encoding($content, $detectEncodings, true);
        
        // 如果检测不到编码，尝试常见的中文编码
        if ($encoding === false || empty($encoding)) {
            // 优先尝试GBK（中文Windows常用编码）
            $encoding = 'GBK';
        }
        
        // 确保编码名称是字符串
        $encoding = (string)$encoding;
        
        // 如果检测到的编码是UTF-8，但之前检查失败，可能是误检，尝试转换
        if (strtoupper($encoding) === 'UTF-8') {
            // 再次验证，如果确实不是UTF-8，尝试其他编码
            if (!mb_check_encoding($content, 'UTF-8')) {
                $encoding = 'GBK'; // 默认使用GBK
            } else {
                return $content;
            }
        }
        
        // 转换为UTF-8
        $utf8Content = mb_convert_encoding($content, 'UTF-8', $encoding);
        
        // 验证转换结果
        if ($utf8Content === false || !mb_check_encoding($utf8Content ?? '', 'UTF-8')) {
            // 转换失败，尝试其他常见编码
            $fallbackEncodings = ['GBK', 'GB2312', 'BIG5', 'ISO-8859-1', 'Windows-1252', 'ASCII'];
            foreach ($fallbackEncodings as $enc) {
                if ($enc === $encoding) {
                    continue; // 跳过已尝试的编码
                }
                $testContent = @mb_convert_encoding($content, 'UTF-8', $enc);
                if ($testContent !== false && mb_check_encoding($testContent, 'UTF-8')) {
                    return $testContent;
                }
            }
            
            // 所有转换都失败，使用IGNORE模式强制转换为UTF-8（会忽略无效字符）
            $utf8Content = mb_convert_encoding($content, 'UTF-8', 'UTF-8//IGNORE');
            if ($utf8Content === false) {
                // 最后的备选方案：尝试从GBK转换
                $utf8Content = mb_convert_encoding($content, 'UTF-8', 'GBK//IGNORE');
            }
        }
        
        // 确保返回的是有效的UTF-8字符串
        if ($utf8Content === false || !mb_check_encoding($utf8Content ?? '', 'UTF-8')) {
            // 如果还是失败，返回原始内容（虽然可能不是UTF-8，但至少不会丢失数据）
            return $content;
        }
        
        return $utf8Content;
    }
    
    /**
     * 导入文档文件
     * 注意：自动导入的文档不保存内容，只保存路径和元数据，内容从文件系统实时读取
     * 
     * @param string $relativePath 相对于模块根目录的路径（如：doc/event/维护模式.md 或 extends.md）
     */
    private function importDocumentFile(string $filePath, string $fileName, string $moduleName, Catalog $catalog, string $relativePath, array &$result, array &$scannedDocumentKeys): void
    {
        $result['scanned']++;
        
        // 读取文件内容并转换为UTF-8编码（仅用于提取标题和摘要）
        $content = $this->readFileAsUtf8($filePath);
        if ($content === false) {
            $this->progress("      ⚠️  " . __('无法读取文件: %{path}', ['path' => $relativePath]), 'warning');
            return;
        }
        
        // 提取标题和摘要
        $title = $this->extractTitle($content, $fileName);
        $summary = $this->extractSummary($content);
        
        // 构建文档唯一标识（用于后续删除不在列表中的文档）
        $documentKey = $this->buildDocumentKey($moduleName, $relativePath);
        $scannedDocumentKeys[] = $documentKey;
        
        // 检查是否已存在（基于模块名+文件路径）
        $existingDoc = $this->documentModel->clear()
            ->where(Document::fields_MODULE_NAME, $moduleName)
            ->where(Document::fields_FILE_PATH, $relativePath)
            ->where(Document::fields_IS_AUTO_IMPORTED, 1)
            ->find()
            ->fetch();
        
        if ($existingDoc && $existingDoc->getId()) {
            // 更新现有文档（不更新内容，内容从文件系统实时读取）
            $existingDoc->setTitle($title)
                ->setSummary($summary)
                ->setFilePath($relativePath)  // 确保更新时也保存相对路径
                ->setFileName($fileName)
                ->setCategoryId((string)$catalog->getId())
                ->save();
            $result['updated']++;
            $this->progress("      ↻ " . __('更新: %{path}', ['path' => $relativePath]), 'info');
        } else {
            // 创建新文档（不保存内容，内容从文件系统实时读取）
            $newDoc = ObjectManager::getInstance(Document::class);
            $newDoc->setTitle($title)
                ->setSummary($summary)
                ->setContent('')  // 自动导入的文档不保存内容，内容从文件系统实时读取
                ->setModuleName($moduleName)
                ->setFilePath($relativePath)  // 保存相对路径（相对于模块根目录，如：doc/event/维护模式.md）
                ->setFileName($fileName)
                ->setCategoryId((string)$catalog->getId())
                ->setIsAutoImported(true)
                ->setSortOrder(0)
                ->save();
            $result['new']++;
            $this->progress("      ✨ " . __('新增: %{path}', ['path' => $relativePath]), 'success');
        }
    }
    
    /**
     * 构建文档唯一标识（module_name + file_path）
     */
    private function buildDocumentKey(string $moduleName, string $filePath): string
    {
        return $moduleName . '|' . $filePath;
    }
    
    /**
     * 从内容中提取标题
     */
    private function extractTitle(string $content, string $fallbackTitle): string
    {
        // 尝试从 Markdown 的第一个 # 标题提取
        if (preg_match('/^#\s+(.+)$/m', $content, $matches)) {
            return trim($matches[1]);
        }
        
        // 如果没有找到，使用文件名（去掉扩展名）
        return pathinfo($fallbackTitle, PATHINFO_FILENAME);
    }
    
    /**
     * 从内容中提取摘要（前200字符）
     */
    private function extractSummary(string $content): string
    {
        // 移除 Markdown 标记
        $text = preg_replace('/^#+\s+/m', '', $content); // 移除标题
        $text = preg_replace('/[*_~`]/', '', $text); // 移除格式化标记
        $text = preg_replace('/\[([^\]]+)\]\([^\)]+\)/', '$1', $text); // 移除链接
        $text = trim($text);
        
        // 截取前200个字符
        if (mb_strlen($text) > 200) {
            return mb_substr($text, 0, 200) . '...';
        }
        
        return $text;
    }
    
    /**
     * 确保"模块文档"顶层分类存在
     */
    private function ensureModuleDocumentCatalog(): Catalog
    {
        $topCatalogName = '模块文档';
        
        // 查找"模块文档"顶层分类（pid=0, level=0）
        $catalog = $this->catalogModel->clear()
            ->where(Catalog::fields_NAME, $topCatalogName)
            ->where(Catalog::fields_PID, 0)
            ->where(Catalog::fields_level, 0)
            ->find()
            ->fetch();
        
        if (!$catalog || !$catalog->getId()) {
            // 不存在则创建新分类
            try {
                $catalog = ObjectManager::getInstance(Catalog::class);
                $catalog->setName($topCatalogName)
                    ->setDescription('所有模块的开发文档')
                    ->setPid(0)
                    ->setData(Catalog::fields_level, 0)
                    ->setData(Catalog::fields_is_system, 1)
                    ->setData(Catalog::fields_position, 999999)  // 排序放最后
                    ->setIsActive(true)
                    ->save();
                $this->progress("   " . __('创建顶层分类: %{name}', ['name' => $topCatalogName]), 'info');
            } catch (\Exception $e) {
                // 如果保存失败（可能是并发创建），再次查询
                $catalog = $this->catalogModel->clear()
                    ->where(Catalog::fields_NAME, $topCatalogName)
                    ->where(Catalog::fields_PID, 0)
                    ->where(Catalog::fields_level, 0)
                    ->find()
                    ->fetch();
                
                // 如果还是没有，抛出异常
                if (!$catalog || !$catalog->getId()) {
                    throw $e;
                }
            }
        } else {
            // 如果分类已存在，验证并修复层级（确保 pid=0, level=0）和排序（确保 position=999999）
            $catalogLevel = (int)($catalog->getData(Catalog::fields_level) ?? 0);
            $catalogPid = (int)$catalog->getPid();
            $catalogPosition = (int)($catalog->getData(Catalog::fields_position) ?? 0);
            $needsUpdate = false;
            $updateData = [];
            
            if ($catalogLevel !== 0 || $catalogPid !== 0) {
                $updateData[Catalog::fields_level] = 0;
                $updateData[Catalog::fields_PID] = 0;
                $needsUpdate = true;
            }
            
            if ($catalogPosition !== 999999) {
                $updateData[Catalog::fields_position] = 999999;
                $needsUpdate = true;
            }
            
            if ($needsUpdate) {
                // 层级、父ID或排序不正确，修复
                $this->catalogModel->clear()
                    ->where(Catalog::fields_ID, $catalog->getId())
                    ->update($updateData)
                    ->fetch();
                // 重新加载
                $catalog = $this->catalogModel->clear()->load($catalog->getId());
            }
        }
        
        return $catalog;
    }
    
    /**
     * 从模块的 register.php 文件中提取描述信息
     */
    private function getModuleDescriptionFromRegister(string $modulePath): string
    {
        $registerFile = rtrim($modulePath, '/\\') . DIRECTORY_SEPARATOR . 'register.php';
        
        if (!file_exists($registerFile)) {
            return '';
        }
        
        try {
            $registerArgs = Register::parserRegisterFunctionParams($registerFile);
            if (isset($registerArgs['description'])) {
                $description = $registerArgs['description'];
                
                // 如果是数组，转换为字符串
                if (is_array($description)) {
                    $description = implode(' ', $description);
                }
                
                // 确保是字符串类型
                if (!is_string($description)) {
                    return '';
                }
                
                // 移除引号
                $description = trim($description ?? '', '\'"');
                
                // 移除HTML标签（如果描述包含HTML）
                $description = strip_tags($description);
                
                return $description;
            }
        } catch (\Exception $e) {
            // 解析失败时忽略
        }
        
        return '';
    }
    
    /**
     * 确保模块有对应的分类
     */
    private function ensureModuleCatalog(string $moduleName, string $modulePath = ''): Catalog
    {
        // 先确保"模块文档"顶层分类存在
        $topCatalog = $this->ensureModuleDocumentCatalog();
        $topCatalogId = (int)$topCatalog->getId();
        
        // 从 register.php 提取模块描述
        $moduleDescription = $this->getModuleDescriptionFromRegister($modulePath);
        if (empty($moduleDescription)) {
            $moduleDescription = '模块 ' . $moduleName . ' 的开发文档';
        }
        
        // 查找已存在的分类（先按名称和父ID查找，不限制层级，因为层级可能错误）
        $catalog = $this->catalogModel->clear()
            ->where(Catalog::fields_NAME, $moduleName)
            ->where(Catalog::fields_PID, $topCatalogId)
            ->find()
            ->fetch();
        
        if ($catalog && $catalog->getId()) {
            // 如果分类已存在，验证并修复层级和父ID
            // 注意：如果"模块文档"的 level=0，那么模块分类的 level 应该是 1
            $catalogLevel = (int)($catalog->getData(Catalog::fields_level) ?? 0);
            $catalogPid = (int)$catalog->getPid();
            if ($catalogLevel !== 1 || $catalogPid !== $topCatalogId) {
                // 层级或父ID不正确，强制修复
                $this->catalogModel->clear()
                    ->where(Catalog::fields_ID, $catalog->getId())
                    ->update([
                        Catalog::fields_level => 1,
                        Catalog::fields_PID => $topCatalogId
                    ])
                    ->fetch();
                // 重新加载
                $catalog = $this->catalogModel->clear()->load($catalog->getId());
                // 再次验证
                $catalogLevel = (int)($catalog->getData(Catalog::fields_level) ?? 0);
                $catalogPid = (int)$catalog->getPid();
                if ($catalogLevel !== 1 || $catalogPid !== $topCatalogId) {
                    throw new \Exception("无法修复模块分类层级: {$moduleName} (level: {$catalogLevel} != 1, pid: {$catalogPid} != {$topCatalogId})");
                }
            }
            // 更新描述（从 register.php 提取的）
            if (!empty($moduleDescription) && $catalog->getDescription() !== $moduleDescription) {
                $catalog->setDescription($moduleDescription)->save();
            }
        } else {
            // 如果不存在，创建新分类
            // 注意：如果"模块文档"的 level=0，那么模块分类的 level 应该是 1
            try {
                $catalog = ObjectManager::getInstance(Catalog::class);
                $catalog->setName($moduleName)
                    ->setDescription($moduleDescription)
                    ->setPid($topCatalogId)
                    ->setData(Catalog::fields_level, 1)
                    ->setData(Catalog::fields_is_system, 1)
                    ->setIsActive(true)
                    ->save();
            } catch (\Exception $e) {
                // 如果保存失败（可能是并发创建导致唯一索引冲突），再次查询
                $catalog = $this->catalogModel->clear()
                    ->where(Catalog::fields_NAME, $moduleName)
                    ->where(Catalog::fields_PID, $topCatalogId)
                    ->where(Catalog::fields_level, 1)
                    ->find()
                    ->fetch();
                
                // 如果还是没有，抛出异常
                if (!$catalog || !$catalog->getId()) {
                    throw $e;
                }
            }
        }
        
        return $catalog;
    }
    /**
     * 根据目录路径确保或创建分类
     * 严格按照目录的路径分层，允许分类名重名（不同层级可以重名），但同一个level层级不允许重名
     * 
     * 重要：此方法必须确保分类的父分类ID和层级完全正确，避免分类归属错误
     * 
     * @param string $dirName 目录名称
     * @param Catalog $parentCatalog 父分类（必须正确，不能是错误层级的分类）
     * @return Catalog
     */
    /**
     * 计算分类的正确层级（根据父ID递归计算，不修改数据库）
     */
    private function calculateCorrectLevel(int $catalogId, array &$visited = []): int
    {
        // 防止循环引用
        if (in_array($catalogId, $visited)) {
            return 0;
        }
        $visited[] = $catalogId;
        
        $catalog = $this->catalogModel->clear()->load($catalogId);
        if (!$catalog || !$catalog->getId()) {
            return 0;
        }
        
        $pid = (int)$catalog->getPid();
        if ($pid === 0) {
            // 顶层分类，可能是 level=0（模块文档）或 level=1（其他顶层分类）
            // 直接返回当前分类的层级
            $currentLevel = (int)($catalog->getData(Catalog::fields_level) ?? 0);
            return $currentLevel >= 0 ? $currentLevel : 1;  // 如果层级异常，默认返回 1
        }
        
        // 先检查父分类是否存在
        $parent = $this->catalogModel->clear()->load($pid);
        if (!$parent || !$parent->getId()) {
            // 父分类不存在，返回0
            return 0;
        }
        
        // 递归计算父分类的层级
        $parentLevel = $this->calculateCorrectLevel($pid, $visited);
        if ($parentLevel < 0) {
            // 如果递归计算失败，尝试直接读取父分类的层级（可能是刚创建的，层级是正确的）
            $parentLevel = (int)($parent->getData(Catalog::fields_level) ?? 0);
            if ($parentLevel >= 0 && $parentLevel <= 5) {
                // 使用父分类的层级
            } else {
                return 0;
            }
        }
        
        // 当前分类的层级 = 父分类的层级 + 1
        return $parentLevel + 1;
    }
    
    /**
     * 根据父ID计算正确的层级（递归向上查找）
     * 返回的是父分类的层级，不是子分类的层级
     * @deprecated 使用 calculateCorrectLevel 代替
     */
    private function calculateLevelFromPid(int $pid, array &$visited = []): int
    {
        // 防止循环引用
        if (in_array($pid, $visited)) {
            return 0; // 检测到循环
        }
        $visited[] = $pid;
        
        if ($pid === 0) {
            // 顶层分类，可能是 level=0（模块文档）或 level=1（其他顶层分类）
            // 这里无法确定具体层级，返回 1 作为默认值（兼容旧逻辑）
            return 1;
        }
        
        $parent = $this->catalogModel->clear()->load($pid);
        if (!$parent || !$parent->getId()) {
            return 0; // 父分类不存在
        }
        
        $parentLevel = (int)($parent->getData(Catalog::fields_level) ?? 0);
        if ($parentLevel >= 0 && $parentLevel <= 5) {
            return $parentLevel; // 返回父分类的层级
        }
        
        // 如果父分类的层级异常，递归计算
        $grandParentPid = (int)$parent->getPid();
        $grandParentLevel = $this->calculateLevelFromPid($grandParentPid, $visited);
        if ($grandParentLevel > 0) {
            $correctLevel = $grandParentLevel + 1; // 父分类的层级 = 祖父分类的层级 + 1
            // 修复父分类的层级
            $this->catalogModel->clear()
                ->where(Catalog::fields_ID, $pid)
                ->update([Catalog::fields_level => $correctLevel])
                ->fetch();
            return $correctLevel; // 返回修复后的父分类层级
        }
        
        return 0;
    }
    
    /**
     * 修复分类的层级（递归修复整个分类链）
     * 注意：只修复层级，不改变其他属性，避免唯一索引冲突
     */
    private function fixCatalogLevel(int $catalogId, array &$visited = []): int
    {
        // 防止循环引用
        if (in_array($catalogId, $visited)) {
            return 0; // 检测到循环，返回0
        }
        $visited[] = $catalogId;
        
        $catalog = $this->catalogModel->clear()->load($catalogId);
        if (!$catalog || !$catalog->getId()) {
            return 0;
        }
        
        $pid = (int)$catalog->getPid();
        if ($pid === 0) {
            // 顶层分类，可能是 level=0（模块文档）或 level=1（其他顶层分类）
            // 直接使用当前分类的层级，如果异常则默认为 1
            $currentLevel = (int)($catalog->getData(Catalog::fields_level) ?? 0);
            $correctLevel = ($currentLevel >= 0 && $currentLevel <= 1) ? $currentLevel : 1;
        } else {
            // 先修复父分类的层级
            $parentLevel = $this->fixCatalogLevel($pid, $visited);
            if ($parentLevel === 0) {
                return 0; // 修复失败
            }
            $correctLevel = $parentLevel + 1;
        }
        
        // 检查当前分类的层级是否正确
        $currentLevel = (int)($catalog->getData(Catalog::fields_level) ?? 0);
        if ($currentLevel !== $correctLevel) {
            // 检查目标层级是否已经存在相同名称和父ID的分类（避免唯一索引冲突）
            $name = $catalog->getName() ?? '';
            $existingCatalog = $this->catalogModel->clear()
                ->where(Catalog::fields_NAME, $name)
                ->where(Catalog::fields_PID, $pid)
                ->where(Catalog::fields_level, $correctLevel)
                ->where(Catalog::fields_ID, $catalogId, '!=')
                ->find()
                ->fetch();
            
            if ($existingCatalog && $existingCatalog->getId()) {
                // 如果目标层级已存在相同分类，说明数据有问题
                // 检查哪个分类是正确的（通过ID判断，ID小的通常是先创建的，更可能是正确的）
                $existingId = (int)$existingCatalog->getId();
                if ($existingId < $catalogId) {
                    // 已存在的分类ID更小，删除当前错误的分类
                    $catalog->delete()->fetch();
                    return 0; // 返回0表示需要重新创建
                } else {
                    // 当前分类ID更小，删除已存在的错误分类
                    $existingCatalog->delete()->fetch();
                    // 继续修复当前分类的层级
                }
            }
            
            // 直接更新层级字段，避免触发唯一索引检查
            $this->catalogModel->clear()
                ->where(Catalog::fields_ID, $catalogId)
                ->update([Catalog::fields_level => $correctLevel])
                ->fetch();
        }
        
        return $correctLevel;
    }
    
    private function ensureCatalogByPath(string $dirName, Catalog $parentCatalog): Catalog
    {
        // 提取显示名称（去掉数字前缀）
        $sortKey = $this->extractSortKey($dirName);
        $displayName = $sortKey['displayName'];
        
        // 始终重新加载父分类，确保获取最新的层级数据
        $parentId = (int)$parentCatalog->getId();
        $parentCatalog = $this->catalogModel->clear()->load($parentId);
        if (!$parentCatalog || !$parentCatalog->getId()) {
            throw new \Exception("父分类不存在: ID {$parentId}，分类名: {$displayName}");
        }
        
        // 计算父分类的正确层级（根据父分类的父ID递归计算）
        $visited = [];
        $parentLevel = $this->calculateCorrectLevel($parentId, $visited);
        
        // 如果计算失败，尝试直接读取父分类的层级（可能是刚创建的，层级是正确的）
        if ($parentLevel < 0 || $parentLevel > 5) {
            $currentParentLevel = (int)($parentCatalog->getData(Catalog::fields_level) ?? 0);
            if ($currentParentLevel >= 0 && $currentParentLevel <= 5) {
                // 使用当前层级，但需要验证是否正确
                $parentPid = (int)$parentCatalog->getPid();
                if ($parentPid === 0) {
                    // 顶层分类，可能是 level=0（模块文档）或 level=1（其他顶层分类）
                    // 直接使用当前层级
                    $parentLevel = $currentParentLevel;
                } else {
                    // 根据父分类的父ID计算
                    $grandParent = $this->catalogModel->clear()->load($parentPid);
                    if ($grandParent && $grandParent->getId()) {
                        $grandParentLevel = (int)($grandParent->getData(Catalog::fields_level) ?? 0);
                        if ($grandParentLevel >= 0 && $grandParentLevel <= 4) {
                            $parentLevel = $grandParentLevel + 1;
                        } else {
                            // 如果祖父分类的层级异常，尝试递归计算
                            $grandParentVisited = [];
                            $calculatedGrandParentLevel = $this->calculateCorrectLevel($parentPid, $grandParentVisited);
                            if ($calculatedGrandParentLevel >= 0 && $calculatedGrandParentLevel <= 4) {
                                $parentLevel = $calculatedGrandParentLevel + 1;
                            } else {
                                $parentLevel = $currentParentLevel; // 使用当前层级
                            }
                        }
                    } else {
                        $parentLevel = $currentParentLevel; // 使用当前层级
                    }
                }
            } else {
                // 如果当前层级也异常，尝试根据父ID计算
                $parentPid = (int)$parentCatalog->getPid();
                if ($parentPid === 0) {
                    // 顶层分类，可能是 level=0（模块文档）或 level=1（其他顶层分类）
                    // 直接使用当前层级，如果异常则默认为 1
                    $parentLevel = ($currentParentLevel >= 0 && $currentParentLevel <= 1) ? $currentParentLevel : 1;
                } else {
                    // 递归计算父分类的层级
                    $grandParentVisited = [];
                    $calculatedParentLevel = $this->calculateCorrectLevel($parentPid, $grandParentVisited);
                    if ($calculatedParentLevel >= 0 && $calculatedParentLevel <= 4) {
                        $parentLevel = $calculatedParentLevel + 1;
                    } else {
                        throw new \Exception("父分类层级异常: ID {$parentId}，分类名: {$displayName}，计算层级: {$parentLevel}，当前层级: {$currentParentLevel}");
                    }
                }
            }
        }
        
        // 如果父分类的层级不正确，更新它
        $currentParentLevel = (int)($parentCatalog->getData(Catalog::fields_level) ?? 0);
        if ($currentParentLevel !== $parentLevel) {
            $this->catalogModel->clear()
                ->where(Catalog::fields_ID, $parentId)
                ->update([Catalog::fields_level => $parentLevel])
                ->fetch();
            // 重新加载
            $parentCatalog = $this->catalogModel->clear()->load($parentId);
        }
        
        // 计算当前分类的层级
        $currentLevel = $parentLevel + 1;
        
        // 验证层级是否合理
        if ($currentLevel > 6) {
            throw new \Exception("分类层级超出范围: 父分类层级 {$parentLevel}，当前分类层级 {$currentLevel}，分类名: {$displayName}");
        }
        
        // 查找已存在的分类
        // 重要：同一父分类下不允许同名分类，所以先按名称和父ID查找（不检查层级）
        // 使用显示名称（去掉数字前缀）进行查找
        $catalog = $this->catalogModel->clear()
            ->where(Catalog::fields_NAME, $displayName)
            ->where(Catalog::fields_is_system, 1)
            ->where(Catalog::fields_PID, $parentId)
            ->find()
            ->fetch();
        
        // 如果找到了分类，直接返回（同一父分类下不允许同名）
        if ($catalog && $catalog->getId()) {
            $catalogPid = (int)$catalog->getPid();
            $catalogLevel = (int)($catalog->getData(Catalog::fields_level) ?? 0);
            
            // 验证：父ID必须匹配（同一父分类下不允许同名）
            if ($catalogPid === $parentId) {
                // 父ID匹配，直接返回已存在的分类（即使层级不同，也不创建新的）
                // 如果层级不同，更新层级
                if ($catalogLevel !== $currentLevel) {
                    $this->progress("      ⚠️  " . __('分类层级不一致: %{name} (当前层级: %{current} != 计算层级: %{calculated})，将更新层级', [
                        'name' => $displayName,
                        'current' => $catalogLevel,
                        'calculated' => $currentLevel
                    ]), 'warning');
                    $this->catalogModel->clear()
                        ->where(Catalog::fields_ID, $catalog->getId())
                        ->update([Catalog::fields_level => $currentLevel])
                        ->fetch();
                    // 重新加载
                    $catalog = $this->catalogModel->clear()->load($catalog->getId());
                }
                return $catalog;
            }
        }
        
        // 如果没找到，检查是否有同名但父ID不同的分类
        // 注意：不删除已存在的分类，只创建新的分类（如果不存在）
        // 但是，如果发现同名但父ID不同的分类，说明可能是重复处理导致的
        // 在这种情况下，我们需要检查是否应该使用已存在的分类
        if (!$catalog || !$catalog->getId()) {
            // 查找所有同名且是系统分类的分类（使用显示名称）
            $existingCatalogs = $this->catalogModel->clear()
                ->where(Catalog::fields_NAME, $displayName)
                ->where(Catalog::fields_is_system, 1)
                ->select()
                ->fetchArray();
            
            // 如果存在同名但父ID不同的分类，记录警告但继续创建新分类
            // 注意：不同父分类下可以有同名分类，所以应该创建新分类
            foreach ($existingCatalogs as $existingCatalogData) {
                $existingPid = (int)($existingCatalogData[Catalog::fields_PID] ?? 0);
                $existingLevel = (int)($existingCatalogData[Catalog::fields_level] ?? 0);
                $existingId = (int)($existingCatalogData[Catalog::fields_ID] ?? 0);
                
                // 如果父ID不同，说明是不同父分类下的同名分类，这是允许的
                // 记录警告信息，但继续创建新分类
                if ($existingPid !== $parentId) {
                    $this->progress("      ⚠️  " . __('发现同名但父ID不同的分类: %{name} (id: %{id}, pid: %{pid} != %{parentId}, level: %{level})，将创建新分类（不同父分类下允许同名）', [
                        'name' => $displayName,
                        'id' => $existingId,
                        'pid' => $existingPid,
                        'parentId' => $parentId,
                        'level' => $existingLevel
                    ]), 'warning');
                }
            }
        }
        
        if (!$catalog || !$catalog->getId()) {
            // 不存在或验证失败，创建新分类
            // 重要：在创建前再次检查，确保同一父分类下不会有同名分类（使用显示名称）
            $duplicateCheck = $this->catalogModel->clear()
                ->where(Catalog::fields_NAME, $displayName)
                ->where(Catalog::fields_PID, $parentId)
                ->where(Catalog::fields_is_system, 1)
                ->find()
                ->fetch();
            
            if ($duplicateCheck && $duplicateCheck->getId()) {
                // 发现重复的分类（同名、同父ID），即使层级不同，也不应该创建
                $duplicatePid = (int)$duplicateCheck->getPid();
                $duplicateLevel = (int)($duplicateCheck->getData(Catalog::fields_level) ?? 0);
                $duplicateId = (int)$duplicateCheck->getId();
                
                // 如果父ID匹配，直接返回已存在的分类（即使层级不同）
                if ($duplicatePid === $parentId) {
                    $this->progress("      ⚠️  " . __('发现已存在的同名分类: %{name} (id: %{id}, pid: %{pid}, level: %{level})，将使用已存在的分类', [
                        'name' => $displayName,
                        'id' => $duplicateId,
                        'pid' => $duplicatePid,
                        'level' => $duplicateLevel
                    ]), 'warning');
                    return $duplicateCheck;
                }
            }
            
            try {
                $catalog = ObjectManager::getInstance(Catalog::class);
                $catalog->setName($displayName)
                    ->setDescription(__('目录 %{name}', ['name' => $displayName]))
                    ->setPid($parentId)
                    ->setData(Catalog::fields_level, $currentLevel)
                    ->setData(Catalog::fields_is_system, 1)
                    ->setIsActive(true)
                    ->save();
                
                // 验证保存后的分类是否正确
                $savedPid = (int)$catalog->getPid();
                $savedLevel = (int)($catalog->getData(Catalog::fields_level) ?? 0);
                if ($savedPid !== $parentId || $savedLevel !== $currentLevel) {
                    throw new \Exception("保存的分类层级不正确: {$displayName} (pid: {$savedPid} != {$parentId}, level: {$savedLevel} != {$currentLevel})");
                }
            } catch (\Exception $e) {
                // 如果保存失败（可能是并发创建或重名冲突），再次查询（使用显示名称）
                $catalog = $this->catalogModel->clear()
                    ->where(Catalog::fields_NAME, $displayName)
                    ->where(Catalog::fields_PID, $parentId)
                    ->where(Catalog::fields_is_system, 1)
                    ->find()
                    ->fetch();
                
                // 再次严格验证
                if ($catalog && $catalog->getId()) {
                    $catalogPid = (int)$catalog->getPid();
                    $catalogLevel = (int)($catalog->getData(Catalog::fields_level) ?? 0);
                    // 只要父ID匹配，就返回（同一父分类下不允许同名）
                    if ($catalogPid === $parentId) {
                        return $catalog;
                    } else {
                        // 验证失败，抛出异常
                        throw new \Exception("查询到的分类层级不正确: {$displayName} (pid: {$catalogPid} != {$parentId}, level: {$catalogLevel} != {$currentLevel})");
                    }
                } else {
                    // 如果还是没有，抛出原始异常
                    throw $e;
                }
            }
        }
        
        return $catalog;
    }
    
    
    /**
     * 清理孤立的分类（不在扫描列表中的系统分类）
     * 
     * @param array $scannedCatalogIds 扫描到的分类ID列表
     * @return int 删除的分类数量
     */
    private function cleanupOrphanCatalogs(array $scannedCatalogIds): int
    {
        if (empty($scannedCatalogIds)) {
            // 如果没有扫描到任何分类，删除所有系统分类（除了顶级模块分类）
            // 这里不删除顶级模块分类，因为它们可能对应已安装的模块
            return 0;
        }
        
        // 去重
        $scannedCatalogIds = array_unique($scannedCatalogIds);
        
        // 查找所有系统分类
        $allSystemCatalogs = $this->catalogModel->clear()
            ->where(Catalog::fields_is_system, 1)
            ->select()
            ->fetchArray();
        
        $deletedCount = 0;
        $catalogsToDelete = [];
        
        // 找出不在扫描列表中的分类
        foreach ($allSystemCatalogs as $catalog) {
            $catalogId = (int)($catalog[Catalog::fields_ID] ?? 0);
            if ($catalogId > 0 && !in_array($catalogId, $scannedCatalogIds)) {
                $catalogsToDelete[] = $catalogId;
            }
        }
        
        // 递归删除分类（从子到父，避免外键约束问题）
        if (!empty($catalogsToDelete)) {
            // 按层级从深到浅排序，先删除子分类
            $catalogsByLevel = [];
            foreach ($catalogsToDelete as $catalogId) {
                $catalog = $this->catalogModel->clear()->load($catalogId);
                if ($catalog && $catalog->getId()) {
                    $level = (int)($catalog->getData(Catalog::fields_level) ?? 1);
                    if (!isset($catalogsByLevel[$level])) {
                        $catalogsByLevel[$level] = [];
                    }
                    $catalogsByLevel[$level][] = $catalogId;
                }
            }
            
            // 从最深层级开始删除
            krsort($catalogsByLevel);
            foreach ($catalogsByLevel as $level => $catalogIds) {
                foreach ($catalogIds as $catalogId) {
                    $catalog = $this->catalogModel->clear()->load($catalogId);
                    if ($catalog && $catalog->getId()) {
                        // 检查是否有文档关联
                        $hasDocuments = $this->documentModel->clear()
                            ->where(Document::fields_CATEGORY_ID, $catalogId)
                            ->find()
                            ->fetch();
                        
                        // 检查是否有子分类（包括所有子分类，如果有非系统子分类，不应该删除父分类）
                        $hasChildren = $this->catalogModel->clear()
                            ->where(Catalog::fields_PID, $catalogId)
                            ->find()
                            ->fetch();
                        
                        // 如果没有文档且没有子分类，可以删除
                        // 注意：只删除系统分类，如果有非系统子分类，不应该删除父分类
                        if (!$hasDocuments && !$hasChildren) {
                            $catalog->delete()->fetch();
                            $deletedCount++;
                        }
                    }
                }
            }
        }
        
        return $deletedCount;
    }
    
    /**
     * 递归删除分类及其所有子分类
     * 
     * @param int $catalogId 分类ID
     */
    private function deleteCatalogAndChildren(int $catalogId): void
    {
        // 查找所有子分类
        $children = $this->catalogModel->clear()
            ->where(Catalog::fields_PID, $catalogId)
            ->select()
            ->fetchArray();
        
        // 先递归删除所有子分类
        foreach ($children as $child) {
            $childId = (int)($child[Catalog::fields_ID] ?? 0);
            if ($childId > 0) {
                $this->deleteCatalogAndChildren($childId);
            }
        }
        
        // 删除当前分类
        $this->catalogModel->clear()
            ->where(Catalog::fields_ID, $catalogId)
            ->delete()
            ->fetch();
    }
}

