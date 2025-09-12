<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Observer;

use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Event\Event;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\DeveloperWorkspace\Model\Document\Catalog as CatalogModel;
use Weline\DeveloperWorkspace\Model\Document as DocumentModel;

class ModuleUpgradeObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {

            // 获取模块列表
            $modules = Env::getInstance()->getActiveModules();

        // 准备模型
        $catalogModel = ObjectManager::make(CatalogModel::class);
        $documentModel = ObjectManager::make(DocumentModel::class);

        // 1. 确保只有一个系统分类“模块文档”，删除多余的系统创建的同名分类（及其文档）
        $existing = $catalogModel->reset()
            ->where(CatalogModel::fields_is_system, 1)
            ->where(CatalogModel::fields_NAME, '模块文档')
            ->select()
            ->fetch()
            ->getItems();

        $rootId = 0;
        if (empty($existing)) {
            // 创建根分类
            $root = ObjectManager::make(CatalogModel::class);
            $root->reset()->setData(CatalogModel::fields_NAME, '模块文档')
                ->setData(CatalogModel::fields_DESCRIPTION, '系统导入的模块开发文档')
                ->setData(CatalogModel::fields_PID, 0)
                ->setData('level', 1)
                ->setData(CatalogModel::fields_is_system, 1)
                ->setData(CatalogModel::fields_is_active, 1)
                ->save();
            $rootId = $root->getId();
        } else {
            // 如果有多个取第一个为保留，其它删除（包括文档）
            $keep = $existing[0];
            $rootId = $keep['id'];

            if (count($existing) > 1) {
                foreach ($existing as $rec) {
                    if ($rec['id'] == $rootId) {
                        continue;
                    }
                    // 删除该分类下的文档
                    $documentModel->reset()->where(DocumentModel::fields_CATEGORY_ID, $rec['id'])->delete()->fetch();
                    // 删除分类
                    $tmp = ObjectManager::make(CatalogModel::class);
                    $tmp->load($rec['id']);
                    if ($tmp->getId()) {
                        $tmp->delete();
                    }
                }
            }
        }

        // 2. 扫描模块 doc 目录，构建路径集合与文件列表
        $moduleDocs = [];
        foreach ($modules as $module) {
            $basePath = $module['base_path'] ?? '';
            if (empty($basePath)) {
                continue;
            }
            $docDir = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'doc';
            if (!is_dir($docDir)) {
                continue;
            }

            $files = [];
            $paths = [];

            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($docDir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $fileinfo) {
                /** @var \SplFileInfo $fileinfo */
                if ($fileinfo->isDir()) {
                    continue;
                }
                $ext = strtolower(pathinfo($fileinfo->getFilename(), PATHINFO_EXTENSION));
                if (!in_array($ext, ['md', 'markdown', 'html', 'htm', 'phtml'])) {
                    continue;
                }
                $fullPath = $fileinfo->getRealPath();
                $relative = ltrim(str_replace($docDir, '', $fullPath), DIRECTORY_SEPARATOR);
                $dir = dirname($relative);
                $dir = $dir === '.' ? '' : str_replace(DIRECTORY_SEPARATOR, '/', $dir);
                // 路径集合（相对路径）
                if ($dir !== '') {
                    // split into hierarchical paths
                    $segments = explode('/', $dir);
                    $acc = '';
                    foreach ($segments as $seg) {
                        $acc = $acc === '' ? $seg : ($acc . '/' . $seg);
                        $paths[] = $acc;
                    }
                }
                $files[] = [
                    'full' => $fullPath,
                    'relative' => $relative,
                    'dir' => $dir,
                    'name' => $fileinfo->getFilename()
                ];
            }

            $paths = array_values(array_unique($paths));
            $files = array_values($files);

            if (empty($paths) && empty($files)) {
                continue;
            }

            $moduleDocs[$module['name']] = [
                'doc_dir' => $docDir,
                'paths' => $paths,
                'files' => $files
            ];
        }

        if (empty($moduleDocs)) {
            return; // 无文档可处理
        }

        // 3. 对每个模块构建分类（模块名作为一级分类），并逐级创建子分类，生成映射关系
        $mapping = [];
        foreach ($moduleDocs as $moduleName => $info) {
            // 先确保模块根分类存在
            $moduleCat = ObjectManager::make(CatalogModel::class);
            $found = $moduleCat->reset()->where(CatalogModel::fields_NAME, $moduleName)->where(CatalogModel::fields_PID, $rootId)->find()->fetch();
            $moduleCatId = $found->getId() ?: 0;
            if (!$moduleCatId) {
                $moduleCat = ObjectManager::make(CatalogModel::class);
                $moduleCat->setData(CatalogModel::fields_NAME, $moduleName)
                    ->setData(CatalogModel::fields_DESCRIPTION, "模块文档: {$moduleName}")
                    ->setData(CatalogModel::fields_PID, $rootId)
                    ->setData('level', 2)
                    ->setData(CatalogModel::fields_is_system, 1)
                    ->setData(CatalogModel::fields_is_active, 1)
                    ->save();
                $moduleCatId = $moduleCat->getId();
            }

            $mapping[$moduleName] = [];
            // 根映射
            $mapping[$moduleName]["{$moduleName}"] = $moduleCatId;

            // 逐级创建子分类
            foreach ($info['paths'] as $path) {
                $segments = explode('/', $path);
                $parentId = $moduleCatId;
                $acc = $moduleName;
                $level = 3; // module root is level 2
                foreach ($segments as $seg) {
                    $acc = $acc . '/' . $seg;
                    // 检查是否存在该分类（同名且父级为 parentId）
                    $tmp = ObjectManager::make(CatalogModel::class);
                    $found = $tmp->reset()->where(CatalogModel::fields_NAME, $seg)->where(CatalogModel::fields_PID, $parentId)->find()->fetch();
                    $foundId = $found->getId() ?: 0;
                    if (!$foundId) {
                        $tmp = ObjectManager::make(CatalogModel::class);
                        $tmp->setData(CatalogModel::fields_NAME, $seg)
                            ->setData(CatalogModel::fields_DESCRIPTION, "模块文档: {$moduleName}/{$path}")
                            ->setData(CatalogModel::fields_PID, $parentId)
                            ->setData('level', $level)
                            ->setData(CatalogModel::fields_is_system, 1)
                            ->setData(CatalogModel::fields_is_active, 1)
                            ->save();
                        $foundId = $tmp->getId();
                    }
                    // 存储映射
                    $mapping[$moduleName][$acc] = $foundId;
                    // 下一层
                    $parentId = $foundId;
                    $level++;
                }
            }
        }

        // 4. 导入文档并关联分类
        foreach ($moduleDocs as $moduleName => $info) {
            foreach ($info['files'] as $file) {
                $relDir = $file['dir'];
                $key = $moduleName;
                if ($relDir !== '') {
                    $key = $moduleName . '/' . $relDir;
                }
                // 如果映射中没有精确目录，尝试逐级回退到最近存在的父目录映射
                $catId = $mapping[$moduleName][$key] ?? 0;
                if (!$catId) {
                    // 回退尝试
                    $parts = $relDir === '' ? [] : explode('/', $relDir);
                    while ($parts) {
                        array_pop($parts);
                        $tryKey = $moduleName . (empty($parts) ? '' : '/' . implode('/', $parts));
                        if (isset($mapping[$moduleName][$tryKey])) {
                            $catId = $mapping[$moduleName][$tryKey];
                            break;
                        }
                    }
                }
                if (!$catId) {
                    // fallback: module root
                    $catId = $mapping[$moduleName]["{$moduleName}"] ?? 0;
                }

                if (!$catId) {
                    continue; // 无法找到分类，跳过
                }

                // 读取文件内容并创建文档
                $content = @file_get_contents($file['full']);
                if ($content === false) {
                    continue;
                }

                $title = pathinfo($file['name'], PATHINFO_FILENAME);
                $doc = new DocumentModel();
                $doc->setTitle($title)
                    ->setContent($content)
                    ->setCategoryId((string)$catId)
                    ->setAuthorID(0)
                    ->setData('summary', '')
                    ->save();
            }
        }
    }
}


