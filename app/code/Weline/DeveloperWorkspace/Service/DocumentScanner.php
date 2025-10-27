<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Service;

use Weline\DeveloperWorkspace\Model\Document;
use Weline\DeveloperWorkspace\Model\Document\Catalog;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\Env;

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
    
    public function __construct(
        Document $documentModel,
        Catalog $catalogModel
    ) {
        $this->documentModel = $documentModel;
        $this->catalogModel = $catalogModel;
    }
    
    /**
     * 扫描所有模块的文档
     * 
     * @param bool $forceRescan 是否强制重新扫描（会删除旧的自动导入文档）
     * @return array ['scanned' => 总数, 'new' => 新增, 'updated' => 更新, 'modules' => [模块列表]]
     */
    public function scanAllModules(bool $forceRescan = false): array
    {
        $result = [
            'scanned' => 0,
            'new' => 0,
            'updated' => 0,
            'modules' => []
        ];
        
        // 如果强制重新扫描，先删除所有自动导入的文档
        if ($forceRescan) {
            $this->documentModel->where(Document::fields_IS_AUTO_IMPORTED, 1)->delete();
        }
        
        // 获取所有已安装的模块
        $modules = Env::getInstance()->getModuleList();
        
        foreach ($modules as $moduleName => $module) {
            $modulePath = $module['base_path'] ?? '';
            
            if (empty($moduleName) || empty($modulePath) || !($module['status'] ?? false)) {
                continue;
            }
            
            $docPath = rtrim($modulePath, '/\\') . DIRECTORY_SEPARATOR . 'doc';
            
            // 检查doc目录是否存在
            if (!is_dir($docPath)) {
                continue;
            }
            
            // 扫描该模块的文档
            $moduleResult = $this->scanModuleDocuments($moduleName, $docPath);
            $result['scanned'] += $moduleResult['scanned'];
            $result['new'] += $moduleResult['new'];
            $result['updated'] += $moduleResult['updated'];
            $result['modules'][] = [
                'name' => $moduleName,
                'scanned' => $moduleResult['scanned'],
                'new' => $moduleResult['new'],
                'updated' => $moduleResult['updated']
            ];
        }
        
        return $result;
    }
    
    /**
     * 扫描单个模块的文档
     * 
     * @param string $moduleName 模块名称
     * @param string $docPath doc目录路径
     * @return array
     */
    public function scanModuleDocuments(string $moduleName, string $docPath): array
    {
        $result = [
            'scanned' => 0,
            'new' => 0,
            'updated' => 0
        ];
        
        // 确保模块有对应的分类目录
        $moduleCatalog = $this->ensureModuleCatalog($moduleName);
        
        // 递归扫描文档文件
        $this->scanDirectory($docPath, $moduleName, $moduleCatalog, $result);
        
        return $result;
    }
    
    /**
     * 递归扫描目录
     */
    private function scanDirectory(string $dirPath, string $moduleName, Catalog $moduleCatalog, array &$result, string $relativePath = ''): void
    {
        if (!is_dir($dirPath)) {
            return;
        }
        
        $files = scandir($dirPath);
        
        foreach ($files as $file) {
            if (in_array($file, $this->ignoreDirs)) {
                continue;
            }
            
            $fullPath = $dirPath . DIRECTORY_SEPARATOR . $file;
            $currentRelativePath = $relativePath ? $relativePath . '/' . $file : $file;
            
            if (is_dir($fullPath)) {
                // 递归扫描子目录，但不创建子分类（所有文档都归属于模块分类）
                $this->scanDirectory($fullPath, $moduleName, $moduleCatalog, $result, $currentRelativePath);
            } elseif (is_file($fullPath) && $this->isDocumentFile($file)) {
                // 处理文档文件（所有文档都归到模块分类下）
                $this->importDocumentFile($fullPath, $file, $moduleName, $moduleCatalog, $currentRelativePath, $result);
            }
        }
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
     * 导入文档文件
     */
    private function importDocumentFile(string $filePath, string $fileName, string $moduleName, Catalog $catalog, string $relativePath, array &$result): void
    {
        $result['scanned']++;
        
        // 读取文件内容
        $content = file_get_contents($filePath);
        if ($content === false) {
            return;
        }
        
        // 提取标题和摘要
        $title = $this->extractTitle($content, $fileName);
        $summary = $this->extractSummary($content);
        
        // 检查是否已存在（基于模块名+文件路径）
        $existingDoc = $this->documentModel->clear()
            ->where(Document::fields_MODULE_NAME, $moduleName)
            ->where(Document::fields_FILE_PATH, $relativePath)
            ->where(Document::fields_IS_AUTO_IMPORTED, 1)
            ->find()
            ->fetch();
        
        if ($existingDoc) {
            // 更新现有文档
            $existingDoc->setTitle($title)
                ->setSummary($summary)
                ->setContent($content)
                ->setFileName($fileName)
                ->setCategoryId((string)$catalog->getId())
                ->save();
            $result['updated']++;
        } else {
            // 创建新文档
            $newDoc = ObjectManager::getInstance(Document::class);
            $newDoc->setTitle($title)
                ->setSummary($summary)
                ->setContent($content)
                ->setModuleName($moduleName)
                ->setFilePath($relativePath)
                ->setFileName($fileName)
                ->setCategoryId((string)$catalog->getId())
                ->setIsAutoImported(true)
                ->setSortOrder(0)
                ->save();
            $result['new']++;
        }
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
     * 确保模块有对应的分类
     */
    private function ensureModuleCatalog(string $moduleName): Catalog
    {
        // 先查找已存在的分类（不限制is_system，避免遗漏）
        $catalog = $this->catalogModel->clear()
            ->where(Catalog::fields_NAME, $moduleName)
            ->find()
            ->fetch();
        
        if (!$catalog || !$catalog->getId()) {
            // 不存在则创建新分类
            try {
                $catalog = ObjectManager::getInstance(Catalog::class);
                $catalog->setName($moduleName)
                    ->setDescription('模块 ' . $moduleName . ' 的开发文档')
                    ->setPid(0)
                    ->setData(Catalog::fields_level, 1)
                    ->setData(Catalog::fields_is_system, 1)
                    ->setIsActive(true)
                    ->save();
            } catch (\Exception $e) {
                // 如果保存失败（可能是并发创建），再次查询
                $catalog = $this->catalogModel->clear()
                    ->where(Catalog::fields_NAME, $moduleName)
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
    
}

