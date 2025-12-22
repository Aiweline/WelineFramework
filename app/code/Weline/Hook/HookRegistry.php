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
    public function refresh(): bool
    {
        // 扫描所有 Hook 规约（使用 extends 方式）
        $scannedData = $this->scanner->scanAllHooks();

        // 组织数据结构，按 Hook 名索引（如果发现冲突会抛出异常）
        $registry = $this->organizeRegistryData($scannedData);

        // 保存注册表
        return $this->saveRegistry($registry);
    }

    /**
     * 组织注册表数据
     * 将模块级别的 Hook 信息转换为 Hook 名索引的结构
     *
     * @param array $scannedData 扫描的数据
     * @return array
     * @throws \RuntimeException 如果多个模块定义了相同的 Hook 名
     */
    private function organizeRegistryData(array $scannedData): array
    {
        $registry = [];
        // 快速查询：Hook 名到模块名的映射（用于性能优化）
        $hookToModuleMap = [];

        foreach ($scannedData as $moduleName => $hooks) {
            foreach ($hooks as $hookName => $hookInfo) {
                // 检查 Hook 名是否已被其他模块定义
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

                // 添加新 Hook
                $registry[$hookName] = [
                    'name' => $hookInfo['name'] ?? $hookName,
                    'description' => $hookInfo['description'] ?? '',
                    'doc' => $hookInfo['doc'] ?? '',
                    'doc_path' => $hookInfo['doc_path'] ?? '',
                    'has_spec' => $hookInfo['has_spec'] ?? false,
                    'has_doc' => $hookInfo['has_doc'] ?? false,
                    'module' => $moduleName, // 定义该 Hook 的模块
                ];
                // 添加到快速查询映射
                $hookToModuleMap[$hookName] = $moduleName;
            }
        }

        // 返回包含快速查询映射的数据结构
        return [
            'hooks' => $registry,
            'hook_to_module' => $hookToModuleMap, // 快速查询：Hook 名 => 模块名
        ];
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

