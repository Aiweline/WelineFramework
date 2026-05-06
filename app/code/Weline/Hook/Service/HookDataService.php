<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Hook\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Hook\Config\HookReader;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Service\ModuleScanService;
use Weline\Framework\System\File\Scanner;
use Weline\Hook\HookRegistry;

/**
 * Hook 数据服务
 * 提供 Hook 信息的读取和统计功能
 */
class HookDataService
{
    private const REGISTRY_FILE = BP . 'generated' . DIRECTORY_SEPARATOR . 'hooks.php';

    private ?HookRegistry $hookRegistry = null;
    private ?HookReader $hookReader = null;
    private ?Scanner $scanner = null;
    private ?ModuleScanService $moduleScanService = null;
    private ?array $cachedHooks = null;
    private int $cachedHooksRegistryMtime = -1;

    public function __construct()
    {
        // 延迟加载依赖，避免依赖注入问题
    }

    /**
     * 获取 HookRegistry 实例
     */
    private function getHookRegistry(): HookRegistry
    {
        if ($this->hookRegistry === null) {
            $this->hookRegistry = ObjectManager::getInstance(HookRegistry::class);
        }
        return $this->hookRegistry;
    }

    /**
     * 获取 HookReader 实例
     */
    private function getHookReader(): HookReader
    {
        return ObjectManager::make(HookReader::class);
    }

    /**
     * 获取 Scanner 实例
     */
    private function getScanner(): Scanner
    {
        if ($this->scanner === null) {
            $this->scanner = ObjectManager::getInstance(Scanner::class);
        }
        return $this->scanner;
    }

    private function getModuleScanService(): ModuleScanService
    {
        if ($this->moduleScanService === null) {
            $this->moduleScanService = new ModuleScanService($this->getScanner());
        }
        return $this->moduleScanService;
    }

    /**
     * 获取所有 Hook 信息
     *
     * @return array
     */
    public function getAllHooks(): array
    {
        $result = [];
        
        // 初始化注册表
        $this->getHookRegistry()->initialize();
        
        // 检查注册表文件是否存在（如果不存在，需要运行 setup:upgrade）
        $registryFile = BP . 'generated' . DIRECTORY_SEPARATOR . 'hooks.php';
        if (!file_exists($registryFile)) {
            // 注册表文件不存在，返回空数组，提示用户运行 setup:upgrade
            return [];
        }
        
        // 从注册表获取所有 Hook（包含规约信息）
        $registryHooks = $this->getHookRegistry()->getHooks();
        
        // 获取所有已注册的 Hook（从 HookInterface 常量）
        $registeredHooks = $this->getHookRegistry()->getAllRegisteredHooks();
        
        // 扫描所有模块的 Hook 文件
        $hookFiles = $this->scanAllHookFiles();
        
        // 首先处理注册表中的所有 Hook（包含规约信息）
        foreach ($registryHooks as $hookName => $hookInfo) {
            // 解析 Hook 名称
            $parts = explode('::', $hookName);
            $moduleName = $parts[0] ?? '';
            $area = $parts[1] ?? '';
            $type = $parts[2] ?? '';
            $component = $parts[3] ?? '';
            $position = $parts[4] ?? '';
            
            // 获取该 Hook 的文件列表
            $files = $hookFiles[$hookName] ?? [];
            
            // 统计使用该 Hook 的模块
            $usingModules = [];
            foreach ($files as $file) {
                $fileModule = explode('::', $file)[0] ?? '';
                if ($fileModule && !in_array($fileModule, $usingModules)) {
                    $usingModules[] = $fileModule;
                }
            }
            
            // 检查是否在 HookInterface 中注册
            $isRegistered = in_array($hookName, $registeredHooks);
            $hookInfoFromInterface = $isRegistered ? $this->getHookRegistry()->getHookInfoFromInterface($hookName) : null;
            
            $result[$hookName] = [
                'name' => $hookName,
                'display_name' => $hookInfo['name'] ?? $hookName,
                'description' => $hookInfo['description'] ?? '',
                'constant' => $hookInfoFromInterface['constant'] ?? '',
                'module' => $hookInfo['module'] ?? $moduleName,
                'area' => $area,
                'type' => $type,
                'component' => $component,
                'position' => $position,
                'files' => $files,
                'file_count' => count($files),
                'using_modules' => $usingModules,
                'using_module_count' => count($usingModules),
                'is_registered' => $isRegistered,
                'has_files' => count($files) > 0,
                'has_spec' => $hookInfo['has_spec'] ?? false,
                'has_doc' => $hookInfo['has_doc'] ?? false,
                'doc' => $hookInfo['doc'] ?? '',
                'doc_path' => $hookInfo['doc_path'] ?? ''
            ];
        }
        
        // 添加在 HookInterface 中注册但不在注册表中的 Hook（可能还没有规约文件）
        foreach ($registeredHooks as $hookName) {
            if (!isset($result[$hookName])) {
                $hookInfoFromInterface = $this->getHookRegistry()->getHookInfoFromInterface($hookName);
                
                // 解析 Hook 名称
                $parts = explode('::', $hookName);
                $moduleName = $parts[0] ?? '';
                $area = $parts[1] ?? '';
                $type = $parts[2] ?? '';
                $component = $parts[3] ?? '';
                $position = $parts[4] ?? '';
                
                // 获取该 Hook 的文件列表
                $files = $hookFiles[$hookName] ?? [];
                
                // 统计使用该 Hook 的模块
                $usingModules = [];
                foreach ($files as $file) {
                    $fileModule = explode('::', $file)[0] ?? '';
                    if ($fileModule && !in_array($fileModule, $usingModules)) {
                        $usingModules[] = $fileModule;
                    }
                }
                
                $result[$hookName] = [
                    'name' => $hookName,
                    'display_name' => $hookName,
                    'description' => '',
                    'constant' => $hookInfoFromInterface['constant'] ?? '',
                    'module' => $moduleName,
                    'area' => $area,
                    'type' => $type,
                    'component' => $component,
                    'position' => $position,
                    'files' => $files,
                    'file_count' => count($files),
                    'using_modules' => $usingModules,
                    'using_module_count' => count($usingModules),
                    'is_registered' => true,
                    'has_files' => count($files) > 0,
                    'has_spec' => false,
                    'has_doc' => false,
                    'doc' => '',
                    'doc_path' => ''
                ];
            }
        }
        
        // 添加未注册但存在文件的 Hook（可能是遗留的或未规范的）
        foreach ($hookFiles as $hookName => $files) {
            if (!isset($result[$hookName])) {
                $parts = explode('::', $hookName);
                $moduleName = $parts[0] ?? '';
                $area = $parts[1] ?? '';
                $type = $parts[2] ?? '';
                $component = $parts[3] ?? '';
                $position = $parts[4] ?? '';
                
                $usingModules = [];
                foreach ($files as $file) {
                    $fileModule = explode('::', $file)[0] ?? '';
                    if ($fileModule && !in_array($fileModule, $usingModules)) {
                        $usingModules[] = $fileModule;
                    }
                }
                
                $result[$hookName] = [
                    'name' => $hookName,
                    'constant' => '',
                    'module' => $moduleName,
                    'area' => $area,
                    'type' => $type,
                    'component' => $component,
                    'position' => $position,
                    'files' => $files,
                    'file_count' => count($files),
                    'using_modules' => $usingModules,
                    'using_module_count' => count($usingModules),
                    'is_registered' => false,
                    'has_files' => true
                ];
            }
        }
        
        return $result;
    }

    /**
     * 获取单个 Hook 的详细信息
     *
     * @param string $hookName
     * @return array|null
     */
    public function getHookDetail(string $hookName): ?array
    {
        $allHooks = $this->getAllHooks();
        return $allHooks[$hookName] ?? null;
    }

    /**
     * 获取 Hook 统计信息
     *
     * @return array
     */
    public function getHookStats(): array
    {
        $hooks = $this->getAllHooks();
        
        $stats = [
            'total_hooks' => count($hooks),
            'registered_hooks' => 0,
            'unregistered_hooks' => 0,
            'hooks_with_files' => 0,
            'hooks_without_files' => 0,
            'total_files' => 0,
            'modules_with_hooks' => [],
            'modules_using_hooks' => [],
            'areas' => ['frontend' => 0, 'backend' => 0],
            'types' => ['partials' => 0, 'layouts' => 0]
        ];

        foreach ($hooks as $hookName => $hookInfo) {
            // 统计注册状态
            if ($hookInfo['is_registered']) {
                $stats['registered_hooks']++;
            } else {
                $stats['unregistered_hooks']++;
            }
            
            // 统计文件
            $fileCount = $hookInfo['file_count'] ?? 0;
            $stats['total_files'] += $fileCount;
            if ($fileCount > 0) {
                $stats['hooks_with_files']++;
            } else {
                $stats['hooks_without_files']++;
            }
            
            // 统计定义 Hook 的模块
            $module = $hookInfo['module'] ?? '';
            if ($module) {
                if (!isset($stats['modules_with_hooks'][$module])) {
                    $stats['modules_with_hooks'][$module] = 0;
                }
                $stats['modules_with_hooks'][$module]++;
            }
            
            // 统计使用 Hook 的模块
            foreach ($hookInfo['using_modules'] ?? [] as $usingModule) {
                if (!isset($stats['modules_using_hooks'][$usingModule])) {
                    $stats['modules_using_hooks'][$usingModule] = 0;
                }
                $stats['modules_using_hooks'][$usingModule]++;
            }
            
            // 统计区域
            $area = $hookInfo['area'] ?? '';
            if (isset($stats['areas'][$area])) {
                $stats['areas'][$area]++;
            }
            
            // 统计类型
            $type = $hookInfo['type'] ?? '';
            if (isset($stats['types'][$type])) {
                $stats['types'][$type]++;
            }
        }

        return $stats;
    }

    /**
     * 搜索 Hook
     *
     * @param string $searchTerm
     * @param string $searchType all|name|module|component|position
     * @return array
     */
    public function searchHooks(string $searchTerm, string $searchType = 'all'): array
    {
        $hooks = $this->getAllHooks();
        $results = [];

        foreach ($hooks as $hookName => $hookInfo) {
            $matched = false;
            $matchReasons = [];

            if (empty($searchTerm)) {
                $results[$hookName] = $hookInfo;
                continue;
            }

            // 按类型搜索
            if ($searchType === 'all' || $searchType === 'name') {
                if (stripos($hookName, $searchTerm) !== false) {
                    $matched = true;
                    $matchReasons[] = 'Hook 名';
                }
            }

            if ($searchType === 'all' || $searchType === 'module') {
                if (stripos($hookInfo['module'] ?? '', $searchTerm) !== false) {
                    $matched = true;
                    $matchReasons[] = '定义模块';
                }
                foreach ($hookInfo['using_modules'] ?? [] as $usingModule) {
                    if (stripos($usingModule, $searchTerm) !== false) {
                        $matched = true;
                        $matchReasons[] = '使用模块';
                    }
                }
            }

            if ($searchType === 'all' || $searchType === 'component') {
                if (stripos($hookInfo['component'] ?? '', $searchTerm) !== false) {
                    $matched = true;
                    $matchReasons[] = '组件';
                }
            }

            if ($searchType === 'all' || $searchType === 'position') {
                if (stripos($hookInfo['position'] ?? '', $searchTerm) !== false) {
                    $matched = true;
                    $matchReasons[] = '位置';
                }
            }

            if ($matched) {
                $hookInfo['match_reasons'] = array_unique($matchReasons);
                $results[$hookName] = $hookInfo;
            }
        }

        return $results;
    }

    /**
     * 按模块筛选 Hook
     *
     * @param string $moduleName 模块名
     * @return array
     */
    public function getHooksByModule(string $moduleName): array
    {
        $hooks = $this->getAllHooks();
        $results = [];

        foreach ($hooks as $hookName => $hookInfo) {
            // 检查是否是定义该 Hook 的模块
            if (($hookInfo['module'] ?? '') === $moduleName) {
                $results[$hookName] = $hookInfo;
                continue;
            }

            // 检查是否有该模块使用的 Hook 文件
            if (in_array($moduleName, $hookInfo['using_modules'] ?? [])) {
                $results[$hookName] = $hookInfo;
            }
        }

        return $results;
    }

    /**
     * 扫描所有模块的 Hook 文件
     *
     * @return array 格式：[hookName => [module::file_path, ...]]
     */
    private function scanAllHookFiles(): array
    {
        $result = [];
        $modules = Env::getInstance()->getModuleList();

        foreach ($modules as $moduleName => $moduleInfo) {
            $basePath = $moduleInfo['base_path'] ?? '';
            if (empty($basePath) || !($moduleInfo['status'] ?? false)) {
                continue;
            }

            // 扫描 view/hooks/ 目录
            $hooksDir = $this->getModuleScanService()->resolveDirectory($basePath, 'view/hooks');
            if ($hooksDir === null) {
                continue;
            }

            // 扫描目录下的所有 .phtml 文件
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($hooksDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if ($file->isFile() && $file->getExtension() === 'phtml') {
                    // 目录层级结构格式：从目录结构提取Hook名称
                    // 文件路径格式：view/hooks/Weline_Backend/backend/partials/head/before.phtml
                    // Hook名称格式：Weline_Backend::backend::partials::head::before
                    
                    // 获取相对于hooks目录的路径
                    $relativePath = str_replace($hooksDir . DS, '', $file->getPathname());
                    // 标准化路径分隔符
                    $relativePath = str_replace(['/', '\\'], DS, $relativePath);
                    // 移除文件扩展名
                    $relativePathWithoutExt = str_replace('.phtml', '', $relativePath);
                    
                    // 将路径分隔符转换为 :: 得到 Hook 名称
                    $hookName = str_replace(DS, '::', $relativePathWithoutExt);
                    
                    // 构建文件路径标识（相对于模块根目录）
                    $fileRelativePath = str_replace($basePath . DS, '', $file->getPathname());
                    
                    // 使用与 HookRegistry 相同的格式（数组格式，包含元数据）
                    // 注意：HookDataService 主要用于后台显示，不需要解析元数据
                    // 但为了保持一致性，使用相同的文件路径格式
                    $fileIdentifier = $moduleName . '::' . $fileRelativePath;
                    
                    if (!isset($result[$hookName])) {
                        $result[$hookName] = [];
                    }
                    $result[$hookName][] = $fileIdentifier;
                }
            }
        }

        return $result;
    }
}
