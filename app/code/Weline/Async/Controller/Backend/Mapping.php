<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Async\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Async\Model\SyncHost;
use Weline\Async\Model\SyncMapping;
use Weline\Async\Service\WatcherService;

class Mapping extends BackendController
{
    /**
     * SSH错误信息（临时存储）
     * @var string|null
     */
    private ?string $sshErrorInfo = null;
    private SyncHost $syncHost;
    private SyncMapping $syncMapping;
    private WatcherService $watcherService;

    public function __construct(ObjectManager $objectManager)
    {
        $this->syncHost = $objectManager->getInstance(SyncHost::class);
        $this->syncMapping = $objectManager->getInstance(SyncMapping::class);
        $this->watcherService = $objectManager->getInstance(WatcherService::class);
    }

    /**
     * 映射列表（属于主机）
     */
    public function index()
    {
        $hostId = $this->request->getParam('host_id');
        if (empty($hostId)) {
            $this->getMessageManager()->addError(__('主机ID不能为空'));
            return $this->redirect('async/backend/host');
        }

        $host = $this->syncHost->clear()->load($hostId);
        if (!$host->getId()) {
            $this->getMessageManager()->addError(__('主机不存在'));
            return $this->redirect('async/backend/host');
        }

        $mappings = $this->syncMapping->clear()
            ->where(SyncMapping::fields_HOST_ID, $hostId)
            ->order(SyncMapping::fields_CREATED_AT, 'DESC')
            ->select()
            ->fetch()
            ->getItems();

        // 获取每个映射的运行状态
        foreach ($mappings as $mapping) {
            $mapping->setData('is_running', $this->watcherService->isWatcherRunning($mapping->getId()));
            $mapping->setData('pid', $this->watcherService->getWatcherPid($mapping->getId()));
        }

        $this->assign('host', $host);
        $this->assign('mappings', $mappings);
        return $this->fetch();
    }

    /**
     * 获取映射列表（JSON API）
     */
    public function getList()
    {
        try {
            $hostId = $this->request->getParam('host_id');
            
            if (empty($hostId)) {
                return $this->fetchJson(['code' => 400, 'msg' => __('主机ID不能为空'), 'data' => null]);
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

            return $this->fetchJson(['code' => 200, 'msg' => __('获取成功'), 'data' => $data]);
        } catch (\Exception $e) {
            return $this->fetchJson(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }

    /**
     * 新增/编辑映射表单
     */
    public function form()
    {
        try {
            // 检查是否是 AJAX 请求（通过请求头或参数）
            $xRequestedWith = strtolower($this->request->getServer('HTTP_X_REQUESTED_WITH') ?? '');
            $isAjaxParam = $this->request->getParam('isAjax');
            $isAjax = $this->request->isAjax() || 
                      $xRequestedWith === 'xmlhttprequest' ||
                      $isAjaxParam === '1' || $isAjaxParam === 1;
            
            $hostId = $this->request->getParam('host_id');
            $id = $this->request->getParam('id');
            
            if (empty($hostId)) {
                // 如果是 AJAX 请求，返回 JSON
                if ($isAjax) {
                    return $this->fetchJson(['code' => 400, 'msg' => __('主机ID不能为空'), 'data' => null]);
                }
                $this->getMessageManager()->addError(__('主机ID不能为空'));
                return $this->redirect('async/backend/host');
            }

            $host = $this->syncHost->clear()->load($hostId);
            if (!$host->getId()) {
                // 如果是 AJAX 请求，返回 JSON
                if ($isAjax) {
                    return $this->fetchJson(['code' => 404, 'msg' => __('主机不存在'), 'data' => null]);
                }
                $this->getMessageManager()->addError(__('主机不存在'));
                return $this->redirect('async/backend/host');
            }

            $mapping = null;
            if ($id) {
                $mapping = $this->syncMapping->clear()->load($id);
                if (!$mapping->getId()) {
                    // 如果是 AJAX 请求，返回 JSON
                    if ($isAjax) {
                        return $this->fetchJson(['code' => 404, 'msg' => __('映射不存在'), 'data' => null]);
                    }
                    $this->getMessageManager()->addError(__('映射不存在'));
                    return $this->redirect('async/backend/mapping?host_id=' . $hostId);
                }
            }

            // 如果是 AJAX 请求，返回 JSON（包含 HTML）
            if ($isAjax) {
                $this->assign('host', $host);
                $this->assign('mapping', $mapping);
                
                // 捕获模板渲染错误
                try {
                    $html = $this->fetch('Weline_Async::templates/backend/mapping/form_offcanvas_v2.phtml');
                } catch (\Exception $templateException) {
                    return $this->fetchJson([
                        'code' => 500, 
                        'msg' => __('模板渲染失败: ') . $templateException->getMessage(), 
                        'data' => null,
                        'trace' => DEV ? $templateException->getTraceAsString() : null
                    ]);
                }
                
                return $this->fetchJson(['code' => 200, 'msg' => __('获取成功'), 'data' => ['html' => $html]]);
            }

            $this->assign('host', $host);
            $this->assign('mapping', $mapping);
            return $this->fetch();
            
        } catch (\Exception $e) {
            // 检查是否是 AJAX 请求
            $xRequestedWith = strtolower($this->request->getServer('HTTP_X_REQUESTED_WITH') ?? '');
            $isAjaxParam = $this->request->getParam('isAjax');
            $isAjax = $this->request->isAjax() || 
                      $xRequestedWith === 'xmlhttprequest' ||
                      $isAjaxParam === '1' || $isAjaxParam === 1;
            
            // 如果是 AJAX 请求，返回 JSON 错误
            if ($isAjax) {
                return $this->fetchJson([
                    'code' => 500, 
                    'msg' => __('加载失败: ') . $e->getMessage(), 
                    'data' => null,
                    'trace' => DEV ? $e->getTraceAsString() : null
                ]);
            }
            // 非 AJAX 请求，显示错误消息
            $this->getMessageManager()->addError(__('加载失败: ') . $e->getMessage());
            $hostId = $this->request->getParam('host_id');
            return $this->redirect('async/backend/mapping?host_id=' . ($hostId ?: ''));
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
            $remotePath = trim($this->request->getParam('remote_path', '')); // 兼容旧数据
            $remotePathsParam = $this->request->getParam('remote_paths', ''); // 新的多个远程路径
            $includePaths = $this->request->getParam('include_paths', '');
            $excludePatterns = $this->request->getParam('exclude_patterns', '');
            $status = (int)($this->request->getParam('status') ?: 0);

            // 验证必填字段
            if (empty($hostId)) {
                throw new \RuntimeException(__('主机ID不能为空'));
            }
            if (empty($localPath)) {
                throw new \RuntimeException(__('本地路径不能为空'));
            }
            if (!is_dir($localPath)) {
                throw new \RuntimeException(__('本地路径不存在或不是目录'));
            }
            
            // 处理远程路径：优先使用 remote_paths，其次使用 remote_path
            $remotePaths = [];
            if (!empty($remotePathsParam)) {
                if (is_string($remotePathsParam)) {
                    $decoded = json_decode($remotePathsParam, true);
                    if (is_array($decoded)) {
                        $remotePaths = array_filter(array_map('trim', $decoded));
                    } else {
                        // 如果是逗号分隔的字符串
                        $remotePaths = array_filter(array_map('trim', explode(',', $remotePathsParam)));
                    }
                } elseif (is_array($remotePathsParam)) {
                    $remotePaths = array_filter(array_map('trim', $remotePathsParam));
                }
            }
            
            // 如果没有 remote_paths，使用 remote_path（兼容旧数据）
            if (empty($remotePaths) && !empty($remotePath)) {
                $remotePaths = [$remotePath];
            }
            
            // 如果都没有，使用本地路径作为远程路径（相同路径映射）
            if (empty($remotePaths)) {
                $remotePaths = [$localPath];
            }
            
            if (empty($remotePaths)) {
                throw new \RuntimeException(__('至少需要一个远程路径'));
            }

            $host = $this->syncHost->clear()->load($hostId);
            if (!$host->getId()) {
                throw new \RuntimeException(__('主机不存在'));
            }

            $mapping = $this->syncMapping->clear();
            
            if ($id) {
                // 编辑模式
                $mapping->load($id);
                if (!$mapping->getId()) {
                    throw new \RuntimeException(__('映射不存在'));
                }
            }

            // 设置数据
            $mapping->setData(SyncMapping::fields_HOST_ID, $hostId);
            $mapping->setData(SyncMapping::fields_LOCAL_PATH, $localPath);
            $mapping->setRemotePathsArray($remotePaths); // 设置多个远程路径
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
                    // 如果是 AJAX 请求，返回 JSON
                    if ($this->request->isAjax()) {
                        return $this->fetchJson(['code' => 200, 'msg' => __('保存成功，但启动同步失败: ') . $result['message'], 'data' => ['mapping_id' => $mapping->getId()]]);
                    }
                    $this->getMessageManager()->addWarning(__('保存成功，但启动同步失败: ') . $result['message']);
                }
            } else {
                // 如果状态为关闭，停止watcher
                if ($this->watcherService->isWatcherRunning($mapping->getId())) {
                    $this->watcherService->stopWatcher($mapping->getId());
                }
            }

            // 如果是 AJAX 请求，返回 JSON
            if ($this->request->isAjax()) {
                return $this->fetchJson(['code' => 200, 'msg' => __('保存成功'), 'data' => ['mapping_id' => $mapping->getId()]]);
            }

            $this->getMessageManager()->addSuccess(__('保存成功'));
            return $this->redirect('async/backend/mapping?host_id=' . $hostId);
            
        } catch (\Exception $e) {
            // 如果是 AJAX 请求，返回 JSON
            if ($this->request->isAjax()) {
                return $this->fetchJson(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
            }
            $this->getMessageManager()->addError($e->getMessage());
            $hostId = $this->request->getParam('host_id');
            return $this->redirect('async/backend/mapping/form?host_id=' . $hostId . ($id ? '&id=' . $id : ''));
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
                throw new \RuntimeException(__('映射ID不能为空'));
            }

            $mapping = $this->syncMapping->clear()->load($id);
            if (!$mapping->getId()) {
                throw new \RuntimeException(__('映射不存在'));
            }

            $hostId = $hostId ?: $mapping->getData(SyncMapping::fields_HOST_ID);

            // 停止watcher
            if ($this->watcherService->isWatcherRunning($id)) {
                $this->watcherService->stopWatcher($id);
            }

            $mapping->delete();
            
            // 如果是 AJAX 请求，返回 JSON
            if ($this->request->isAjax()) {
                return $this->fetchJson(['code' => 200, 'msg' => __('删除成功'), 'data' => null]);
            }
            
            $this->getMessageManager()->addSuccess(__('删除成功'));
            
        } catch (\Exception $e) {
            // 如果是 AJAX 请求，返回 JSON
            if ($this->request->isAjax()) {
                return $this->fetchJson(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
            }
            $this->getMessageManager()->addError($e->getMessage());
        }
        
        return $this->redirect('async/backend/mapping?host_id=' . $hostId);
    }

    /**
     * 开启/关闭同步
     */
    public function toggle()
    {
        try {
            $id = $this->request->getParam('id');
            $hostId = $this->request->getParam('host_id');
            
            if (empty($id)) {
                throw new \RuntimeException(__('映射ID不能为空'));
            }

            $mapping = $this->syncMapping->clear()->load($id);
            if (!$mapping->getId()) {
                throw new \RuntimeException(__('映射不存在'));
            }

            $hostId = $hostId ?: $mapping->getData(SyncMapping::fields_HOST_ID);

            $currentStatus = (int)$mapping->getData(SyncMapping::fields_STATUS);
            $newStatus = $currentStatus === 1 ? 0 : 1;

            $mapping->setData(SyncMapping::fields_STATUS, $newStatus);
            $mapping->save();

            if ($newStatus === 1) {
                // 开启：启动watcher
                $result = $this->watcherService->startWatcher($id);
                if ($result['success']) {
                    // 如果是 AJAX 请求，返回 JSON
                    if ($this->request->isAjax()) {
                        return $this->fetchJson(['code' => 200, 'msg' => __('同步已开启'), 'data' => ['status' => $newStatus]]);
                    }
                    $this->getMessageManager()->addSuccess(__('同步已开启'));
                } else {
                    $mapping->setData(SyncMapping::fields_STATUS, 0);
                    $mapping->save();
                    throw new \RuntimeException(__('开启同步失败: ') . $result['message']);
                }
            } else {
                // 关闭：停止watcher
                if ($this->watcherService->isWatcherRunning($id)) {
                    $this->watcherService->stopWatcher($id);
                }
                // 如果是 AJAX 请求，返回 JSON
                if ($this->request->isAjax()) {
                    return $this->fetchJson(['code' => 200, 'msg' => __('同步已关闭'), 'data' => ['status' => $newStatus]]);
                }
                $this->getMessageManager()->addSuccess(__('同步已关闭'));
            }
            
        } catch (\Exception $e) {
            // 如果是 AJAX 请求，返回 JSON
            if ($this->request->isAjax()) {
                return $this->fetchJson(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
            }
            $this->getMessageManager()->addError($e->getMessage());
        }
        
        return $this->redirect('async/backend/mapping?host_id=' . $hostId);
    }

    /**
     * 获取目录树（JSON API）
     */
    public function getDirectoryTree()
    {
        try {
            $path = $this->request->getParam('path');
            $type = $this->request->getParam('type', 'local'); // local or remote
            $maxDepth = (int)$this->request->getParam('max_depth', 1); // 默认只加载第一层
            
            if (empty($path)) {
                return $this->fetchJson(['code' => 400, 'msg' => __('路径不能为空'), 'data' => null]);
            }

            if ($type === 'remote') {
                // 远程目录需要SSH连接
                $hostId = $this->request->getParam('host_id');
                if (empty($hostId)) {
                    return $this->fetchJson(['code' => 400, 'msg' => __('主机ID不能为空'), 'data' => null]);
                }
                
                $host = $this->syncHost->clear()->load($hostId);
                if (!$host->getId()) {
                    return $this->fetchJson(['code' => 404, 'msg' => __('主机不存在'), 'data' => null]);
                }
                
                $this->sshErrorInfo = null; // 清除之前的错误信息
                $tree = $this->buildRemoteDirectoryTree($host, $path, $maxDepth);
                $errorInfo = $this->sshErrorInfo; // 获取SSH错误信息
                
                // 如果树为空且没有错误信息，可能是目录确实为空
                // 如果有错误信息，返回错误
                if (empty($tree) && !empty($errorInfo)) {
                    return $this->fetchJson([
                        'code' => 500, 
                        'msg' => __('SSH连接失败'), 
                        'data' => [
                            'tree' => [], 
                            'root' => $path,
                            'is_empty' => true,
                            'error' => $errorInfo
                        ]
                    ]);
                }
                
                // 即使树为空，也返回成功（可能是目录确实为空）
                return $this->fetchJson([
                    'code' => 200, 
                    'msg' => __('获取成功'), 
                    'data' => [
                        'tree' => $tree, 
                        'root' => $path,
                        'is_empty' => empty($tree) // 标识是否为空目录
                    ]
                ]);
            }

            // 本地目录
            if (!is_dir($path)) {
                return $this->fetchJson(['code' => 400, 'msg' => __('路径不存在或不是目录'), 'data' => null]);
            }

            $tree = $this->buildDirectoryTree($path, $path, $maxDepth);
            
            return $this->fetchJson(['code' => 200, 'msg' => __('获取成功'), 'data' => ['tree' => $tree, 'root' => $path]]);
            
        } catch (\Exception $e) {
            return $this->fetchJson(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
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
                return $this->fetchJson(['code' => 400, 'msg' => __('路径不能为空'), 'data' => null]);
            }

            if (!is_dir($path)) {
                return $this->fetchJson(['code' => 400, 'msg' => __('路径不存在或不是目录'), 'data' => null]);
            }

            $children = $this->getDirectChildren($path, $basePath);
            
            return $this->fetchJson(['code' => 200, 'msg' => __('获取成功'), 'data' => ['children' => $children]]);
            
        } catch (\Exception $e) {
            return $this->fetchJson(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
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

    /**
     * 构建远程目录树（通过SSH）
     */
    private function buildRemoteDirectoryTree(SyncHost $host, string $remotePath, int $maxDepth = 1, int $currentDepth = 0): array
    {
        if ($currentDepth >= $maxDepth) {
            return [];
        }

        $tree = [];
        $this->sshErrorInfo = null; // 清除之前的错误信息
        
        try {
            // 构建SSH命令来列出远程目录
            $hostAddress = $host->getData(SyncHost::fields_HOST);
            $port = $host->getData(SyncHost::fields_PORT) ?: 22;
            $user = $host->getData(SyncHost::fields_USER);
            $password = $host->getDecryptedPassword();
            $keyContent = $host->getDecryptedKeyContent();
            
            // 构建SSH选项
            $sshOptions = [];
            $keyFile = null;
            if (!empty($keyContent)) {
                // 清理密钥内容：去除首尾空白，但保留中间内容
                $keyContent = trim($keyContent);
                
                // 验证密钥格式（应该包含 BEGIN 和 END 标记）
                $isValidKey = false;
                if (strpos($keyContent, '-----BEGIN') !== false && strpos($keyContent, '-----END') !== false) {
                    $isValidKey = true;
                } elseif (strpos($keyContent, 'ssh-') === 0) {
                    // 可能是公钥格式，但我们需要私钥
                    w_log_warning("SSH key appears to be a public key, private key required");
                }
                
                if (!$isValidKey) {
                    w_log_warning("Invalid SSH key format for host {$host->getId()}. Key should contain BEGIN/END markers.");
                }
                
                // 创建临时密钥文件
                $keyDir = BP . DS . 'var' . DS . 'async' . DS . 'keys';
                if (!is_dir($keyDir)) {
                    mkdir($keyDir, 0700, true);
                }
                $keyFile = $keyDir . DS . 'browse_' . $host->getId() . '_' . time() . '.pem';
                
                // 确保密钥内容以换行符结尾（SSH要求）
                if (!preg_match('/\n$/', $keyContent)) {
                    $keyContent .= "\n";
                }
                
                $writeResult = file_put_contents($keyFile, $keyContent);
                if ($writeResult === false) {
                    w_log_error("Failed to write SSH key file: {$keyFile}");
                } else {
                    chmod($keyFile, 0600);
                    // 验证文件权限
                    $filePerms = substr(sprintf('%o', fileperms($keyFile)), -4);
                    if ($filePerms !== '0600') {
                        w_log_warning("SSH key file permissions incorrect: {$filePerms}, expected 0600");
                        chmod($keyFile, 0600); // 再次尝试设置
                    }
                    $sshOptions[] = '-i ' . escapeshellarg($keyFile);
                }
            }
            $sshOptions[] = '-o StrictHostKeyChecking=no';
            $sshOptions[] = '-o UserKnownHostsFile=/dev/null';
            $sshOptions[] = '-o ConnectTimeout=5';
            
            // 构建SSH命令：列出目录内容
            // 使用更可靠的命令：先检查目录是否存在，然后列出子目录
            $escapedPath = escapeshellarg($remotePath);
            
            // 方法1：使用 find 命令（更可靠）
            $remoteCmd = "find {$escapedPath} -maxdepth 1 -type d ! -path {$escapedPath} 2>/dev/null | sort";
            
            $sshCmd = sprintf(
                'ssh %s -p %d %s@%s %s',
                implode(' ', $sshOptions),
                $port,
                escapeshellarg($user),
                escapeshellarg($hostAddress),
                escapeshellarg($remoteCmd)
            );
            
            // 如果使用密码认证（且没有密钥内容）
            if (!empty($password) && empty($keyContent)) {
                // 构建不包含密钥文件的SSH选项
                $passwordSshOptions = [];
                $passwordSshOptions[] = '-o StrictHostKeyChecking=no';
                $passwordSshOptions[] = '-o UserKnownHostsFile=/dev/null';
                $passwordSshOptions[] = '-o ConnectTimeout=5';
                
                // 使用sshpass（如果可用）
                $sshCmd = sprintf(
                    'sshpass -p %s ssh %s -p %d %s@%s %s',
                    escapeshellarg($password),
                    implode(' ', $passwordSshOptions),
                    $port,
                    escapeshellarg($user),
                    escapeshellarg($hostAddress),
                    escapeshellarg($remoteCmd)
                );
            }
            
            $output = [];
            $errorOutput = [];
            $returnVar = 0;
            
            // 执行命令并捕获输出和错误
            exec($sshCmd . ' 2>&1', $output, $returnVar);
            
            // 如果 find 命令失败（可能目录不存在或没有权限），尝试 ls 命令
            if ($returnVar !== 0 || empty($output)) {
                // 方法2：使用 ls 命令
                $remoteCmd2 = "ls -1d {$escapedPath}/*/ 2>/dev/null | sort";
                $sshCmd2 = sprintf(
                    'ssh %s -p %d %s@%s %s',
                    implode(' ', $sshOptions),
                    $port,
                    escapeshellarg($user),
                    escapeshellarg($hostAddress),
                    escapeshellarg($remoteCmd2)
                );
                
                // 如果使用密码认证（且没有密钥内容）
                if (!empty($password) && empty($keyContent)) {
                    // 构建不包含密钥文件的SSH选项
                    $passwordSshOptions2 = [];
                    $passwordSshOptions2[] = '-o StrictHostKeyChecking=no';
                    $passwordSshOptions2[] = '-o UserKnownHostsFile=/dev/null';
                    $passwordSshOptions2[] = '-o ConnectTimeout=5';
                    
                    $sshCmd2 = sprintf(
                        'sshpass -p %s ssh %s -p %d %s@%s %s',
                        escapeshellarg($password),
                        implode(' ', $passwordSshOptions2),
                        $port,
                        escapeshellarg($user),
                        escapeshellarg($hostAddress),
                        escapeshellarg($remoteCmd2)
                    );
                }
                
                exec($sshCmd2 . ' 2>&1', $output, $returnVar);
            }
            
            // 如果还是失败，可能是目录为空或没有子目录，这是正常的，返回空数组
            // 但我们需要记录错误信息以便调试
            if ($returnVar !== 0 && !empty($output)) {
                // 检查是否是"没有匹配"的错误（这是正常的，表示目录为空）
                $errorMsg = implode("\n", $output);
                if (strpos($errorMsg, 'No such file') !== false || 
                    strpos($errorMsg, 'Permission denied') !== false ||
                    strpos($errorMsg, 'Connection') !== false ||
                    strpos($errorMsg, 'Host key verification failed') !== false) {
                    // 真正的错误，记录详细日志
                    w_log_error("SSH directory listing failed for {$remotePath}");
                    w_log_debug("SSH Command: " . substr($sshCmd, 0, 200)); // 记录命令前200字符
                    w_log_error("SSH Error: {$errorMsg}");
                    
                    $debugInfo = [];
                    $debugInfo[] = "SSH错误: {$errorMsg}";
                    
                    if (!empty($keyFile)) {
                        $keyExists = file_exists($keyFile);
                        w_log_debug("Key file exists: " . ($keyExists ? 'yes' : 'no'));
                        $debugInfo[] = "密钥文件存在: " . ($keyExists ? '是' : '否');
                        
                        if ($keyExists) {
                            $keySize = filesize($keyFile);
                            $keyPerms = substr(sprintf('%o', fileperms($keyFile)), -4);
                            w_log_debug("Key file size: {$keySize} bytes");
                            w_log_debug("Key file permissions: {$keyPerms}");
                            $debugInfo[] = "密钥文件大小: {$keySize} 字节";
                            $debugInfo[] = "密钥文件权限: {$keyPerms}";
                            
                            // 读取密钥文件前100字符用于调试（不包含敏感内容）
                            $keyPreview = file_get_contents($keyFile, false, null, 0, 100);
                            $preview = substr($keyPreview, 0, 50) . "...";
                            w_log_debug("Key file preview: {$preview}");
                            $debugInfo[] = "密钥文件预览: {$preview}";
                        }
                    } else {
                        $debugInfo[] = "未指定密钥文件";
                        // 检查是否有密钥内容但未创建文件
                        if (empty($keyContent)) {
                            $debugInfo[] = "密钥内容为空（数据库中可能没有保存密钥）";
                            $debugInfo[] = "请检查主机配置中的SSH密钥是否正确保存";
                        } else {
                            $debugInfo[] = "密钥内容存在但文件创建失败";
                        }
                    }
                    
                    // 保存错误信息供调用者使用
                    $this->sshErrorInfo = implode("\n", $debugInfo);
                    
                    // 返回空树
                    return $tree;
                }
                // 其他情况可能是正常的"没有子目录"，继续处理
            }
            
            if (!empty($output)) {
                foreach ($output as $line) {
                    $line = trim($line);
                    if (empty($line)) {
                        continue;
                    }
                    
                    // 提取目录名
                    $dirPath = rtrim($line, '/');
                    $dirName = basename($dirPath);
                    
                    // 跳过隐藏目录
                    if (strpos($dirName, '.') === 0 && $dirName !== '.') {
                        continue;
                    }
                    
                    // 检查是否有子目录（通过再次SSH查询）
                    $hasChildren = false;
                    if ($currentDepth < $maxDepth - 1) {
                        // 构建检查命令的SSH选项（需要重新构建，因为可能使用密钥文件）
                        $checkSshOptions = [];
                        if (!empty($keyFile) && file_exists($keyFile)) {
                            // 使用临时密钥文件
                            $checkSshOptions[] = '-i ' . escapeshellarg($keyFile);
                        }
                        $checkSshOptions[] = '-o StrictHostKeyChecking=no';
                        $checkSshOptions[] = '-o UserKnownHostsFile=/dev/null';
                        $checkSshOptions[] = '-o ConnectTimeout=5';
                        
                        $checkCmd = sprintf(
                            'ssh %s -p %d %s@%s %s',
                            implode(' ', $checkSshOptions),
                            $port,
                            escapeshellarg($user),
                            escapeshellarg($hostAddress),
                            escapeshellarg("test -d {$dirPath}/*/ && echo yes || echo no")
                        );
                        
                        // 如果使用密码认证（且没有密钥文件）
                        if (!empty($password) && empty($keyFile)) {
                            $checkCmd = sprintf(
                                'sshpass -p %s ssh %s -p %d %s@%s %s',
                                escapeshellarg($password),
                                implode(' ', $checkSshOptions),
                                $port,
                                escapeshellarg($user),
                                escapeshellarg($hostAddress),
                                escapeshellarg("test -d {$dirPath}/*/ && echo yes || echo no")
                            );
                        }
                        
                        $checkOutput = [];
                        exec($checkCmd . ' 2>&1', $checkOutput, $checkReturn);
                        $hasChildren = !empty($checkOutput) && trim($checkOutput[0]) === 'yes';
                    }
                    
                    $relativePath = str_replace($remotePath . '/', '', $dirPath);
                    
                    $node = [
                        'name' => $dirName,
                        'path' => $dirPath,
                        'relative_path' => $relativePath,
                        'type' => 'directory',
                        'has_children' => $hasChildren,
                        'children' => $currentDepth < $maxDepth - 1 
                            ? $this->buildRemoteDirectoryTree($host, $dirPath, $maxDepth, $currentDepth + 1)
                            : []
                    ];
                    $tree[] = $node;
                }
            }
            
        } catch (\Exception $e) {
            // 记录错误但不抛出异常，返回空树
            w_log_error("buildRemoteDirectoryTree exception for {$remotePath}: " . $e->getMessage());
        } finally {
            // 确保清理临时密钥文件（无论是否发生异常）
            if (!empty($keyFile) && file_exists($keyFile)) {
                @unlink($keyFile);
            }
        }
        
        // 按名称排序
        usort($tree, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        
        return $tree;
    }

    /**
     * 启动watcher（JSON API）
     */
    public function start()
    {
        try {
            $mappingId = $this->request->getParam('mapping_id');
            
            if (empty($mappingId)) {
                return $this->fetchJson(['code' => 400, 'msg' => __('映射ID不能为空'), 'data' => null]);
            }

            $result = $this->watcherService->startWatcher($mappingId);
            
            if ($result['success']) {
                return $this->fetchJson(['code' => 200, 'msg' => $result['message'] ?? __('启动成功'), 'data' => $result]);
            } else {
                return $this->fetchJson(['code' => 500, 'msg' => $result['message'] ?? __('启动失败'), 'data' => null]);
            }
        } catch (\Exception $e) {
            return $this->fetchJson(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }

    /**
     * 停止watcher（JSON API）
     */
    public function stop()
    {
        try {
            $mappingId = $this->request->getParam('mapping_id');
            
            if (empty($mappingId)) {
                return $this->fetchJson(['code' => 400, 'msg' => __('映射ID不能为空'), 'data' => null]);
            }

            $result = $this->watcherService->stopWatcher($mappingId);
            
            if ($result['success']) {
                return $this->fetchJson(['code' => 200, 'msg' => $result['message'] ?? __('停止成功'), 'data' => $result]);
            } else {
                return $this->fetchJson(['code' => 500, 'msg' => $result['message'] ?? __('停止失败'), 'data' => null]);
            }
        } catch (\Exception $e) {
            return $this->fetchJson(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }

    /**
     * 重启watcher（JSON API）
     */
    public function restart()
    {
        try {
            $mappingId = $this->request->getParam('mapping_id');
            
            if (empty($mappingId)) {
                return $this->fetchJson(['code' => 400, 'msg' => __('映射ID不能为空'), 'data' => null]);
            }

            $result = $this->watcherService->restartWatcher($mappingId);
            
            if ($result['success']) {
                return $this->fetchJson(['code' => 200, 'msg' => $result['message'] ?? __('重启成功'), 'data' => $result]);
            } else {
                return $this->fetchJson(['code' => 500, 'msg' => $result['message'] ?? __('重启失败'), 'data' => null]);
            }
        } catch (\Exception $e) {
            return $this->fetchJson(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }
}
