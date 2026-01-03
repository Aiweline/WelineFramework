<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Async\Controller\Backend\Api;

use Weline\Framework\App\Controller\BackendRestController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
use Weline\Async\Model\SyncMapping;
use Weline\Async\Model\SyncHost;
use Weline\Async\Service\WatcherService;

class Mapping extends BackendRestController
{
    private SyncMapping $syncMapping;
    private SyncHost $syncHost;
    private WatcherService $watcherService;

    public function __construct(ObjectManager $objectManager)
    {
        parent::__construct();
        $this->syncMapping = $objectManager->getInstance(SyncMapping::class);
        $this->syncHost = $objectManager->getInstance(SyncHost::class);
        $this->watcherService = $objectManager->getInstance(WatcherService::class);
    }

    /**
     * 获取映射列表
     */
    public function getList()
    {
        try {
            $hostId = $this->request->getParam('host_id');
            
            if (empty($hostId)) {
                return $this->error('主机ID不能为空');
            }
            
            $query = $this->syncMapping->clear();
            $query->where(SyncMapping::fields_HOST_ID, $hostId);
            
            $mappings = $query->order(SyncMapping::fields_CREATED_AT, 'DESC')
                ->select()
                ->fetch()
                ->getItems();

            $data = [];
            foreach ($mappings as $mapping) {
                $mappingId = $mapping->getId();
                $data[] = [
                    'mapping_id' => $mappingId,
                    'host_id' => $mapping->getData(SyncMapping::fields_HOST_ID),
                    'local_path' => $mapping->getData(SyncMapping::fields_LOCAL_PATH),
                    'remote_path' => $mapping->getData(SyncMapping::fields_REMOTE_PATH),
                    'include_paths' => $mapping->getIncludePathsArray(),
                    'exclude_patterns' => $mapping->getExcludePatternsArray(),
                    'status' => (int)$mapping->getData(SyncMapping::fields_STATUS),
                    'is_running' => $this->watcherService->isWatcherRunning($mappingId),
                    'pid' => $this->watcherService->getWatcherPid($mappingId),
                    'created_at' => $mapping->getData(SyncMapping::fields_CREATED_AT),
                ];
            }

            return $this->success('获取成功', $data);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 开启/关闭同步
     */
    public function toggle()
    {
        try {
            $id = $this->request->getParam('id');
            if (empty($id)) {
                return $this->error('映射ID不能为空');
            }

            $mapping = $this->syncMapping->clear()->load($id);
            if (!$mapping->getId()) {
                return $this->error('映射不存在');
            }

            $currentStatus = (int)$mapping->getData(SyncMapping::fields_STATUS);
            $newStatus = $currentStatus === 1 ? 0 : 1;

            $mapping->setData(SyncMapping::fields_STATUS, $newStatus);
            $mapping->save();

            if ($newStatus === 1) {
                // 开启：启动watcher
                $result = $this->watcherService->startWatcher($id);
                if ($result['success']) {
                    return $this->success('同步已开启', ['status' => $newStatus]);
                } else {
                    $mapping->setData(SyncMapping::fields_STATUS, 0);
                    $mapping->save();
                    return $this->error('开启同步失败: ' . $result['message']);
                }
            } else {
                // 关闭：停止watcher
                if ($this->watcherService->isWatcherRunning($id)) {
                    $this->watcherService->stopWatcher($id);
                }
                return $this->success('同步已关闭', ['status' => $newStatus]);
            }
            
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取映射表单HTML
     */
    public function form()
    {
        try {
            $hostId = $this->request->getParam('host_id');
            $id = $this->request->getParam('id');
            
            if (empty($hostId)) {
                return $this->error('主机ID不能为空');
            }

            $host = $this->syncHost->clear()->load($hostId);
            if (!$host->getId()) {
                return $this->error('主机不存在');
            }

            $mapping = null;
            if ($id) {
                $mapping = $this->syncMapping->clear()->load($id);
                if (!$mapping->getId()) {
                    return $this->error('映射不存在');
                }
            }

            // 渲染表单HTML
            $template = ObjectManager::getInstance(Template::class)->init();
            $template->assign('host', $host);
            $template->assign('mapping', $mapping);
            $html = $template->fetch('Weline_Async::templates/backend/mapping/form_offcanvas.phtml');
            
            return $this->success('获取成功', ['html' => $html]);
            
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 保存映射
     */
    public function save()
    {
        try {
            $id = $this->request->getParam('id');
            $hostId = $this->request->getParam('host_id');
            $localPath = trim($this->request->getParam('local_path', ''));
            $remotePath = trim($this->request->getParam('remote_path', ''));
            $includePaths = $this->request->getParam('include_paths', '');
            $excludePatterns = $this->request->getParam('exclude_patterns', '');
            $status = (int)($this->request->getParam('status') ?: 0);

            // 验证必填字段
            if (empty($hostId)) {
                return $this->error('主机ID不能为空');
            }
            if (empty($localPath)) {
                return $this->error('本地路径不能为空');
            }
            if (empty($remotePath)) {
                return $this->error('远程路径不能为空');
            }
            if (!is_dir($localPath)) {
                return $this->error('本地路径不存在或不是目录');
            }

            $host = $this->syncHost->clear()->load($hostId);
            if (!$host->getId()) {
                return $this->error('主机不存在');
            }

            $mapping = $this->syncMapping->clear();
            
            if ($id) {
                // 编辑模式
                $mapping->load($id);
                if (!$mapping->getId()) {
                    return $this->error('映射不存在');
                }
            }

            // 设置数据
            $mapping->setData(SyncMapping::fields_HOST_ID, $hostId);
            $mapping->setData(SyncMapping::fields_LOCAL_PATH, $localPath);
            $mapping->setData(SyncMapping::fields_REMOTE_PATH, $remotePath);
            $mapping->setData(SyncMapping::fields_STATUS, $status);
            
            // 处理包含路径
            if (!empty($includePaths)) {
                $paths = is_array($includePaths) ? $includePaths : explode("\n", $includePaths);
                $paths = array_filter(array_map('trim', $paths));
                $mapping->setIncludePathsArray($paths);
            } else {
                // 如果未指定，清空 include_paths
                $mapping->setIncludePathsArray([]);
            }
            
            // 处理排除模式
            if (!empty($excludePatterns)) {
                $patterns = is_array($excludePatterns) ? $excludePatterns : explode("\n", $excludePatterns);
                $patterns = array_filter(array_map('trim', $patterns));
                $mapping->setExcludePatternsArray($patterns);
            } else {
                // 如果未指定，清空 exclude_patterns
                $mapping->setExcludePatternsArray([]);
            }

            $mapping->save();

            // 如果状态为开启，启动watcher
            if ($status === 1) {
                $result = $this->watcherService->startWatcher($mapping->getId());
                if (!$result['success']) {
                    return $this->success('保存成功，但启动同步失败: ' . $result['message'], ['mapping_id' => $mapping->getId()]);
                }
            } else {
                // 如果状态为关闭，停止watcher
                if ($this->watcherService->isWatcherRunning($mapping->getId())) {
                    $this->watcherService->stopWatcher($mapping->getId());
                }
            }

            return $this->success('保存成功', ['mapping_id' => $mapping->getId()]);
            
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 删除映射
     */
    public function delete()
    {
        try {
            $id = $this->request->getParam('id');
            $hostId = $this->request->getParam('host_id');
            
            if (empty($id)) {
                return $this->error('映射ID不能为空');
            }

            $mapping = $this->syncMapping->clear()->load($id);
            if (!$mapping->getId()) {
                return $this->error('映射不存在');
            }

            // 停止watcher
            if ($this->watcherService->isWatcherRunning($id)) {
                $this->watcherService->stopWatcher($id);
            }

            $mapping->delete();
            return $this->success('删除成功');
            
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取目录树
     */
    public function getDirectoryTree()
    {
        try {
            $path = $this->request->getParam('path');
            $type = $this->request->getParam('type', 'local'); // local or remote
            $maxDepth = (int)$this->request->getParam('max_depth', 1); // 默认只加载第一层
            
            if (empty($path)) {
                return $this->error('路径不能为空');
            }

            if ($type === 'remote') {
                // 远程目录需要SSH连接，这里先返回错误提示
                return $this->error('远程目录浏览功能暂未实现，请手动输入路径');
            }

            // 本地目录
            if (!is_dir($path)) {
                return $this->error('路径不存在或不是目录');
            }

            $tree = $this->buildDirectoryTree($path, $path, $maxDepth);
            
            return $this->success('获取成功', ['tree' => $tree, 'root' => $path]);
            
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取指定目录的直接子目录（用于懒加载）
     */
    public function getDirectoryChildren()
    {
        try {
            $path = $this->request->getParam('path');
            $basePath = $this->request->getParam('base_path', $path);
            
            if (empty($path)) {
                return $this->error('路径不能为空');
            }

            if (!is_dir($path)) {
                return $this->error('路径不存在或不是目录');
            }

            $children = $this->getDirectChildren($path, $basePath);
            
            return $this->success('获取成功', ['children' => $children]);
            
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取目录的直接子目录（不递归）
     */
    private function getDirectChildren(string $currentPath, string $basePath): array
    {
        $children = [];
        
        if (!is_dir($currentPath) || !is_readable($currentPath)) {
            return $children;
        }

        $items = scandir($currentPath);
        if ($items === false) {
            return $children;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $currentPath . DIRECTORY_SEPARATOR . $item;
            $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $fullPath);
            
            // 跳过隐藏文件和系统文件
            if (strpos($item, '.') === 0 && $item !== '.') {
                continue;
            }

            if (is_dir($fullPath)) {
                // 检查是否有子目录（但不加载）
                $hasChildren = false;
                $subItems = scandir($fullPath);
                if ($subItems !== false) {
                    foreach ($subItems as $subItem) {
                        if ($subItem === '.' || $subItem === '..') {
                            continue;
                        }
                        if (strpos($subItem, '.') === 0 && $subItem !== '.') {
                            continue;
                        }
                        $subPath = $fullPath . DIRECTORY_SEPARATOR . $subItem;
                        if (is_dir($subPath)) {
                            $hasChildren = true;
                            break;
                        }
                    }
                }
                
                $node = [
                    'name' => $item,
                    'path' => $fullPath,
                    'relative_path' => $relativePath,
                    'type' => 'directory',
                    'has_children' => $hasChildren,
                    'children' => [] // 懒加载，不在这里加载子目录
                ];
                $children[] = $node;
            }
        }

        // 按名称排序
        usort($children, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        return $children;
    }

    /**
     * 构建目录树
     */
    private function buildDirectoryTree(string $basePath, string $currentPath, int $maxDepth = 1, int $currentDepth = 0): array
    {
        if ($currentDepth >= $maxDepth) {
            return [];
        }

        $tree = [];
        
        if (!is_dir($currentPath) || !is_readable($currentPath)) {
            return $tree;
        }

        $items = scandir($currentPath);
        if ($items === false) {
            return $tree;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $currentPath . DIRECTORY_SEPARATOR . $item;
            $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $fullPath);
            
            // 跳过隐藏文件和系统文件
            if (strpos($item, '.') === 0 && $item !== '.') {
                continue;
            }

            if (is_dir($fullPath)) {
                // 检查是否有子目录（总是检查，不管深度）
                $hasChildren = false;
                $subItems = scandir($fullPath);
                if ($subItems !== false) {
                    foreach ($subItems as $subItem) {
                        if ($subItem === '.' || $subItem === '..') {
                            continue;
                        }
                        if (strpos($subItem, '.') === 0 && $subItem !== '.') {
                            continue;
                        }
                        $subPath = $fullPath . DIRECTORY_SEPARATOR . $subItem;
                        if (is_dir($subPath)) {
                            $hasChildren = true;
                            break;
                        }
                    }
                }
                
                $node = [
                    'name' => $item,
                    'path' => $fullPath,
                    'relative_path' => $relativePath,
                    'type' => 'directory',
                    'has_children' => $hasChildren,
                    'children' => $currentDepth < $maxDepth - 1 
                        ? $this->buildDirectoryTree($basePath, $fullPath, $maxDepth, $currentDepth + 1)
                        : [] // 达到最大深度时不加载子目录，通过懒加载
                ];
                $tree[] = $node;
            }
        }

        // 按名称排序
        usort($tree, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        return $tree;
    }
}
