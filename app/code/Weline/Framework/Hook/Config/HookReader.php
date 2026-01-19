<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Hook\Config;

use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Hook\Cache\HookCache;
use Weline\Framework\System\File\Scanner;
use Weline\Framework\System\ModuleFileReader;

class HookReader extends ModuleFileReader
{
    private CacheInterface $hookCache;
    protected string $path = 'hooks';
    
    /**
     * 静态缓存：在同一个请求中缓存已查询的 hook 文件列表
     * 避免在模板编译阶段重复扫描文件系统
     */
    private static array $staticFileListCache = [];

    public function __construct(HookCache $cache, Scanner $scanner, string $path = 'view' . DS . 'hooks')
    {
        $this->hookCache = $cache->create();
        parent::__construct($scanner, $path);
    }

    public function getFileList(?\Closure $callback = null): array
    {
        $hookName = $this->getHookNameFromPath();
        $cache_key = 'hooks::' . $this->getPath();
        
        // 优化1：先检查静态缓存（同一请求内）
        if (isset(self::$staticFileListCache[$cache_key])) {
            $data = self::$staticFileListCache[$cache_key];
            // 过滤掉禁用的模块的Hook文件，并按顺序返回
            return $this->filterAndSortHooks($data);
        }
        
        // 优化2：从注册表读取实现文件信息（新方式）
        $data = $this->getHookFilesFromRegistry($hookName);
        if (!empty($data)) {
            // 更新静态缓存
            self::$staticFileListCache[$cache_key] = $data;
            // 过滤掉禁用的模块的Hook文件，并按顺序返回
            return $this->filterAndSortHooks($data);
        }
        
        // 优化3：检查持久化缓存（跨请求，向后兼容）
        if ($data = $this->hookCache->get($cache_key)) {
            // 同时更新静态缓存
            self::$staticFileListCache[$cache_key] = $data;
            // 过滤掉禁用的模块的Hook文件，并按顺序返回
            return $this->filterAndSortHooks($data);
        }
        
        // 如果都没有，返回空数组
        self::$staticFileListCache[$cache_key] = [];
        return [];
    }
    
    /**
     * 从注册表读取Hook实现文件信息
     * 
     * @param string $hookName Hook名称
     * @return array Hook文件列表，格式：['module' => ['file' => 'file_path', 'priority' => 100, 'sort_order' => 1, 'solo' => false]]
     */
    private function getHookFilesFromRegistry(string $hookName): array
    {
        try {
            $registryFile = BP . 'generated' . DIRECTORY_SEPARATOR . 'hooks.php';
            if (!file_exists($registryFile)) {
                return [];
            }
            
            $registry = include $registryFile;
            if (!is_array($registry) || !isset($registry['hooks'][$hookName])) {
                return [];
            }
            
            $hookInfo = $registry['hooks'][$hookName];
            $implementations = $hookInfo['implementations'] ?? [];
            
            // 转换为HookReader期望的格式
            $result = [];
            foreach ($implementations as $module => $impl) {
                $result[$module] = [
                    'file' => $impl['file'] ?? '',
                    'priority' => $impl['priority'] ?? 100,
                    'sort_order' => $impl['sort_order'] ?? 0,
                    'solo' => $impl['solo'] ?? false,
                ];
            }
            
            return $result;
        } catch (\Exception $e) {
            // 如果读取失败，返回空数组
            return [];
        }
    }
    
    /**
     * 从路径中提取Hook名称
     * 
     * @return string Hook名称
     */
    private function getHookNameFromPath(): string
    {
        $path = $this->getPath();
        // 新格式：从目录结构提取Hook名称
        // 路径格式：view/hooks/Weline_Backend/backend/partials/head/before.phtml
        // Hook名称格式：Weline_Backend::backend::partials::head::before
        
        // 移除路径前缀和后缀
        $path = str_replace('view' . DS . 'hooks' . DS, '', $path);
        $path = str_replace(['view/hooks/', 'view\\hooks\\'], '', $path);
        $path = str_replace('.phtml', '', $path);
        
        // 将路径分隔符（/或\）转换为 :: 得到 Hook 名称
        return str_replace([DS, '/', '\\'], '::', $path);
    }
    
    /**
     * 过滤掉禁用的模块的Hook文件，并按顺序返回
     * 
     * @param array $hookFiles Hook文件列表，格式：['module' => 'file_path'] 或 ['module' => ['file' => 'file_path', 'priority' => 100, 'sort_order' => 1]]
     * @return array 过滤并排序后的Hook文件列表，格式：['module' => 'file_path']
     */
    private function filterAndSortHooks(array $hookFiles): array
    {
        if (empty($hookFiles)) {
            return [];
        }
        
        $env = \Weline\Framework\App\Env::getInstance();
        $activeHooks = [];
        
        // 第一步：过滤掉禁用的模块，并转换为统一格式
        $moduleOrder = 0; // 用于记录模块顺序
        foreach ($hookFiles as $module => $fileData) {
            // 检查模块状态
            if (!$env->getModuleStatus($module)) {
                continue;
            }
            
            // 兼容旧格式（直接是文件路径）和新格式（包含file、priority等）
            if (is_string($fileData)) {
                // 旧格式已废弃，现在必须使用新格式（包含元数据）
                continue; // 跳过旧格式
            } elseif (is_array($fileData)) {
                // 新格式：使用提供的优先级，如果没有则计算
                $priority = $fileData['priority'] ?? $this->calculateModulePriority($env, $module);
                $activeHooks[$module] = [
                    'file' => $fileData['file'] ?? $fileData['path'] ?? '',
                    'priority' => $priority,
                    'sort_order' => $fileData['sort_order'] ?? $moduleOrder++,
                    'solo' => $fileData['solo'] ?? false,
                ];
            }
        }
        
        // 第二步：按顺序排序
        // 排序规则：
        // 1. 优先级（priority）降序（数字越大越优先）
        // 2. 排序顺序（sort_order）升序（数字越小越优先）
        // 3. 模块位置优先级：app > composer > framework > system
        // 4. 模块依赖顺序（模块加载顺序）
        uksort($activeHooks, function($moduleA, $moduleB) use ($env, $activeHooks) {
            $a = $activeHooks[$moduleA];
            $b = $activeHooks[$moduleB];
            
            // 1. 按优先级排序（降序）
            if ($a['priority'] != $b['priority']) {
                return $b['priority'] <=> $a['priority'];
            }
            
            // 2. 按排序顺序排序（升序）
            if ($a['sort_order'] != $b['sort_order']) {
                return $a['sort_order'] <=> $b['sort_order'];
            }
            
            // 3. 按模块位置排序
            $positionOrder = ['app' => 4, 'composer' => 3, 'framework' => 2, 'system' => 1];
            try {
                $moduleInfoA = $env->getModuleInfo($moduleA);
                $moduleInfoB = $env->getModuleInfo($moduleB);
                $positionA = $positionOrder[$moduleInfoA['position'] ?? 'composer'] ?? 0;
                $positionB = $positionOrder[$moduleInfoB['position'] ?? 'composer'] ?? 0;
                
                if ($positionA != $positionB) {
                    return $positionB <=> $positionA; // 降序：app优先
                }
            } catch (\Exception $e) {
                // 如果获取模块信息失败，继续使用模块名排序
            }
            
            // 4. 按模块名排序（作为最后的排序依据）
            return strcmp($moduleA, $moduleB);
        });
        
        // 第三步：转换为简单格式返回，格式为 ModuleName::hooks/path/to/file.phtml
        // 这样在 Template.php 中可以直接使用，不需要再次构建路径
        $result = [];
        foreach ($activeHooks as $module => $data) {
            $filePath = $data['file'] ?? '';
            // 如果路径已经是 ModuleName::path 格式，直接使用
            if (strpos($filePath, '::') !== false) {
                $result[$module] = $filePath;
            } else {
                // 将相对路径转换为 ModuleName::hooks/path/to/file.phtml 格式
                $result[$module] = $module . '::hooks/' . $filePath;
            }
        }
        
        return $result;
    }
    
    /**
     * 计算模块的默认优先级
     * 根据模块位置和依赖关系计算优先级
     * 
     * @param \Weline\Framework\App\Env $env
     * @param string $module
     * @return int 优先级（数字越大越优先）
     */
    private function calculateModulePriority(\Weline\Framework\App\Env $env, string $module): int
    {
        try {
            $moduleInfo = $env->getModuleInfo($module);
            $position = $moduleInfo['position'] ?? 'composer';
            
            // 根据模块位置设置基础优先级
            // app > composer > framework > system
            $basePriority = match($position) {
                'app' => 200,
                'composer' => 150,
                'framework' => 100,
                'system' => 50,
                default => 100,
            };
            
            // 可以根据模块依赖关系进一步调整优先级
            // 依赖其他模块的模块优先级稍低（让被依赖的模块先执行）
            // 这里暂时不实现，保持简单
            
            return $basePriority;
        } catch (\Exception $e) {
            // 如果获取模块信息失败，返回默认优先级
            return 100;
        }
    }
    
    /**
     * 过滤掉禁用的模块的Hook文件（保留此方法以兼容旧代码）
     * 
     * @param array $hookFiles Hook文件列表，格式：['module' => 'file_path']
     * @return array 过滤后的Hook文件列表
     * @deprecated 使用 filterAndSortHooks() 代替
     */
    private function filterActiveModuleHooks(array $hookFiles): array
    {
        return $this->filterAndSortHooks($hookFiles);
    }

    /**
     * 获取Hook文件列表（包含完整元数据）
     * 
     * @return array Hook文件列表，格式：['module' => ['file' => 'file_path', 'priority' => 100, 'sort_order' => 1, 'solo' => false]]
     */
    public function getFileListWithMeta(): array
    {
        $hookName = $this->getHookNameFromPath();
        $cache_key = 'hooks::meta::' . $this->getPath();
        
        // 检查静态缓存
        if (isset(self::$staticFileListCache[$cache_key])) {
            return self::$staticFileListCache[$cache_key];
        }
        
        // 从注册表读取
        $data = $this->getHookFilesFromRegistry($hookName);
        
        // 过滤掉禁用的模块
        $env = \Weline\Framework\App\Env::getInstance();
        $result = [];
        foreach ($data as $module => $meta) {
            if ($env->getModuleStatus($module)) {
                $result[$module] = $meta;
            }
        }
        
        // 按顺序排序
        uksort($result, function($moduleA, $moduleB) use ($env, $result) {
            $a = $result[$moduleA];
            $b = $result[$moduleB];
            
            // 1. 按优先级排序（降序）
            if ($a['priority'] != $b['priority']) {
                return $b['priority'] <=> $a['priority'];
            }
            
            // 2. 按排序顺序排序（升序）
            if ($a['sort_order'] != $b['sort_order']) {
                return $a['sort_order'] <=> $b['sort_order'];
            }
            
            // 3. 按模块位置排序
            $positionOrder = ['app' => 4, 'composer' => 3, 'framework' => 2, 'system' => 1];
            try {
                $moduleInfoA = $env->getModuleInfo($moduleA);
                $moduleInfoB = $env->getModuleInfo($moduleB);
                $positionA = $positionOrder[$moduleInfoA['position'] ?? 'composer'] ?? 0;
                $positionB = $positionOrder[$moduleInfoB['position'] ?? 'composer'] ?? 0;
                
                if ($positionA != $positionB) {
                    return $positionB <=> $positionA;
                }
            } catch (\Exception $e) {
                // 忽略错误
            }
            
            // 4. 按模块名排序
            return strcmp($moduleA, $moduleB);
        });
        
        // 更新静态缓存
        self::$staticFileListCache[$cache_key] = $result;
        
        return $result;
    }

    public function setPath(string $path)
    {
        // Hook 名称格式：Weline_Backend::backend::partials::head::before
        // 文件路径格式：view/hooks/Weline_Backend/backend/partials/head/before.phtml
        // 需要将 :: 转换为目录分隔符（/或\）
        $filePath = str_replace('::', DS, $path);
        return parent::setPath('view' . DS . 'hooks' . DS . $filePath . '.phtml');
    }
}
