<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Hook;

use Weline\Framework\Hook\HookInterface;

/**
 * Hook 注册表管理
 * 管理 generated/hooks.php 文件的读取和写入
 * 同时管理 HookInterface 中定义的常量
 */
class HookRegistry
{
    private const REGISTRY_FILE = BP . 'generated' . DIRECTORY_SEPARATOR . 'hooks.php';

    private ?array $cachedRegistry = null;
    private ?int $cachedFileMtime = null;
    private HookScanner $scanner;
    
    /**
     * 已注册的 hook 列表（从 HookInterface 常量中获取）
     * 
     * @var array
     */
    private array $registeredHooks = [];
    
    /**
     * 是否已初始化
     * 
     * @var bool
     */
    private bool $initialized = false;

    public function __construct(
        HookScanner $scanner
    ) {
        $this->scanner = $scanner;
    }

    /**
     * 初始化注册表，从 HookInterface 中读取所有常量
     */
    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }
        
        $reflection = new \ReflectionClass(HookInterface::class);
        $constants = $reflection->getConstants();
        
        foreach ($constants as $constantName => $constantValue) {
            if (is_string($constantValue)) {
                $this->registeredHooks[$constantValue] = [
                    'name' => $constantValue,
                    'constant' => $constantName,
                ];
            }
        }
        
        $this->initialized = true;
    }

    /**
     * 获取注册表内容
     *
     * @param bool $forceReload 强制重新加载
     * @return array
     */
    public function getRegistry(bool $forceReload = false): array
    {
        // 内存缓存机制
        if (!$forceReload && $this->cachedRegistry !== null) {
            $currentMtime = file_exists(self::REGISTRY_FILE) ? filemtime(self::REGISTRY_FILE) : 0;
            if ($currentMtime === $this->cachedFileMtime) {
                return $this->cachedRegistry;
            }
        }

        if (!file_exists(self::REGISTRY_FILE)) {
            $this->cachedRegistry = ['hooks' => [], 'hook_to_module' => []];
            $this->cachedFileMtime = 0;
            return $this->cachedRegistry;
        }

        $registry = include self::REGISTRY_FILE;
        if (!is_array($registry)) {
            $registry = ['hooks' => [], 'hook_to_module' => []];
        }

        // 兼容旧格式
        if (!isset($registry['hook_to_module']) && isset($registry['hooks'])) {
            $hookToModule = [];
            foreach ($registry['hooks'] as $hookName => $hookInfo) {
                if (isset($hookInfo['module'])) {
                    $hookToModule[$hookName] = $hookInfo['module'];
                }
            }
            $registry['hook_to_module'] = $hookToModule;
        } elseif (!isset($registry['hooks'])) {
            // 兼容旧格式（如果直接是 Hook 数组）
            $hooks = $registry;
            $hookToModule = [];
            foreach ($hooks as $hookName => $hookInfo) {
                if (isset($hookInfo['module'])) {
                    $hookToModule[$hookName] = $hookInfo['module'];
                }
            }
            $registry = ['hooks' => $hooks, 'hook_to_module' => $hookToModule];
        }

        $this->cachedRegistry = $registry;
        $this->cachedFileMtime = file_exists(self::REGISTRY_FILE) ? filemtime(self::REGISTRY_FILE) : 0;

        return $registry;
    }

    /**
     * 刷新注册表（重新扫描并保存）
     *
     * @return bool
     * @throws \RuntimeException 如果多个模块定义了相同的 Hook 名
     */
    /**
     * 刷新注册表（重新扫描并保存）
     *
     * @param bool $allowSoloConflict 是否允许solo hook冲突（系统升级时使用）
     * @return bool
     * @throws \RuntimeException 如果多个模块定义了相同的 Hook 名
     */
    public function refresh(bool $allowSoloConflict = false): bool
    {
        // 扫描所有 Hook 规约（使用 extends 方式）
        $scannedData = $this->scanner->scanAllHooks();
        
        // 扫描所有 Hook 实现文件
        $hookFiles = $this->scanAllHookFiles();

        // 组织数据结构，按 Hook 名索引（如果发现冲突会抛出异常）
        $registry = $this->organizeRegistryData($scannedData, $hookFiles, $allowSoloConflict);

        // 检查文档（无论是否允许solo冲突，都要检查文档）
        $this->validateDocumentation($registry);

        // 检查Hook实现文件是否存在但没有规约的情况（始终检查，不只在开发环境）
        // 在系统升级和hook:rebuild时，必须确保所有Hook实现都有规约
        $this->validateHookSpecifications($registry);

        // 保存注册表
        return $this->saveRegistry($registry);
    }

    /**
     * 组织注册表数据
     * 将模块级别的 Hook 信息转换为 Hook 名索引的结构
     * 
     * 注意：Hook规约（定义）只能被一个模块定义，但Hook实现文件可以被多个模块提供
     *
     * @param array $scannedData 扫描的Hook规约数据
     * @param array $hookFiles 扫描的Hook实现文件数据，格式：[hookName => [module::file_path, ...]]
     * @param bool $allowSoloConflict 是否允许solo hook冲突（系统升级时使用）
     * @return array
     * @throws \RuntimeException 如果多个模块定义了相同的 Hook 规约
     */
    private function organizeRegistryData(array $scannedData, array $hookFiles = [], bool $allowSoloConflict = false): array
    {
        $registry = [];
        // 快速查询：Hook 名到模块名的映射（用于性能优化）
        $hookToModuleMap = [];

        foreach ($scannedData as $moduleName => $hooks) {
            foreach ($hooks as $hookName => $hookInfo) {
                // 检查 Hook 规约是否已被其他模块定义（规约只能被一个模块定义）
                if (isset($registry[$hookName])) {
                    $existingModule = $hookToModuleMap[$hookName];
                    $existingHookInfo = $registry[$hookName];
                    
                    // 构建详细的错误信息
                    $errorMessage = $this->buildConflictErrorMessage(
                        $hookName,
                        $existingModule,
                        $moduleName,
                        $existingHookInfo,
                        $hookInfo
                    );
                    
                    throw new \RuntimeException($errorMessage);
                }

                // 添加新 Hook 规约
                $registry[$hookName] = [
                    'name' => $hookInfo['name'] ?? $hookName,
                    'description' => $hookInfo['description'] ?? '',
                    'doc' => $hookInfo['doc'] ?? '',
                    'doc_path' => $hookInfo['doc_path'] ?? '',
                    'has_spec' => $hookInfo['has_spec'] ?? false,
                    'has_doc' => $hookInfo['has_doc'] ?? false,
                    'module' => $moduleName, // 定义该 Hook 规约的模块（只能有一个）
                    'implementations' => [], // Hook实现文件列表（多个模块可以实现）
                ];
                // 添加到快速查询映射
                $hookToModuleMap[$hookName] = $moduleName;
            }
        }
        
        // 添加Hook实现文件信息
        foreach ($hookFiles as $hookName => $files) {
            // 如果Hook规约不存在，创建一个基本的规约（用于未定义规约但存在实现文件的情况）
            if (!isset($registry[$hookName])) {
                // 从Hook名称提取模块名（第一个::之前的部分）
                $parts = explode('::', $hookName, 2);
                $defaultModule = $parts[0] ?? 'Unknown';
                
                $registry[$hookName] = [
                    'name' => $hookName,
                    'description' => '',
                    'doc' => '',
                    'doc_path' => '',
                    'has_spec' => false,
                    'has_doc' => false,
                    'module' => $defaultModule,
                    'implementations' => [],
                ];
                $hookToModuleMap[$hookName] = $defaultModule;
            }
            
            // 处理实现文件，转换为带顺序信息的格式
            $implementations = [];
            $env = \Weline\Framework\App\Env::getInstance();
            $moduleOrder = 0;
            
            foreach ($files as $fileIdentifier) {
                // 兼容旧格式（字符串）和新格式（数组）
                if (is_array($fileIdentifier)) {
                    // 新格式：包含模块名、文件路径和元数据
                    $moduleName = $fileIdentifier['module'] ?? '';
                    $filePath = $fileIdentifier['file'] ?? '';
                    $customPriority = $fileIdentifier['priority'] ?? null;
                    $customSortOrder = $fileIdentifier['sort_order'] ?? null;
                    $solo = $fileIdentifier['solo'] ?? false;
                } else {
                    // 旧格式：ModuleName::relative/path/to/file.phtml（已废弃，现在必须使用新格式）
                    throw new \RuntimeException(
                        "Hook文件格式已废弃：{$fileIdentifier}\n" .
                        "所有Hook文件必须包含元数据（priority或sort_order）。\n" .
                        "请参考：app/code/Weline/Framework/Hook/doc/Hook优先级和排序顺序使用指南.md"
                    );
                }
                
                // 检查模块状态
                if (empty($moduleName) || !$env->getModuleStatus($moduleName)) {
                    continue;
                }
                
                // 计算模块优先级（如果文件中有自定义优先级则使用，否则使用默认值）
                $defaultPriority = $this->calculateModulePriority($env, $moduleName);
                $priority = $customPriority !== null ? (int)$customPriority : $defaultPriority;
                
                // 使用自定义排序顺序，如果没有则使用模块顺序
                $sortOrder = $customSortOrder !== null ? (int)$customSortOrder : $moduleOrder++;
                
                $implementations[$moduleName] = [
                    'file' => $filePath,
                    'priority' => $priority,
                    'sort_order' => $sortOrder,
                    'solo' => (bool)$solo,
                ];
            }
            
            // 检查是否有多个solo的hook（冲突检测）
            $soloHooks = array_filter($implementations, function($impl) {
                return $impl['solo'] ?? false;
            });
            
            if (count($soloHooks) > 1) {
                $soloModules = array_keys($soloHooks);
                throw new \RuntimeException(
                    "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
                    "【致命错误】Hook独享冲突检测\n" .
                    "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n" .
                    "❌ Hook：{$hookName}\n" .
                    "   问题：有多个模块设置了 @hook-solo true（独享模式）\n\n" .
                    "⚠️  冲突模块：\n" .
                    implode("\n", array_map(function($module) use ($implementations) {
                        return "   - {$module}（文件：{$implementations[$module]['file']}）";
                    }, $soloModules)) . "\n\n" .
                    "💡 解决方案\n" .
                    "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n" .
                    "独享模式（solo）表示该Hook只能被一个模块实现。\n" .
                    "请只保留一个模块的 @hook-solo true，其他模块改为 false 或删除该属性。\n\n" .
                    "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
                );
            }
            
            // 开发环境下：如果存在solo hook，检查是否有其他非solo的实现，如果有则报错并停止收集
            // 但如果允许solo冲突（系统升级时），则只记录警告，不抛出异常
            if (defined('DEV') && DEV && count($soloHooks) === 1) {
                $soloModule = array_key_first($soloHooks);
                $nonSoloHooks = array_filter($implementations, function($impl, $module) use ($soloModule) {
                    return $module !== $soloModule && !($impl['solo'] ?? false);
                }, ARRAY_FILTER_USE_BOTH);
                
                if (count($nonSoloHooks) > 0) {
                    $nonSoloModules = array_keys($nonSoloHooks);
                    $errorMessage = 
                        "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
                        "【致命错误】Hook被独占冲突检测（开发环境）\n" .
                        "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n" .
                        "❌ Hook：{$hookName}\n" .
                        "   问题：该Hook已被模块 {$soloModule} 设置为独享模式（@hook-solo true）\n" .
                        "   但仍有其他模块尝试实现该Hook，这些实现将被忽略。\n\n" .
                        "⚠️  独占模块：\n" .
                        "   - {$soloModule}（文件：{$implementations[$soloModule]['file']}）\n\n" .
                        "⚠️  被影响的模块（将被忽略）：\n" .
                        implode("\n", array_map(function($module) use ($implementations) {
                            return "   - {$module}（文件：{$implementations[$module]['file']}）";
                        }, $nonSoloModules)) . "\n\n" .
                        "💡 解决方案\n" .
                        "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n" .
                        "选项1：移除被影响模块的Hook实现文件\n" .
                        "   如果确实不需要这些模块的实现，请删除对应的Hook文件。\n\n" .
                        "选项2：移除独占模块的 @hook-solo true\n" .
                        "   如果希望多个模块同时实现该Hook，请将 {$soloModule} 模块的Hook文件中的\n" .
                        "   @hook-solo true 改为 @hook-solo false 或删除该属性。\n\n" .
                        "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
                    
                    if ($allowSoloConflict) {
                        // 系统升级时，只记录警告，不抛出异常
                        \Weline\Framework\App\Env::log_warning('hook_solo_conflict.log', $errorMessage);
                    } else {
                        // 开发环境下，抛出异常
                        throw new \RuntimeException($errorMessage);
                    }
                }
            }
            
            // 按优先级和排序顺序排序实现文件
            uksort($implementations, function($moduleA, $moduleB) use ($env, $implementations) {
                $a = $implementations[$moduleA];
                $b = $implementations[$moduleB];
                
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
            
            $registry[$hookName]['implementations'] = $implementations;
        }

        // 返回包含快速查询映射的数据结构
        return [
            'hooks' => $registry,
            'hook_to_module' => $hookToModuleMap, // 快速查询：Hook 名 => 定义该Hook规约的模块名
        ];
    }
    
    /**
     * 解析Hook文件中的元数据（优先级、排序顺序、solo等）
     * 支持从文件注释中提取：
     * - @hook-priority 200
     * - @hook-sort-order 1
     * - @hook-solo true
     * - Hook优先级：200
     * - Hook排序顺序：1
     * - Hook独享：true
     * 
     * @param string $filePath Hook文件路径
     * @param string $moduleName 模块名称（用于错误提示）
     * @param string $hookName Hook名称（用于错误提示）
     * @return array 包含 priority, sort_order, solo 的数组
     * @throws \RuntimeException 如果缺少必需的元数据
     */
    private function parseHookFileMeta(string $filePath, string $moduleName, string $hookName): array
    {
        $meta = [
            'priority' => null,
            'sort_order' => null,
            'solo' => false,
        ];
        
        if (!is_file($filePath)) {
            throw new \RuntimeException(
                "Hook文件不存在：{$filePath}\n" .
                "模块：{$moduleName}\n" .
                "Hook：{$hookName}"
            );
        }
        
        $content = file_get_contents($filePath);
        
        // 提取 @hook-priority 或 Hook优先级
        if (preg_match('/@hook-priority\s+(\d+)/i', $content, $matches)) {
            $meta['priority'] = (int)$matches[1];
        } elseif (preg_match('/Hook优先级[：:]\s*(\d+)/i', $content, $matches)) {
            $meta['priority'] = (int)$matches[1];
        } elseif (preg_match('/优先级[：:]\s*(\d+)/i', $content, $matches)) {
            $meta['priority'] = (int)$matches[1];
        }
        
        // 提取 @hook-sort-order 或 Hook排序顺序
        if (preg_match('/@hook-sort-order\s+(\d+)/i', $content, $matches)) {
            $meta['sort_order'] = (int)$matches[1];
        } elseif (preg_match('/Hook排序顺序[：:]\s*(\d+)/i', $content, $matches)) {
            $meta['sort_order'] = (int)$matches[1];
        } elseif (preg_match('/排序顺序[：:]\s*(\d+)/i', $content, $matches)) {
            $meta['sort_order'] = (int)$matches[1];
        }
        
        // 提取 @hook-solo 或 Hook独享
        if (preg_match('/@hook-solo\s+(true|false|1|0|yes|no)/i', $content, $matches)) {
            $value = strtolower(trim($matches[1]));
            $meta['solo'] = in_array($value, ['true', '1', 'yes'], true);
        } elseif (preg_match('/Hook独享[：:]\s*(true|false|1|0|是|否)/i', $content, $matches)) {
            $value = strtolower(trim($matches[1]));
            $meta['solo'] = in_array($value, ['true', '1', '是'], true);
        } elseif (preg_match('/独享[：:]\s*(true|false|1|0|是|否)/i', $content, $matches)) {
            $value = strtolower(trim($matches[1]));
            $meta['solo'] = in_array($value, ['true', '1', '是'], true);
        }
        
        // 验证：必须至少定义 priority 或 sort_order 之一
        if ($meta['priority'] === null && $meta['sort_order'] === null) {
            throw new \RuntimeException(
                "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
                "【致命错误】Hook文件缺少必需的元数据\n" .
                "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n" .
                "❌ Hook文件：{$filePath}\n" .
                "   模块：{$moduleName}\n" .
                "   Hook：{$hookName}\n\n" .
                "💡 解决方案\n" .
                "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n" .
                "请在Hook文件的开头注释中添加元数据，至少包含以下之一：\n\n" .
                "方式1（推荐）：\n" .
                "  @hook-priority 200      Hook优先级：200（数字越大越优先）\n" .
                "  @hook-sort-order 1      Hook排序顺序：1（数字越小越优先）\n" .
                "  @hook-solo false        Hook独享：false（是否独占整个hook）\n\n" .
                "方式2（中文注释）：\n" .
                "  Hook优先级：200\n" .
                "  Hook排序顺序：1\n" .
                "  Hook独享：false\n\n" .
                "详细说明请参考：app/code/Weline/Framework/Hook/doc/Hook优先级和排序顺序使用指南.md\n" .
                "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
            );
        }
        
        return $meta;
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
            return match($position) {
                'app' => 200,
                'composer' => 150,
                'framework' => 100,
                'system' => 50,
                default => 100,
            };
        } catch (\Exception $e) {
            // 如果获取模块信息失败，返回默认优先级
            return 100;
        }
    }
    
    /**
     * 扫描所有模块的 Hook 实现文件
     *
     * @return array 格式：[hookName => [module::file_path, ...]]
     */
    private function scanAllHookFiles(): array
    {
        $result = [];
        $env = \Weline\Framework\App\Env::getInstance();
        $modules = $env->getModuleList();

        foreach ($modules as $moduleName => $moduleInfo) {
            $basePath = $moduleInfo['base_path'] ?? '';
            if (empty($basePath) || !($moduleInfo['status'] ?? false)) {
                continue;
            }

            // 扫描 view/hooks/ 目录
            $hooksDir = $basePath . DS . 'view' . DS . 'hooks';
            if (!is_dir($hooksDir)) {
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
                    
                    // 获取相对于hooks目录的路径（用于提取Hook名称）
                    $relativePath = str_replace($hooksDir . DS, '', $file->getPathname());
                    // 标准化路径分隔符
                    $relativePath = str_replace(['/', '\\'], DS, $relativePath);
                    // 移除文件扩展名
                    $relativePathWithoutExt = str_replace('.phtml', '', $relativePath);
                    
                    // 将路径分隔符转换为 :: 得到 Hook 名称
                    $hookName = str_replace(DS, '::', $relativePathWithoutExt);
                    
                    // 构建文件路径标识（相对于 view/hooks/ 目录，不包含 view/hooks/ 前缀）
                    // 这样在 Template.php 中使用时，可以统一添加 view/hooks/ 前缀
                    $fileRelativePath = str_replace(['/', '\\'], '/', $relativePath);
                    $filePath = $file->getPathname();
                    
                    // 解析文件中的元数据（从注释中提取，必须包含priority或sort_order）
                    $hookMeta = $this->parseHookFileMeta($filePath, $moduleName, $hookName);
                    
                    // 构建文件标识，包含元数据
                    $fileIdentifier = [
                        'module' => $moduleName,
                        'file' => $fileRelativePath,
                        'priority' => $hookMeta['priority'],
                        'sort_order' => $hookMeta['sort_order'],
                        'solo' => $hookMeta['solo'],
                    ];
                    
                    if (!isset($result[$hookName])) {
                        $result[$hookName] = [];
                    }
                    $result[$hookName][] = $fileIdentifier;
                }
            }
        }

        return $result;
    }

    /**
     * 验证所有Hook的文档是否存在
     * 如果缺少文档，抛出异常阻止保存
     * 
     * @param array $registry Hook注册表数据（包含 'hooks' 和 'hook_to_module' 键）
     * @throws \RuntimeException 如果发现缺少文档的Hook
     */
    private function validateDocumentation(array $registry): void
    {
        $hooksWithoutDoc = [];
        
        // 从注册表中获取 hooks 数组（registry 结构：['hooks' => [...], 'hook_to_module' => [...]）
        $hooks = $registry['hooks'] ?? [];
        
        foreach ($hooks as $hookName => $hookInfo) {
            // 只检查有规约的Hook（has_spec为true）
            if (!empty($hookInfo['has_spec']) && $hookInfo['has_spec']) {
                // 检查是否有文档
                if (empty($hookInfo['has_doc']) || !$hookInfo['has_doc']) {
                    $hooksWithoutDoc[] = [
                        'name' => $hookName,
                        'module' => $hookInfo['module'] ?? '',
                        'display_name' => $hookInfo['name'] ?? $hookName,
                        'doc_path' => $hookInfo['doc_path'] ?? '',
                    ];
                }
            }
        }
        
        if (!empty($hooksWithoutDoc)) {
            $errorMessage = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $errorMessage .= "【致命错误】Hook 文档缺失检测\n";
            $errorMessage .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            $errorMessage .= sprintf("❌ 发现 %d 个钩子缺少文档，重建中断！\n\n", count($hooksWithoutDoc));
            $errorMessage .= "缺少文档的钩子列表：\n\n";
            
            foreach ($hooksWithoutDoc as $hook) {
                $errorMessage .= "  ❌ 钩子名称：{$hook['name']}\n";
                $errorMessage .= "     显示名称：{$hook['display_name']}\n";
                $errorMessage .= "     所属模块：{$hook['module']}\n";
                if (!empty($hook['doc_path'])) {
                    $errorMessage .= "     期望文档路径：{$hook['doc_path']}\n";
                } else if (!empty($hook['doc'])) {
                    // 如果 hook.php 中配置了 doc 字段，显示期望路径
                    $moduleName = $hook['module'] ?? '';
                    $expectedDocPath = $moduleName . '/doc/hook/' . $hook['doc'];
                    $errorMessage .= "     期望文档路径：{$expectedDocPath}\n";
                } else {
                    // 尝试构建期望的文档路径（从 hook 名称推断）
                    $hookParts = explode('::', $hook['name']);
                    if (count($hookParts) >= 2) {
                        $moduleName = $hookParts[0];
                        $hookPath = implode('/', array_slice($hookParts, 1));
                        $expectedDocPath = $moduleName . '/doc/hook/' . $hookPath . '.md';
                        $errorMessage .= "     期望文档路径：{$expectedDocPath}\n";
                    }
                }
                $errorMessage .= "\n";
            }
            
            $errorMessage .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $errorMessage .= "💡 解决方案：\n";
            $errorMessage .= "   请为上述钩子创建对应的文档文件。\n";
            $errorMessage .= "   文档文件应放在模块的 doc/hook/ 目录下（路径相对于 doc/hook/）。\n";
            $errorMessage .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            
            throw new \RuntimeException($errorMessage);
        }
    }

    /**
     * 验证所有Hook实现文件是否有对应的规约
     * 如果Hook有实现文件但没有规约，抛出异常阻止保存
     * 在系统升级和hook:rebuild时，必须确保所有Hook实现都有规约
     * 
     * @param array $registry Hook注册表数据（包含 'hooks' 和 'hook_to_module' 键）
     * @throws \RuntimeException 如果发现缺少规约的Hook实现
     */
    private function validateHookSpecifications(array $registry): void
    {
        $hooksWithoutSpec = [];
        
        // 从注册表中获取 hooks 数组（registry 结构：['hooks' => [...], 'hook_to_module' => [...]）
        $hooks = $registry['hooks'] ?? [];
        
        foreach ($hooks as $hookName => $hookInfo) {
            // 检查是否有实现文件
            $hasImplementations = !empty($hookInfo['implementations']) && is_array($hookInfo['implementations']) && count($hookInfo['implementations']) > 0;
            
            // 如果Hook有实现文件但没有规约（has_spec为false），记录错误
            if ($hasImplementations && (empty($hookInfo['has_spec']) || !$hookInfo['has_spec'])) {
                // 收集实现文件的模块信息
                $implementationModules = [];
                foreach ($hookInfo['implementations'] as $moduleName => $implementation) {
                    $implementationModules[] = [
                        'module' => $moduleName,
                        'file' => $implementation['file'] ?? '',
                    ];
                }
                
                $hooksWithoutSpec[] = [
                    'name' => $hookName,
                    'module' => $hookInfo['module'] ?? 'Unknown',
                    'display_name' => $hookInfo['name'] ?? $hookName,
                    'implementations' => $implementationModules,
                ];
            }
        }
        
        if (!empty($hooksWithoutSpec)) {
            $errorMessage = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $errorMessage .= "【致命错误】Hook 规约缺失检测\n";
            $errorMessage .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            $errorMessage .= sprintf("❌ 发现 %d 个Hook有实现文件但缺少规约，系统升级中断！\n\n", count($hooksWithoutSpec));
            $errorMessage .= "缺少规约的Hook列表：\n\n";
            
            foreach ($hooksWithoutSpec as $hook) {
                $errorMessage .= "  ❌ Hook名称：{$hook['name']}\n";
                $errorMessage .= "     显示名称：{$hook['display_name']}\n";
                $errorMessage .= "     所属模块：{$hook['module']}\n";
                $errorMessage .= "     实现文件：\n";
                
                foreach ($hook['implementations'] as $impl) {
                    $errorMessage .= "       - 模块：{$impl['module']}\n";
                    $errorMessage .= "         文件：{$impl['file']}\n";
                }
                
                // 尝试推断规约应该定义在哪个模块
                $hookParts = explode('::', $hook['name']);
                if (count($hookParts) >= 2) {
                    $expectedModule = $hookParts[0];
                    $errorMessage .= "\n";
                    $errorMessage .= "     💡 解决方案：\n";
                    $errorMessage .= "        请在模块 {$expectedModule} 的 hook.php 文件中定义此Hook的规约。\n";
                    $errorMessage .= "        文件路径：app/code/{$expectedModule}/hook.php\n";
                    $errorMessage .= "        示例：\n";
                    $errorMessage .= "        '{$hook['name']}' => [\n";
                    $errorMessage .= "            'name' => __('{$hook['display_name']}'),\n";
                    $errorMessage .= "            'description' => __('...'),\n";
                    $errorMessage .= "            'doc' => '...',\n";
                    $errorMessage .= "        ],\n";
                }
                
                $errorMessage .= "\n";
            }
            
            $errorMessage .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $errorMessage .= "💡 说明：\n";
            $errorMessage .= "   所有Hook实现文件都必须有对应的规约定义（在系统升级和hook:rebuild时强制检查）。\n";
            $errorMessage .= "   Hook规约应在定义Hook的模块的 hook.php 文件中声明。\n";
            $errorMessage .= "   详细说明请参考：app/code/Weline/Framework/Hook/doc/Hook顺序机制设计.md\n";
            $errorMessage .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            
            throw new \RuntimeException($errorMessage);
        }
    }

    /**
     * 保存注册表
     *
     * @param array $registry 注册表数据
     * @return bool
     */
    public function saveRegistry(array $registry): bool
    {
        $content = "<?php return " . var_export($registry, true) . ";\n";

        // 确保目录存在
        $dir = dirname(self::REGISTRY_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $result = file_put_contents(self::REGISTRY_FILE, $content, LOCK_EX);

        if ($result !== false) {
            $this->cachedRegistry = $registry;
            $this->cachedFileMtime = file_exists(self::REGISTRY_FILE) ? filemtime(self::REGISTRY_FILE) : 0;
            return true;
        }

        return false;
    }

    /**
     * 构建 Hook 冲突错误信息
     *
     * @param string $hookName 冲突的 Hook 名
     * @param string $existingModule 已定义该 Hook 的模块
     * @param string $conflictModule 冲突的模块
     * @param array $existingHookInfo 已定义 Hook 的信息
     * @param array $conflictHookInfo 冲突 Hook 的信息
     * @return string
     */
    private function buildConflictErrorMessage(
        string $hookName,
        string $existingModule,
        string $conflictModule,
        array $existingHookInfo,
        array $conflictHookInfo
    ): string {
        $existingModulePath = $this->getModulePath($existingModule);
        $conflictModulePath = $this->getModulePath($conflictModule);
        
        $message = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "【致命错误】Hook 名冲突检测\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        $message .= "❌ 冲突 Hook 名：{$hookName}\n\n";
        
        $message .= "📦 已注册模块信息：\n";
        $message .= "   模块名称：{$existingModule}\n";
        if ($existingModulePath) {
            $message .= "   模块路径：{$existingModulePath}\n";
        }
        $message .= "   规约文件：{$existingModulePath}/hook.php\n";
        if (!empty($existingHookInfo['name'])) {
            $message .= "   Hook 显示名：{$existingHookInfo['name']}\n";
        }
        $message .= "\n";
        
        $message .= "⚠️  冲突模块信息：\n";
        $message .= "   模块名称：{$conflictModule}\n";
        if ($conflictModulePath) {
            $message .= "   模块路径：{$conflictModulePath}\n";
        }
        $message .= "   规约文件：{$conflictModulePath}/hook.php\n";
        if (!empty($conflictHookInfo['name'])) {
            $message .= "   Hook 显示名：{$conflictHookInfo['name']}\n";
        }
        $message .= "\n";
        
        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "💡 解决方案\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        $message .= "方案 1：修改冲突模块的 Hook 名（推荐）\n";
        $message .= "   1. 打开文件：{$conflictModulePath}/hook.php\n";
        $message .= "   2. 修改 Hook 名以避免冲突\n";
        $message .= "   3. 更新所有使用该 Hook 的代码\n";
        $message .= "   4. 运行 'php bin/w hook:rebuild' 重建 Hook 注册表\n\n";
        
        $message .= "方案 2：删除冲突模块的 Hook 定义\n";
        $message .= "   如果 {$conflictModule} 模块不需要定义此 Hook，可以删除规约文件\n\n";
        
        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        
        return $message;
    }

    /**
     * 获取模块路径
     *
     * @param string $moduleName 模块名
     * @return string
     */
    private function getModulePath(string $moduleName): string
    {
        try {
            $env = \Weline\Framework\App\Env::getInstance();
            $moduleInfo = $env->getModuleInfo($moduleName);
            return $moduleInfo['base_path'] ?? '';
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * 检查 hook 是否已注册（在 HookInterface 中定义）
     * 
     * @param string $hookName Hook 名称
     * @return bool
     */
    public function isRegistered(string $hookName): bool
    {
        $this->initialize();
        return isset($this->registeredHooks[$hookName]);
    }
    
    /**
     * 获取所有已注册的 hook（从 HookInterface 中）
     * 
     * @return array
     */
    public function getAllRegisteredHooks(): array
    {
        $this->initialize();
        return array_keys($this->registeredHooks);
    }
    
    /**
     * 获取 hook 信息（从 HookInterface 中）
     * 
     * @param string $hookName Hook 名称
     * @return array|null
     */
    public function getHookInfoFromInterface(string $hookName): ?array
    {
        $this->initialize();
        return $this->registeredHooks[$hookName] ?? null;
    }

    /**
     * 获取 Hook 列表
     *
     * @return array
     */
    public function getHooks(): array
    {
        $registry = $this->getRegistry();
        return $registry['hooks'] ?? [];
    }

    /**
     * 获取 Hook 名到模块名的映射（快速查询）
     *
     * @return array
     */
    public function getHookToModuleMap(): array
    {
        $registry = $this->getRegistry();
        return $registry['hook_to_module'] ?? [];
    }

    /**
     * 检查 Hook 是否有规约
     *
     * @param string $hookName Hook 名
     * @return bool
     */
    public function hasSpec(string $hookName): bool
    {
        $hooks = $this->getHooks();
        return isset($hooks[$hookName]) && ($hooks[$hookName]['has_spec'] ?? false);
    }

    /**
     * 检查 Hook 是否有文档
     *
     * @param string $hookName Hook 名
     * @return bool
     */
    public function hasDoc(string $hookName): bool
    {
        $hooks = $this->getHooks();
        return isset($hooks[$hookName]) && ($hooks[$hookName]['has_doc'] ?? false);
    }

    /**
     * 获取 Hook 信息（从注册表）
     *
     * @param string $hookName Hook 名
     * @return array|null
     */
    public function getHookInfo(string $hookName): ?array
    {
        $hooks = $this->getHooks();
        return $hooks[$hookName] ?? null;
    }

    /**
     * 获取 Hook 所属的模块名
     *
     * @param string $hookName Hook 名
     * @return string|null
     */
    public function getHookModule(string $hookName): ?string
    {
        $hookToModule = $this->getHookToModuleMap();
        return $hookToModule[$hookName] ?? null;
    }
}

