<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Service;

use Weline\DeveloperWorkspace\Model\Document;
use Weline\DeveloperWorkspace\Model\Document\Catalog;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\Env;
use Weline\Framework\Register\Register;

/**
 * 文档扫描服务 - 扫描各模块 doc 目录并导入数据库
 *
 * 架构设计：
 * - 每个模块 doc/ 下的子目录映射为分类，最多两层
 * - 文档以 (module_name, file_path) 为唯一标识，确保幂等
 * - 分类以 (name, pid) 为唯一标识，不依赖 level 字段
 */
class DocumentScanner
{
    private const MODULE_DOC_ROOT_NAME = '模块文档';
    private const CORE_DOC_MODULE_NAME = 'Weline_Framework';
    private const CORE_DOC_ROOT_NAME = '核心';
    private const CLEANUP_BATCH_SIZE = 1000;

    private Document $documentModel;
    private Catalog $catalogModel;

    private array $ignoreDirs = ['.', '..', '.git', '.svn', 'vendor', 'node_modules', 'var', 'pub', 'generated'];

    /** @var callable|null */
    private $progressCallback = null;

    /** 本次扫描中已处理的分类 ID 集合 */
    private array $seenCatalogIds = [];

    /** 本次扫描中已处理的文档唯一键集合 */
    private array $seenDocumentKeys = [];
    private array $seenDisplayDocumentKeys = [];
    private array $documentFieldSupport = [];

    public function __construct(Document $documentModel, Catalog $catalogModel)
    {
        $this->documentModel = $documentModel;
        $this->catalogModel = $catalogModel;
    }

    public function setProgressCallback(callable $callback): self
    {
        $this->progressCallback = $callback;
        return $this;
    }

    private function progress(string $message, string $type = 'info'): void
    {
        if ($this->progressCallback) {
            call_user_func($this->progressCallback, $message, $type);
        }
    }

    // ─── 公开入口 ───────────────────────────────────────────

    /**
     * 扫描所有模块的文档
     */
    public function scanAllModules(bool $forceRescan = false): array
    {
        $this->seenCatalogIds = [];
        $this->seenDocumentKeys = [];
        $this->seenDisplayDocumentKeys = [];

        $result = [
            'scanned' => 0, 'new' => 0, 'updated' => 0,
            'deleted' => 0, 'cleaned_duplicates' => 0, 'cleaned_legacy_duplicates' => 0, 'modules' => [],
        ];

        // 强制重扫时先清空
        if ($forceRescan) {
            $this->progress(__('正在清理旧的自动导入文档和分类...'), 'warning');
            $result['cleaned_legacy_duplicates'] += $this->cleanupLegacyNullIdentityDocuments();
            $this->documentModel->clear()->where(Document::schema_fields_IS_AUTO_IMPORTED, 1)->delete()->fetch();
            $this->catalogModel->clear()->where(Catalog::schema_fields_is_system, 1)->delete()->fetch();
            $this->progress(__('清理完成'), 'success');
        }

        // 确保顶层"模块文档"分类存在
        $topCatalog = $this->ensureCatalog('模块文档', 0, 0, '所有模块的开发文档', 999999);
        $this->seenCatalogIds[(int)$topCatalog->getId()] = true;

        // 遍历所有已安装模块
        $modules = Env::getInstance()->getModuleList();
        $modulesWithDoc = [];
        foreach ($modules as $name => $mod) {
            if (empty($name) || empty($mod['base_path']) || !($mod['status'] ?? false)) {
                continue;
            }
            $docPath = rtrim($mod['base_path'], '/\\') . DIRECTORY_SEPARATOR . 'doc';
            if (is_dir($docPath)) {
                $modulesWithDoc[$name] = $mod;
            }
        }

        $total = count($modulesWithDoc);
        $current = 0;
        $this->progress(__('找到 %{count} 个模块包含 doc 目录，开始扫描...', ['count' => $total]), 'info');

        foreach ($modulesWithDoc as $moduleName => $module) {
            $current++;
            $modulePath = $module['base_path'];
            $docPath = rtrim($modulePath, '/\\') . DIRECTORY_SEPARATOR . 'doc';

            $this->progress('', 'info');
            $this->progress('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', 'info');
            $this->progress("📦 " . __('[%{current}/%{total}] 正在扫描模块: %{name}', [
                'current' => $current, 'total' => $total, 'name' => $moduleName,
            ]), 'info');
            $this->progress("   " . __('路径: %{path}', ['path' => $docPath]), 'info');

            $moduleResult = ['scanned' => 0, 'new' => 0, 'updated' => 0];

            if ($this->isCoreDocumentModule($moduleName)) {
                $this->scanCoreModuleDocuments($docPath, $moduleName, $modulePath, $moduleResult);
            } else {
                // 创建模块分类（挂在"模块文档"下）
                $desc = $this->getModuleDescription($modulePath) ?: __('模块 %{name} 的开发文档', ['name' => $moduleName]);
                $moduleCatalog = $this->ensureCatalog($moduleName, (int)$topCatalog->getId(), 1, $desc);
                $this->seenCatalogIds[(int)$moduleCatalog->getId()] = true;

                // 扫描 doc/ 目录
                $this->scanDirectory($docPath, $moduleName, (int)$moduleCatalog->getId(), $moduleResult, '', 'doc');

                // 扫描模块根目录下的外部文档（README.md 等）
                $this->scanRootDocuments($modulePath, $moduleName, (int)$moduleCatalog->getId(), $moduleResult);
            }

            $result['scanned'] += $moduleResult['scanned'];
            $result['new'] += $moduleResult['new'];
            $result['updated'] += $moduleResult['updated'];
            $result['modules'][] = array_merge(['name' => $moduleName], $moduleResult);

            $this->progress("   ✓ " . __('扫描完成: 文档 %{scanned} 个, 新增 %{new} 个, 更新 %{updated} 个', $moduleResult), 'success');
        }

        // 清理不存在的文档和分类
        $this->progress('', 'info');
        $this->progress('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', 'info');
        $this->progress("🧹 " . __('正在清理不存在的文档和分类...'), 'info');
        $result['deleted'] = $this->cleanupUnseenDocuments();
        $result['deleted_catalogs'] = $this->cleanupUnseenCatalogs();

        // API 文档导入
        $this->importApiDocuments($forceRescan, $result);
        $result['cleaned_legacy_duplicates'] += $this->cleanupLegacyNullIdentityDocuments();

        return $result;
    }

    // ─── 目录扫描 ───────────────────────────────────────────

    /**
     * 递归扫描目录
     *
     * @param string $dirPath       磁盘绝对路径
     * @param string $moduleName    模块名
     * @param int    $parentCatId   父分类 ID
     * @param array  &$result       统计累加
     * @param string $relativePath  相对于 doc/ 的路径（用于构建子分类）
     * @param string $docPrefix     文件路径前缀（doc / view/doc）
     * @param int    $depth         当前目录深度（从 0 开始）
     * @param ?int   $docCatalogId  当前目录文件写入的分类 ID；为空时使用父分类
     */
    private function scanDirectory(
        string $dirPath,
        string $moduleName,
        int    $parentCatId,
        array  &$result,
        string $relativePath = '',
        string $docPrefix = 'doc',
        int    $depth = 0,
        ?int   $docCatalogId = null
    ): void {
        // 支持最多 6 层深度，满足 hook/backend/layouts/login/ 等多层结构
        if (!is_dir($dirPath) || $depth > 6) {
            if ($depth > 6) {
                $this->progress("      ⚠️  " . __('跳过深层目录（超过6层）: %{path}', ['path' => $relativePath]), 'warning');
            }
            return;
        }

        $items = scandir($dirPath);
        if ($items === false) {
            return;
        }

        // 分离文件和目录
        $docFiles = [];
        $subDirs = [];
        foreach ($items as $item) {
            if (in_array($item, $this->ignoreDirs)) {
                continue;
            }
            $fullPath = $dirPath . DIRECTORY_SEPARATOR . $item;
            $relPath = $relativePath ? $relativePath . '/' . $item : $item;
            if (is_dir($fullPath)) {
                $subDirs[] = ['path' => $fullPath, 'name' => $item, 'relative' => $relPath];
            } elseif (is_file($fullPath) && $this->isDocumentFile($item)) {
                $docFiles[] = [
                    'path' => $fullPath,
                    'name' => $item,
                    'moduleRelative' => $docPrefix . '/' . ($relativePath ? $relativePath . '/' . $item : $item),
                ];
            }
        }

        // 排序（支持 {数字}- 前缀）
        $docFiles = $this->sortByPrefix($docFiles);
        $subDirs = $this->sortByPrefix($subDirs);

        // 导入当前目录下的文档
        $currentDocCatalogId = $docCatalogId ?? $parentCatId;
        foreach ($docFiles as $file) {
            $this->upsertDocument(
                $moduleName,
                $file['moduleRelative'],
                $file['path'],
                $file['name'],
                $currentDocCatalogId,
                $result,
                $this->extractSortOrder($file['name'])
            );
        }

        // 递归处理子目录
        foreach ($subDirs as $dir) {
            $displayName = $this->extractDisplayName($dir['name']);
            $sortOrder = $this->extractSortOrder($dir['name']);
            $parentLevel = $this->getCatalogLevel($parentCatId);
            $subCatalog = $this->ensureCatalog($displayName, $parentCatId, $parentLevel + 1, '', $sortOrder, $this->hasSortPrefix($dir['name']));
            $this->seenCatalogIds[(int)$subCatalog->getId()] = true;
            $this->scanDirectory($dir['path'], $moduleName, (int)$subCatalog->getId(), $result, $dir['relative'], $docPrefix, $depth + 1);
        }
    }

    private function scanCoreModuleDocuments(string $docPath, string $moduleName, string $modulePath, array &$result): int
    {
        $coreCatalog = $this->ensureCatalog(self::CORE_DOC_ROOT_NAME, 0, 1, 'WelineFramework 核心文档', 999999);
        $coreCatalogId = (int)$coreCatalog->getId();
        $this->seenCatalogIds[$coreCatalogId] = true;

        $this->scanDirectory($docPath, $moduleName, 0, $result, '', 'doc', 0, $coreCatalogId);
        $this->scanRootDocuments($modulePath, $moduleName, $coreCatalogId, $result);

        return $coreCatalogId;
    }

    /**
     * 扫描模块根目录下的外部文档（README.md 等）
     */
    private function scanRootDocuments(string $modulePath, string $moduleName, int $catalogId, array &$result): void
    {
        if (!is_dir($modulePath)) {
            return;
        }
        $files = scandir($modulePath);
        if ($files === false) {
            return;
        }
        $docFiles = [];
        foreach ($files as $file) {
            if (in_array($file, $this->ignoreDirs)) {
                continue;
            }
            $fullPath = $modulePath . DIRECTORY_SEPARATOR . $file;
            if (is_file($fullPath) && $this->isDocumentFile($file)) {
                $docFiles[] = ['path' => $fullPath, 'name' => $file, 'moduleRelative' => $file];
            }
        }
        if (empty($docFiles)) {
            return;
        }
        $docFiles = $this->sortByPrefix($docFiles);
        foreach ($docFiles as $file) {
            $this->upsertDocument(
                $moduleName,
                $file['moduleRelative'],
                $file['path'],
                $file['name'],
                $catalogId,
                $result,
                $this->extractSortOrder($file['name'])
            );
        }
    }

    // ─── 文档 UPSERT ────────────────────────────────────────

    /**
     * 根据 (module_name, file_path) 更新或插入文档
     * 只有当源文件修改时间变化时才更新记录
     *
     * @param string $moduleName    模块名
     * @param string $filePath      相对于模块根目录的路径（作唯一标识）
     * @param string $diskPath      磁盘绝对路径（用于读取内容）
     * @param string $fileName      文件名
     * @param int    $catalogId     所属分类 ID
     * @param array  &$result       统计累加
     */
    private function upsertDocument(
        string $moduleName,
        string $filePath,
        string $diskPath,
        string $fileName,
        int $catalogId,
        array &$result,
        int $sortOrder = 999999
    ): void
    {
        $docKey = $moduleName . '|' . $filePath;
        if (isset($this->seenDocumentKeys[$docKey])) {
            return;
        }
        $result['scanned']++;

        // 获取文件修改时间
        $fileMtime = @filemtime($diskPath);
        if ($fileMtime === false) {
            $this->progress("      ⚠️  " . __('无法获取文件时间: %{path}', ['path' => $filePath]), 'warning');
            return;
        }

        $content = $this->readFileUtf8($diskPath);
        if ($content === false) {
            $this->progress("      ⚠️  " . __('无法读取文件: %{path}', ['path' => $filePath]), 'warning');
            return;
        }
        $title = $this->extractTitle($content, $fileName);
        $summary = $this->extractSummary($content);
        $displayKey = $moduleName . '|' . $catalogId . '|' . $title . '|' . sha1($content);
        if (isset($this->seenDisplayDocumentKeys[$displayKey])) {
            $this->progress("      ↷ " . __('跳过重复内容: %{path}', ['path' => $filePath]), 'info');
            return;
        }
        $this->seenDocumentKeys[$docKey] = true;
        $this->seenDisplayDocumentKeys[$displayKey] = true;

        // 查询已有记录
        $existing = ObjectManager::make(Document::class)->clear()
            ->where(Document::schema_fields_MODULE_NAME, $moduleName)
            ->where(Document::schema_fields_FILE_PATH, $filePath)
            ->find()
            ->fetch();

        if ($existing && $existing->getId()) {
            // 检查文件是否有变化（通过修改时间判断）
            $supportsFileMtime = $this->documentSupportsField(Document::schema_fields_FILE_MTIME);
            $storedMtime = $supportsFileMtime ? (int)($existing->getData(Document::schema_fields_FILE_MTIME) ?? 0) : 0;
            if ($supportsFileMtime && $storedMtime === $fileMtime) {
                // 文件未变化，跳过更新（但仍需确保分类正确）
                $storedCategoryId = (int)($existing->getCategoryId() ?? 0);
                $needsSave = false;
                if ($storedCategoryId !== $catalogId) {
                    $existing->setCategoryId((string)$catalogId);
                    $needsSave = true;
                    $this->progress("      ⟳ " . __('分类变更: %{path}', ['path' => $filePath]), 'info');
                }
                if ((string)$existing->getTitle() !== $title || (string)$existing->getData(Document::schema_fields_summary) !== $summary) {
                    $existing->setTitle($title)
                        ->setData(Document::schema_fields_summary, $summary)
                        ->setFileName($fileName);
                    $needsSave = true;
                }
                if ((int)($existing->getSortOrder() ?? 0) !== $sortOrder) {
                    $existing->setSortOrder($sortOrder);
                    $needsSave = true;
                }
                if ($needsSave) {
                    $existing->save();
                    $result['updated']++;
                }
                return;
            }

            $existing->setTitle($title)
                ->setData(Document::schema_fields_summary, $summary)
                ->setFileName($fileName)
                ->setCategoryId((string)$catalogId)
                ->setIsAutoImported(true)
                ->setSortOrder($sortOrder);
            if ($this->documentSupportsField(Document::schema_fields_UPDATED_AT)) {
                $existing->setData(Document::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));
            }
            if ($supportsFileMtime) {
                $existing->setData(Document::schema_fields_FILE_MTIME, $fileMtime);
            }
            $existing->save();
            $result['updated']++;
            $this->progress("      ↻ " . __('更新: %{path}', ['path' => $filePath]), 'info');
        } else {
            // 新文档，插入规范化后的源文件标识
            $newDoc = ObjectManager::make(Document::class);
            $newDoc->setTitle($title)
                ->setData(Document::schema_fields_summary, $summary)
                ->setContent('')
                ->setModuleName($moduleName)
                ->setFilePath($filePath)
                ->setFileName($fileName)
                ->setCategoryId((string)$catalogId)
                ->setIsAutoImported(true)
                ->setSortOrder($sortOrder);
            if ($this->documentSupportsField(Document::schema_fields_UPDATED_AT)) {
                $newDoc->setData(Document::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));
            }
            if ($this->documentSupportsField(Document::schema_fields_FILE_MTIME)) {
                $newDoc->setData(Document::schema_fields_FILE_MTIME, $fileMtime);
            }
            $newDoc->save();
            $result['new']++;
            $this->progress("      ✨ " . __('新增: %{path}', ['path' => $filePath]), 'success');
        }
    }

    // ─── 分类管理 ────────────────────────────────────────────

    /**
     * 确保分类存在（按 name + pid 查找，不存在则创建）
     */
    private function documentSupportsField(string $field): bool
    {
        if (array_key_exists($field, $this->documentFieldSupport)) {
            return $this->documentFieldSupport[$field];
        }

        try {
            $this->documentFieldSupport[$field] = $this->documentModel
                ->getConnection()
                ->getConnector()
                ->hasField($this->documentModel->getTable(), $field);
        } catch (\Throwable) {
            $this->documentFieldSupport[$field] = false;
        }

        return $this->documentFieldSupport[$field];
    }

    private function ensureCatalog(string $name, int $pid, int $level = 1, string $description = '', int $position = 0, bool $hasExplicitPosition = false): Catalog
    {
        // 使用新实例查询，避免单例状态污染
        $queryModel = ObjectManager::make(Catalog::class);
        $catalog = $queryModel->clear()
            ->where(Catalog::schema_fields_NAME, $name)
            ->where(Catalog::schema_fields_PID, $pid)
            ->find()
            ->fetch();

        if ($catalog && $catalog->getId()) {
            $needsUpdate = false;
            $updateData = [];

            // 更新 level（缓存值，可能需要修正）
            $currentLevel = (int)($catalog->getData(Catalog::schema_fields_level) ?? 0);
            if ($currentLevel !== $level) {
                $updateData[Catalog::schema_fields_level] = $level;
                $needsUpdate = true;
            }

            // 更新 position（排序值）
            $currentPosition = (int)($catalog->getData(Catalog::schema_fields_position) ?? 0);
            $incomingHasExplicitPosition = $hasExplicitPosition && $position < 999999;
            $currentHasExplicitPosition = $currentPosition >= 0 && $currentPosition < 999999;
            $shouldUpdatePosition = false;
            if ($incomingHasExplicitPosition) {
                $shouldUpdatePosition = $currentPosition !== $position;
            } elseif (!$currentHasExplicitPosition && $position > 0 && $currentPosition !== $position) {
                $shouldUpdatePosition = true;
            }
            if ($shouldUpdatePosition) {
                $updateData[Catalog::schema_fields_position] = $position;
                $needsUpdate = true;
            }

            if ($needsUpdate && !empty($updateData)) {
                ObjectManager::make(Catalog::class)->clear()
                    ->where(Catalog::schema_fields_ID, $catalog->getId())
                    ->update($updateData)
                    ->fetch();
            }
            if (!empty($description) && $catalog->getDescription() !== $description) {
                $catalog->setDescription($description)->save();
            }
            return $catalog;
        }

        if (empty($description)) {
            $description = __('目录 %{name}', ['name' => $name]);
        }

        try {
            // 必须使用 make 创建全新对象，避免单例污染
            $catalog = ObjectManager::make(Catalog::class);
            $catalog->setName($name)
                ->setDescription($description)
                ->setPid($pid)
                ->setData(Catalog::schema_fields_level, $level)
                ->setData(Catalog::schema_fields_is_system, 1)
                ->setData(Catalog::schema_fields_position, $position)
                ->setIsActive(true)
                ->save();
        } catch (\Exception $e) {
            // 并发创建冲突时重新查询
            $existingCatalog = ObjectManager::make(Catalog::class)->clear()
                ->where(Catalog::schema_fields_NAME, $name)
                ->where(Catalog::schema_fields_PID, $pid)
                ->find()
                ->fetch();
            if (!$existingCatalog || !$existingCatalog->getId()) {
                throw $e;
            }
            $catalog = $existingCatalog;
        }

        return $catalog;
    }

    /**
     * 获取分类的 level（简单从数据库读取）
     */
    private function getCatalogLevel(int $catalogId): int
    {
        if ($catalogId <= 0) {
            return 0;
        }
        $cat = ObjectManager::make(Catalog::class)->clear()->load($catalogId);
        return ($cat && $cat->getId()) ? (int)($cat->getData(Catalog::schema_fields_level) ?? 0) : 0;
    }

    // ─── 清理 ────────────────────────────────────────────────

    /**
     * 删除本次未见到的自动导入文档
     */
    private function cleanupUnseenDocuments(): int
    {
        $pdo = $this->resolvePdo();
        if (!$pdo instanceof \PDO) {
            return 0;
        }

        $catalogIds = $this->collectSystemCatalogTreeIds($pdo, self::MODULE_DOC_ROOT_NAME);
        $catalogList = $this->intList($catalogIds);
        if ($catalogList === '') {
            return 0;
        }

        $totalDeleted = 0;
        $documentTable = $this->quoteIdentifier($this->documentModel->getTable());
        $idField = $this->quoteIdentifier(Document::schema_fields_ID);
        $categoryField = $this->quoteIdentifier(Document::schema_fields_CATEGORY_ID);
        $moduleField = $this->quoteIdentifier(Document::schema_fields_MODULE_NAME);
        $filePathField = $this->quoteIdentifier(Document::schema_fields_FILE_PATH);
        $autoField = $this->quoteIdentifier(Document::schema_fields_IS_AUTO_IMPORTED);
        $lastId = 0;

        while (true) {
            $sql = 'SELECT ' . $idField . ', ' . $moduleField . ', ' . $filePathField
                . ' FROM ' . $documentTable
                . ' WHERE ' . $idField . ' > ' . (int)$lastId
                . ' AND (' . $categoryField . ' IN (' . $catalogList . ')'
                . ' OR ' . $moduleField . ' = ' . $pdo->quote(self::CORE_DOC_MODULE_NAME) . ')'
                . ' AND ' . $autoField . ' = 1'
                . ' ORDER BY ' . $idField . ' ASC'
                . ' LIMIT ' . self::CLEANUP_BATCH_SIZE;
            $docs = $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);

            if (empty($docs)) {
                break;
            }

            $idsToDelete = [];
            foreach ($docs as $doc) {
                $id = (int)($doc[Document::schema_fields_ID] ?? 0);
                $lastId = max($lastId, $id);
                $key = ($doc[Document::schema_fields_MODULE_NAME] ?? '') . '|' . ($doc[Document::schema_fields_FILE_PATH] ?? '');
                if (!isset($this->seenDocumentKeys[$key])) {
                    $idsToDelete[] = $id;
                }
            }

            if (!empty($idsToDelete)) {
                $totalDeleted += $this->deleteDocumentIdsWithPdo($pdo, $documentTable, $idField, $idsToDelete);
            }
        }

        if ($totalDeleted > 0) {
            $this->progress("   ✓ " . __('已删除 %{count} 个不存在的文档', ['count' => $totalDeleted]), 'success');
        } else {
            $this->progress("   ✓ " . __('无需删除文档'), 'success');
        }
        return $totalDeleted;
    }

    /**
     * 删除本次未见到的系统分类（从深层到浅层）
     */
    private function cleanupUnseenCatalogs(): int
    {
        $allSystem = $this->catalogModel->clear()
            ->where(Catalog::schema_fields_is_system, 1)
            ->select()
            ->fetchArray();

        $toDelete = [];
        foreach ($allSystem as $cat) {
            $id = (int)($cat[Catalog::schema_fields_ID] ?? 0);
            if ($id > 0 && !isset($this->seenCatalogIds[$id])) {
                $level = (int)($cat[Catalog::schema_fields_level] ?? 0);
                $toDelete[] = ['id' => $id, 'level' => $level];
            }
        }

        // 从深层到浅层删除
        usort($toDelete, fn($a, $b) => $b['level'] - $a['level']);

        $deleted = 0;
        foreach ($toDelete as $item) {
            $cat = $this->catalogModel->clear()->load($item['id']);
            if (!$cat || !$cat->getId()) {
                continue;
            }
            // 检查是否有文档或子分类关联
            $hasDoc = $this->documentModel->clear()
                ->where(Document::schema_fields_CATEGORY_ID, $item['id'])
                ->find()->fetch();
            $hasChild = $this->catalogModel->clear()
                ->where(Catalog::schema_fields_PID, $item['id'])
                ->find()->fetch();
            if (!$hasDoc && !$hasChild) {
                $cat->delete()->fetch();
                $deleted++;
            }
        }

        if ($deleted > 0) {
            $this->progress("   ✓ " . __('已删除 %{count} 个不存在的分类', ['count' => $deleted]), 'success');
        } else {
            $this->progress("   ✓ " . __('无需删除分类'), 'success');
        }
        return $deleted;
    }

    /**
     * 清理重复文档（公开方法，供外部调用）
     */
    public function cleanupDuplicateDocuments(): int
    {
        $this->progress(__('正在检查并清理重复文档...'), 'info');
        $totalDeleted = 0;
        $pageSize = 500;

        // 清理自动导入的重复文档（基于 module_name + file_path）
        $page = 1;
        while (true) {
            $groups = $this->documentModel->clear()
                ->fields([
                    Document::schema_fields_MODULE_NAME,
                    Document::schema_fields_FILE_PATH,
                    'MIN(' . Document::schema_fields_ID . ') as min_id',
                    'COUNT(*) as cnt',
                ])
                ->where(Document::schema_fields_IS_AUTO_IMPORTED, 1)
                ->group(Document::schema_fields_MODULE_NAME . ', ' . Document::schema_fields_FILE_PATH)
                ->having('COUNT(*) > 1')
                ->limit(($page - 1) * $pageSize, $pageSize)
                ->select()
                ->fetchArray();

            if (empty($groups)) {
                break;
            }

            foreach ($groups as $g) {
                $moduleName = $g[Document::schema_fields_MODULE_NAME] ?? '';
                $filePath = $g[Document::schema_fields_FILE_PATH] ?? '';
                $minId = (int)($g['min_id'] ?? 0);
                if (!$moduleName || !$filePath || $minId <= 0) {
                    continue;
                }
                $ids = $this->documentModel->clear()
                    ->fields(Document::schema_fields_ID)
                    ->where(Document::schema_fields_IS_AUTO_IMPORTED, 1)
                    ->where(Document::schema_fields_MODULE_NAME, $moduleName)
                    ->where(Document::schema_fields_FILE_PATH, $filePath)
                    ->where(Document::schema_fields_ID, $minId, '!=')
                    ->select()
                    ->fetchArray();
                $idsToDelete = array_map('intval', array_column($ids, Document::schema_fields_ID));
                if (!empty($idsToDelete)) {
                    $this->documentModel->clear()
                        ->where(Document::schema_fields_ID, $idsToDelete, 'in')
                        ->delete()->fetch();
                    $totalDeleted += count($idsToDelete);
                }
            }
            $page++;
        }

        if ($totalDeleted === 0) {
            $this->progress(__('没有发现需要清理的文档'), 'success');
        } else {
            $this->progress(__('共清理 %{count} 条重复文档', ['count' => $totalDeleted]), 'success');
        }
        return $totalDeleted;
    }

    // ─── API 文档导入 ────────────────────────────────────────

    private function cleanupLegacyNullIdentityDocuments(): int
    {
        $pdo = $this->resolvePdo();
        if (!$pdo instanceof \PDO) {
            return 0;
        }

        $catalogIds = $this->collectSystemCatalogTreeIds($pdo, self::MODULE_DOC_ROOT_NAME);
        if ($catalogIds === []) {
            return 0;
        }

        $documentTable = $this->quoteIdentifier($this->documentModel->getTable());
        $idField = $this->quoteIdentifier(Document::schema_fields_ID);
        $titleField = $this->quoteIdentifier(Document::schema_fields_TITLE);
        $categoryField = $this->quoteIdentifier(Document::schema_fields_CATEGORY_ID);
        $contentField = $this->quoteIdentifier(Document::schema_fields_CONTEND);
        $moduleField = $this->quoteIdentifier(Document::schema_fields_MODULE_NAME);
        $filePathField = $this->quoteIdentifier(Document::schema_fields_FILE_PATH);
        $autoField = $this->quoteIdentifier(Document::schema_fields_IS_AUTO_IMPORTED);
        $catalogList = $this->intList($catalogIds);
        if ($catalogList === '') {
            return 0;
        }

        $autoTitleKeys = $this->collectAutoImportedTitleKeys($pdo, $documentTable, $categoryField, $titleField, $autoField, $catalogList);
        $seenLegacyContentKeys = [];
        $deleted = 0;
        $lastId = 0;

        while (true) {
            $sql = 'SELECT ' . $idField . ', ' . $titleField . ', ' . $categoryField . ', '
                . $contentField . ', ' . $moduleField . ', ' . $filePathField
                . ' FROM ' . $documentTable
                . ' WHERE ' . $idField . ' > ' . (int)$lastId
                . ' AND ' . $categoryField . ' IN (' . $catalogList . ')'
                . ' AND ' . $autoField . ' = 0'
                . ' ORDER BY ' . $idField . ' ASC'
                . ' LIMIT ' . self::CLEANUP_BATCH_SIZE;
            $rows = $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
            if ($rows === []) {
                break;
            }

            $deleteIds = [];
            foreach ($rows as $row) {
                $id = (int)($row[Document::schema_fields_ID] ?? 0);
                $lastId = max($lastId, $id);
                if ($id <= 0) {
                    continue;
                }

                $moduleName = trim((string)($row[Document::schema_fields_MODULE_NAME] ?? ''));
                $filePath = trim((string)($row[Document::schema_fields_FILE_PATH] ?? ''));
                if ($moduleName !== '' || $filePath !== '') {
                    continue;
                }

                $categoryId = (int)($row[Document::schema_fields_CATEGORY_ID] ?? 0);
                $title = (string)($row[Document::schema_fields_TITLE] ?? '');
                $titleKey = $categoryId . '|' . $title;
                $contentKey = $titleKey . '|' . sha1((string)($row[Document::schema_fields_CONTEND] ?? ''));

                if (isset($autoTitleKeys[$titleKey]) || isset($seenLegacyContentKeys[$contentKey])) {
                    $deleteIds[] = $id;
                    continue;
                }

                $seenLegacyContentKeys[$contentKey] = $id;
            }

            if ($deleteIds !== []) {
                $deleted += $this->deleteDocumentIdsWithPdo($pdo, $documentTable, $idField, $deleteIds);
            }
        }

        if ($deleted > 0) {
            $this->progress('   Cleaned legacy duplicate imported documents: ' . $deleted, 'warning');
        }

        return $deleted;
    }

    /**
     * @return list<int>
     */
    private function collectSystemCatalogTreeIds(\PDO $pdo, string $rootName): array
    {
        $catalogTable = $this->quoteIdentifier($this->catalogModel->getTable());
        $idField = $this->quoteIdentifier(Catalog::schema_fields_ID);
        $pidField = $this->quoteIdentifier(Catalog::schema_fields_PID);
        $nameField = $this->quoteIdentifier(Catalog::schema_fields_NAME);
        $systemField = $this->quoteIdentifier(Catalog::schema_fields_is_system);
        $sql = 'SELECT ' . $idField . ', ' . $pidField . ', ' . $nameField
            . ' FROM ' . $catalogTable
            . ' WHERE ' . $systemField . ' = 1';
        $rows = $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === []) {
            return [];
        }

        $rootIds = [];
        $childrenByPid = [];
        foreach ($rows as $row) {
            $id = (int)($row[Catalog::schema_fields_ID] ?? 0);
            $pid = (int)($row[Catalog::schema_fields_PID] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if ((string)($row[Catalog::schema_fields_NAME] ?? '') === $rootName) {
                $rootIds[] = $id;
            }
            $childrenByPid[$pid][] = $id;
        }

        $ids = [];
        $queue = $rootIds;
        while ($queue !== []) {
            $id = array_shift($queue);
            if (!is_int($id) || isset($ids[$id])) {
                continue;
            }
            $ids[$id] = true;
            foreach ($childrenByPid[$id] ?? [] as $childId) {
                $queue[] = $childId;
            }
        }

        return array_keys($ids);
    }

    /**
     * @return array<string, bool>
     */
    private function collectAutoImportedTitleKeys(
        \PDO $pdo,
        string $documentTable,
        string $categoryField,
        string $titleField,
        string $autoField,
        string $catalogList
    ): array {
        $keys = [];
        $sql = 'SELECT ' . $categoryField . ', ' . $titleField
            . ' FROM ' . $documentTable
            . ' WHERE ' . $categoryField . ' IN (' . $catalogList . ')'
            . ' AND ' . $autoField . ' = 1';
        foreach ($pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $keys[(int)($row[Document::schema_fields_CATEGORY_ID] ?? 0) . '|' . (string)($row[Document::schema_fields_TITLE] ?? '')] = true;
        }

        return $keys;
    }

    private function deleteDocumentIdsWithPdo(\PDO $pdo, string $documentTable, string $idField, array $ids): int
    {
        $idList = $this->intList($ids);
        if ($idList === '') {
            return 0;
        }

        $statement = $pdo->prepare('DELETE FROM ' . $documentTable . ' WHERE ' . $idField . ' IN (' . $idList . ')');
        $statement->execute();

        return $statement->rowCount();
    }

    private function resolvePdo(): ?\PDO
    {
        try {
            $connector = $this->documentModel->getConnection()->getConnector();
            if (method_exists($connector, 'create')) {
                $connector->create();
            }
            if (method_exists($connector, 'getLink')) {
                $pdo = $connector->getLink();
                return $pdo instanceof \PDO ? $pdo : null;
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (str_contains($identifier, '"')) {
            return $identifier;
        }

        $parts = explode('.', $identifier);
        $parts = array_map(static fn(string $part): string => '"' . str_replace('"', '""', $part) . '"', $parts);

        return implode('.', $parts);
    }

    private function intList(array $values): string
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $values), static fn(int $value): bool => $value > 0)));

        return implode(',', $ids);
    }

    private function importApiDocuments(bool $forceRescan, array &$result): void
    {
        try {
            $this->progress('', 'info');
            $this->progress('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', 'info');
            $this->progress(__('📡 开始导入API文档...'), 'info');

            /** @var ApiDocImporter $importer */
            $importer = ObjectManager::getInstance(ApiDocImporter::class);
            $importer->setProgressCallback(fn(string $msg, string $type) => $this->progress($msg, $type));
            $apiResult = $importer->importAll($forceRescan);

            $result['scanned'] += $apiResult['scanned'];
            $result['new'] += $apiResult['new'];
            $result['updated'] += $apiResult['updated'];
            foreach ($apiResult['modules'] ?? [] as $m) {
                $result['modules'][] = ['name' => $m['name'] . ' (API)', 'scanned' => $m['scanned'], 'new' => $m['new'], 'updated' => $m['updated']];
            }
            $this->progress("   ✓ " . __('API文档导入完成: 扫描 %{scanned}, 新增 %{new}, 更新 %{updated}', [
                'scanned' => $apiResult['scanned'], 'new' => $apiResult['new'], 'updated' => $apiResult['updated'],
            ]), 'success');
        } catch (\Throwable $e) {
            $this->progress("   ⚠️  " . __('API文档导入失败: %{error}', ['error' => $e->getMessage()]), 'warning');
        }
    }

    // ─── 工具方法 ────────────────────────────────────────────

    private function isDocumentFile(string $fileName): bool
    {
        return in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), ['md', 'markdown', 'txt']);
    }

    private function isCoreDocumentModule(string $moduleName): bool
    {
        return $moduleName === self::CORE_DOC_MODULE_NAME;
    }

    /**
     * 提取显示名称（去掉 {数字}- 前缀）
     */
    private function extractDisplayName(string $name): string
    {
        return preg_match('/^\d+[-_](.+)$/', $name, $m) ? $m[1] : $name;
    }

    /**
     * 提取排序权重
     */
    private function extractSortOrder(string $name): int
    {
        return preg_match('/^(\d+)[-_]/', $name, $m) ? (int)$m[1] : 999999;
    }

    private function hasSortPrefix(string $name): bool
    {
        return preg_match('/^\d+[-_]/', $name) === 1;
    }

    /**
     * 按数字前缀排序
     */
    private function sortByPrefix(array $items): array
    {
        usort($items, function ($a, $b) {
            $oa = $this->extractSortOrder($a['name']);
            $ob = $this->extractSortOrder($b['name']);
            return $oa !== $ob ? $oa <=> $ob : strcmp($a['name'], $b['name']);
        });
        return $items;
    }

    private function extractTitle(string $content, string $fallback): string
    {
        $fileName = $this->extractDisplayName(pathinfo($fallback, PATHINFO_FILENAME));
        
        if (preg_match('/^#\s+(.+)$/m', $content, $m)) {
            $title = trim($m[1]);
            
            // 如果标题是泛称（如"事件文档"、"Hook文档"等），追加文件名使其更具体
            $genericPatterns = [
                '/事件文档$/u',
                '/Hook文档$/u',
                '/hook文档$/ui',
                '/Event文档$/ui',
                '/文档$/u',
            ];
            
            foreach ($genericPatterns as $pattern) {
                if (preg_match($pattern, $title)) {
                    // 只有当文件名与标题不同时才追加
                    if (mb_stripos($title, $fileName) === false) {
                        return $this->applyFileVariantToTitle($title . ' - ' . $fileName, $fallback);
                    }
                    break;
                }
            }
            
            return $this->applyFileVariantToTitle($title, $fallback);
        }
        
        return $fileName;
    }

    private function applyFileVariantToTitle(string $title, string $fallback): string
    {
        if (!preg_match('/\.([a-z]{2}(?:[_-][a-z]{2})?)\.(?:md|markdown|txt)$/i', $fallback, $match)) {
            return $title;
        }

        $variant = strtolower(str_replace('_', '-', $match[1]));
        if (str_contains(strtolower($title), '(' . $variant . ')')) {
            return $title;
        }

        return $title . ' (' . $variant . ')';
    }

    private function extractSummary(string $content): string
    {
        $text = preg_replace('/^#+\s+/m', '', $content);
        $text = preg_replace('/[*_~`]/', '', $text);
        $text = preg_replace('/\[([^\]]+)\]\([^\)]+\)/', '$1', $text);
        $text = trim($text);
        return mb_strlen($text) > 200 ? mb_substr($text, 0, 200) . '...' : $text;
    }

    private function getModuleDescription(string $modulePath): string
    {
        $registerFile = rtrim($modulePath, '/\\') . DIRECTORY_SEPARATOR . 'register.php';
        if (!file_exists($registerFile)) {
            return '';
        }
        try {
            $args = Register::parserRegisterFunctionParams($registerFile);
            $desc = $args['description'] ?? '';
            if (is_array($desc)) {
                $desc = implode(' ', $desc);
            }
            return is_string($desc) ? strip_tags(trim($desc, '\'"')) : '';
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * 读取文件内容并转换为 UTF-8
     */
    private function readFileUtf8(string $filePath): string|false
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return false;
        }
        if (empty($content)) {
            return '';
        }
        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }
        $encoding = mb_detect_encoding($content, ['UTF-8', 'GBK', 'GB2312', 'BIG5', 'ISO-8859-1'], true);
        if (!$encoding) {
            $encoding = 'GBK';
        }
        $utf8 = mb_convert_encoding($content, 'UTF-8', (string)$encoding);
        if ($utf8 !== false && mb_check_encoding($utf8, 'UTF-8')) {
            return $utf8;
        }
        return $content;
    }

    // ─── 兼容旧接口 ─────────────────────────────────────────

    /**
     * 扫描单个模块的文档（兼容旧调用方式）
     */
    public function scanModuleDocuments(string $moduleName, string $docPath, string $modulePath, array &$scannedDocumentKeys, array &$scannedCatalogIds = []): array
    {
        $result = ['scanned' => 0, 'new' => 0, 'updated' => 0];

        $topCatalog = $this->ensureCatalog('模块文档', 0, 0, '所有模块的开发文档', 999999);
        $this->seenCatalogIds[(int)$topCatalog->getId()] = true;

        if ($this->isCoreDocumentModule($moduleName)) {
            $coreCatalogId = $this->scanCoreModuleDocuments($docPath, $moduleName, $modulePath, $result);
            $scannedCatalogIds[] = $coreCatalogId;
            foreach ($this->seenDocumentKeys as $key => $_) {
                $scannedDocumentKeys[$key] = true;
            }

            return $result;
        }

        $desc = $this->getModuleDescription($modulePath) ?: __('模块 %{name} 的开发文档', ['name' => $moduleName]);
        $moduleCatalog = $this->ensureCatalog($moduleName, (int)$topCatalog->getId(), 1, $desc);
        $this->seenCatalogIds[(int)$moduleCatalog->getId()] = true;
        $scannedCatalogIds[] = (int)$moduleCatalog->getId();

        $this->scanDirectory($docPath, $moduleName, (int)$moduleCatalog->getId(), $result, '', 'doc');
        $this->scanRootDocuments($modulePath, $moduleName, (int)$moduleCatalog->getId(), $result);

        // 同步到传入的引用参数
        foreach ($this->seenDocumentKeys as $key => $_) {
            $scannedDocumentKeys[$key] = true;
        }

        return $result;
    }
}
