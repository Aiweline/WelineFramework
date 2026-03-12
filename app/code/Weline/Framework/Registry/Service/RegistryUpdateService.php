<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Registry\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Event\EventRegistry;
use Weline\Framework\Extends\ExtendsRegistry;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Plugin\PluginRegistry;
use Weline\Hook\HookRegistry;

/**
 * 注册表更新服务
 * 统一管理所有注册表（Extends、插件、事件、Hook、命令）的更新操作
 * 确保在模块状态变更时自动更新相关注册表
 */
class RegistryUpdateService
{
    /**
     * 更新所有注册表
     * 包括：Extends、插件、事件、Hook、命令
     * 
     * @param bool $silent 是否静默执行（不输出信息）
     * @param bool|null $autoCompile 是否在更新插件注册表后自动编译
     *                                - null: 自动检测（如果系统更新锁存在则跳过编译，否则执行编译）
     *                                - true: 强制编译
     *                                - false: 强制跳过编译
     * @param bool $skipCommandUpdate 是否跳过命令更新（在 setup:upgrade 中单独更新命令时使用）
     * @return bool 是否全部成功
     */
    public function updateAllRegistries(bool $silent = false, ?bool $autoCompile = null, bool $skipCommandUpdate = false): bool
    {
        $allSuccess = true;
        
        try {
            // 1. 更新 Extends 注册表
            if (!$silent) {
                w_log_info(__('正在更新 Extends 注册表...'), [], 'registry_update.log');
            }
            /** @var ExtendsRegistry $extendsRegistry */
            $extendsRegistry = ObjectManager::getInstance(ExtendsRegistry::class);
            $ok = $extendsRegistry->refresh();
            if ($ok) {
                $extendsRegistry->getRegistry(true); // 强制重新加载
                if (!$silent) {
                    w_log_info(__('✓ Extends 注册表已更新完成。'), [], 'registry_update.log');
                }
            } else {
                $allSuccess = false;
                w_log_warning(__('Extends 注册表更新失败，但将继续执行。'), [], 'registry_update.log');
            }
        } catch (\Exception $e) {
            $allSuccess = false;
            w_log_warning(__('Extends 注册表更新失败: %{1}', [$e->getMessage()]), [], 'registry_update.log');
        }
        
        try {
            // 2. 更新插件注册表
            if (!$silent) {
                w_log_info(__('正在更新插件注册表...'), [], 'registry_update.log');
            }
            /** @var PluginRegistry $pluginRegistry */
            $pluginRegistry = ObjectManager::getInstance(PluginRegistry::class);
            $ok = $pluginRegistry->refresh();
            if ($ok) {
                $pluginRegistry->getRegistry(true); // 强制重新加载
                if (!$silent) {
                    w_log_info(__('✓ 插件注册表已更新完成。'), [], 'registry_update.log');
                }
                
                // 插件注册表更新完成后，根据参数决定是否立即编译插件/DI
                // 如果 autoCompile 为 null，则自动检测：系统更新锁存在时跳过编译，否则执行编译
                $shouldCompile = $autoCompile;
                if ($autoCompile === null) {
                    $shouldCompile = !$this->isUpgradeLockExists();
                }
                
                if ($shouldCompile) {
                    try {
                        if (!$silent) {
                            w_log_info(__('正在编译插件/DI以识别新注册的插件...'), [], 'registry_update.log');
                        }
                        /** @var \Weline\Framework\Plugin\Console\Plugin\Di\Compile $diCompile */
                        $diCompile = ObjectManager::getInstance(\Weline\Framework\Plugin\Console\Plugin\Di\Compile::class);
                        $diCompile->execute();
                        if (!$silent) {
                            w_log_info(__('✓ 插件/DI编译完成，新插件已识别。'), [], 'registry_update.log');
                        }
                    } catch (\Exception $compileException) {
                        w_log_warning(__('插件/DI编译失败：%{1}，但将继续执行。', [$compileException->getMessage()]), [], 'registry_update.log');
                    }
                }
            } else {
                $allSuccess = false;
                w_log_warning(__('插件注册表更新失败，但将继续执行。'), [], 'registry_update.log');
            }
        } catch (\Exception $e) {
            $allSuccess = false;
            w_log_warning(__('插件注册表更新失败: %{1}', [$e->getMessage()]), [], 'registry_update.log');
        }
        
        try {
            // 3. 更新事件注册表
            if (!$silent) {
                w_log_info(__('正在更新事件注册表...'), [], 'registry_update.log');
            }
            /** @var EventRegistry $eventRegistry */
            $eventRegistry = ObjectManager::getInstance(EventRegistry::class);
            $ok = $eventRegistry->refresh();
            if ($ok) {
                $eventRegistry->getRegistry(true); // 强制重新加载
                /** @var \Weline\Framework\Event\EventsManager $eventsManager */
                $eventsManager = ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
                $eventsManager->clearObserverCache(); // 事件注册表已刷新，清空观察者缓存以便从新注册表读取
                if (!$silent) {
                    w_log_info(__('✓ 事件注册表已更新完成。'), [], 'registry_update.log');
                }
            } else {
                $allSuccess = false;
                w_log_warning(__('事件注册表更新失败，但将继续执行。'), [], 'registry_update.log');
            }
        } catch (\Exception $e) {
            $allSuccess = false;
            w_log_warning(__('事件注册表更新失败: %{1}', [$e->getMessage()]), [], 'registry_update.log');
        }
        
        try {
            // 4. 更新 Hook 注册表
            if (!$silent) {
                w_log_info(__('正在更新 Hook 注册表...'), [], 'registry_update.log');
            }
            /** @var HookRegistry $hookRegistry */
            $hookRegistry = ObjectManager::getInstance(HookRegistry::class);
            // 系统升级时，允许solo hook冲突，只记录警告，不阻止保存
            // 但是文档检查必须通过，否则直接抛出异常停止系统更新
            $ok = $hookRegistry->refresh(true);
            if ($ok) {
                $hookRegistry->getRegistry(true); // 强制重新加载
                if (!$silent) {
                    w_log_info(__('✓ Hook 注册表已更新完成。'), [], 'registry_update.log');
                }
            } else {
                $allSuccess = false;
                w_log_warning(__('Hook 注册表更新失败，但将继续执行。'), [], 'registry_update.log');
            }
        } catch (\RuntimeException $e) {
            // Hook 收集阶段的错误（如缺少文档、缺少元数据、solo冲突等）必须停止系统更新
            // 检查是否是致命错误（包含"【致命错误】"标记）
            $errorMessage = $e->getMessage();
            if (str_contains($errorMessage, '【致命错误】') || 
                str_contains($errorMessage, '缺少文档') || 
                str_contains($errorMessage, '缺少规约') ||
                str_contains($errorMessage, '缺少必需的元数据') ||
                str_contains($errorMessage, 'Hook独享冲突') ||
                str_contains($errorMessage, 'Hook被独占冲突')) {
                // 记录错误并重新抛出异常，停止系统更新
                w_log_error(__('Hook 注册表更新失败（致命错误）: %{1}', [$errorMessage]), [], 'registry_update.log');
                throw $e; // 重新抛出异常，停止系统更新
            }
            // 其他 RuntimeException 也记录并抛出
            $allSuccess = false;
            w_log_error(__('Hook 注册表更新失败: %{1}', [$errorMessage]), [], 'registry_update.log');
            throw $e;
        } catch (\Exception $e) {
            $allSuccess = false;
            w_log_warning(__('Hook 注册表更新失败: %{1}', [$e->getMessage()]), [], 'registry_update.log');
        }
        
        // 5. 更新命令注册表（可跳过，在 setup:upgrade 中单独更新命令时使用）
        if (!$skipCommandUpdate) {
            try {
                if (!$silent) {
                    w_log_info(__('正在更新命令注册表...'), [], 'registry_update.log');
                }
                /** @var \Weline\Framework\Console\Console\Command\Upgrade $commandUpgrade */
                $commandUpgrade = ObjectManager::getInstance(\Weline\Framework\Console\Console\Command\Upgrade::class);
                $commandUpgrade->execute();
                if (!$silent) {
                    w_log_info(__('✓ 命令注册表已更新完成。'), [], 'registry_update.log');
                }
            } catch (\Exception $e) {
                $allSuccess = false;
                w_log_warning(__('命令注册表更新失败: %{1}', [$e->getMessage()]), [], 'registry_update.log');
            }
        } else {
            if (!$silent) {
                w_log_info(__('跳过命令注册表更新（由调用方单独处理）'), [], 'registry_update.log');
            }
        }
        
        return $allSuccess;
    }
    
    /**
     * 增量更新指定模块的所有注册表
     * 仅重新扫描和更新指定模块的数据，不重建整个注册表
     * 
     * @param array $moduleNames 模块名数组
     * @param bool $silent 是否静默执行（不输出信息）
     * @return bool 是否全部成功
     */
    public function updateModuleRegistriesIncremental(array $moduleNames, bool $silent = false): bool
    {
        if (empty($moduleNames)) {
            return true;
        }
        
        $allSuccess = true;
        
        // 1. 更新 Extends 注册表
        try {
            if (!$silent) {
                w_log_info(__('正在增量更新 Extends 注册表（模块：%{1}）...', [implode(', ', $moduleNames)]), [], 'registry_update.log');
            }
            /** @var ExtendsRegistry $extendsRegistry */
            $extendsRegistry = ObjectManager::getInstance(ExtendsRegistry::class);
            $ok = $extendsRegistry->refreshForModules($moduleNames);
            if ($ok) {
                $extendsRegistry->getRegistry(true);
                if (!$silent) {
                    w_log_info(__('✓ Extends 注册表增量更新完成。'), [], 'registry_update.log');
                }
            } else {
                $allSuccess = false;
                w_log_warning(__('Extends 注册表增量更新失败，但将继续执行。'), [], 'registry_update.log');
            }
        } catch (\Exception $e) {
            $allSuccess = false;
            w_log_warning(__('Extends 注册表增量更新失败: %{1}', [$e->getMessage()]), [], 'registry_update.log');
        }
        
        // 2. 更新插件注册表
        try {
            if (!$silent) {
                w_log_info(__('正在增量更新插件注册表（模块：%{1}）...', [implode(', ', $moduleNames)]), [], 'registry_update.log');
            }
            /** @var PluginRegistry $pluginRegistry */
            $pluginRegistry = ObjectManager::getInstance(PluginRegistry::class);
            $ok = $pluginRegistry->refreshForModules($moduleNames);
            if ($ok) {
                $pluginRegistry->getRegistry(true);
                if (!$silent) {
                    w_log_info(__('✓ 插件注册表增量更新完成。'), [], 'registry_update.log');
                }
                
                // 编译指定模块的插件
                if (!$this->isUpgradeLockExists()) {
                    try {
                        if (!$silent) {
                            w_log_info(__('正在编译模块插件/DI...'), [], 'registry_update.log');
                        }
                        /** @var \Weline\Framework\Plugin\PluginsManager $pluginsManager */
                        $pluginsManager = ObjectManager::getInstance(\Weline\Framework\Plugin\PluginsManager::class);
                        $pluginsManager->compileForModules($moduleNames);
                        if (!$silent) {
                            w_log_info(__('✓ 模块插件/DI编译完成。'), [], 'registry_update.log');
                        }
                    } catch (\Exception $compileException) {
                        w_log_warning(__('模块插件/DI编译失败：%{1}，但将继续执行。', [$compileException->getMessage()]), [], 'registry_update.log');
                    }
                }
            } else {
                $allSuccess = false;
                w_log_warning(__('插件注册表增量更新失败，但将继续执行。'), [], 'registry_update.log');
            }
        } catch (\Exception $e) {
            $allSuccess = false;
            w_log_warning(__('插件注册表增量更新失败: %{1}', [$e->getMessage()]), [], 'registry_update.log');
        }
        
        // 3. 更新事件注册表
        try {
            if (!$silent) {
                w_log_info(__('正在增量更新事件注册表（模块：%{1}）...', [implode(', ', $moduleNames)]), [], 'registry_update.log');
            }
            /** @var EventRegistry $eventRegistry */
            $eventRegistry = ObjectManager::getInstance(EventRegistry::class);
            $ok = $eventRegistry->refreshForModules($moduleNames);
            if ($ok) {
                $eventRegistry->getRegistry(true);
                /** @var \Weline\Framework\Event\EventsManager $eventsManager */
                $eventsManager = ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
                $eventsManager->clearObserverCache();
                if (!$silent) {
                    w_log_info(__('✓ 事件注册表增量更新完成。'), [], 'registry_update.log');
                }
            } else {
                $allSuccess = false;
                w_log_warning(__('事件注册表增量更新失败，但将继续执行。'), [], 'registry_update.log');
            }
        } catch (\Exception $e) {
            $allSuccess = false;
            w_log_warning(__('事件注册表增量更新失败: %{1}', [$e->getMessage()]), [], 'registry_update.log');
        }
        
        // 4. 更新 Hook 注册表
        try {
            if (!$silent) {
                w_log_info(__('正在增量更新 Hook 注册表（模块：%{1}）...', [implode(', ', $moduleNames)]), [], 'registry_update.log');
            }
            /** @var HookRegistry $hookRegistry */
            $hookRegistry = ObjectManager::getInstance(HookRegistry::class);
            $ok = $hookRegistry->refreshForModules($moduleNames, true);
            if ($ok) {
                $hookRegistry->getRegistry(true);
                if (!$silent) {
                    w_log_info(__('✓ Hook 注册表增量更新完成。'), [], 'registry_update.log');
                }
            } else {
                $allSuccess = false;
                w_log_warning(__('Hook 注册表增量更新失败，但将继续执行。'), [], 'registry_update.log');
            }
        } catch (\RuntimeException $e) {
            $errorMessage = $e->getMessage();
            if (str_contains($errorMessage, '【致命错误】')) {
                w_log_error(__('Hook 注册表增量更新失败（致命错误）: %{1}', [$errorMessage]), [], 'registry_update.log');
                throw $e;
            }
            $allSuccess = false;
            w_log_warning(__('Hook 注册表增量更新失败: %{1}', [$errorMessage]), [], 'registry_update.log');
        } catch (\Exception $e) {
            $allSuccess = false;
            w_log_warning(__('Hook 注册表增量更新失败: %{1}', [$e->getMessage()]), [], 'registry_update.log');
        }
        
        // 5. 更新命令注册表
        try {
            if (!$silent) {
                w_log_info(__('正在增量更新命令注册表（模块：%{1}）...', [implode(', ', $moduleNames)]), [], 'registry_update.log');
            }
            /** @var \Weline\Framework\Console\Console\Command\Upgrade $commandUpgrade */
            $commandUpgrade = ObjectManager::getInstance(\Weline\Framework\Console\Console\Command\Upgrade::class);
            $commandUpgrade->refreshForModules($moduleNames);
            if (!$silent) {
                w_log_info(__('✓ 命令注册表增量更新完成。'), [], 'registry_update.log');
            }
        } catch (\Exception $e) {
            $allSuccess = false;
            w_log_warning(__('命令注册表增量更新失败: %{1}', [$e->getMessage()]), [], 'registry_update.log');
        }
        
        // 6. 更新 Taglib（已支持模块参数）
        try {
            if (!$silent) {
                w_log_info(__('正在增量更新 Taglib（模块：%{1}）...', [implode(', ', $moduleNames)]), [], 'registry_update.log');
            }
            /** @var \Weline\Framework\Event\EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
            $eventData = [
                'module_names' => $moduleNames,
                'skip_template_cache_clear' => false,
                'result' => null,
            ];
            $eventsManager->dispatch('Weline_Framework_Setup::collect_taglib_registry', $eventData);
            $result = $eventData['result'] ?? null;
            if (is_array($result) && isset($result['success']) && !$result['success']) {
                throw new \RuntimeException((string)($result['message'] ?? __('未知错误')));
            }
            if (!$silent) {
                w_log_info(__('✓ Taglib 增量更新完成。'), [], 'registry_update.log');
            }
        } catch (\Exception $e) {
            $allSuccess = false;
            w_log_warning(__('Taglib 增量更新失败: %{1}', [$e->getMessage()]), [], 'registry_update.log');
        }
        
        return $allSuccess;
    }
    
    /**
     * 更新指定模块相关的注册表
     * 当模块状态变更时，需要更新所有注册表以确保一致性
     * 
     * @param string|array $moduleNames 模块名或模块名数组
     * @param bool $silent 是否静默执行（不输出信息）
     * @return bool 是否全部成功
     */
    public function updateModuleRegistries($moduleNames, bool $silent = false): bool
    {
        // 将单个模块名转换为数组
        if (is_string($moduleNames)) {
            $moduleNames = [$moduleNames];
        }
        
        // 检查模块是否存在且状态已变更
        $env = Env::getInstance();
        $hasChanges = false;
        
        foreach ($moduleNames as $moduleName) {
            $moduleInfo = $env->getModuleInfo($moduleName);
            if (!empty($moduleInfo)) {
                $hasChanges = true;
                break;
            }
        }
        
        if (!$hasChanges) {
            if (!$silent) {
                w_log_info(__('没有需要更新的模块，跳过注册表更新。'), [], 'registry_update.log');
            }
            return true;
        }
        
        // 更新所有注册表（因为模块状态变更可能影响多个注册表）
        return $this->updateAllRegistries($silent);
    }
    
    /**
     * 检查系统更新锁是否存在
     * 如果锁文件存在且无法以非阻塞方式获取锁，说明系统更新命令正在执行
     * 
     * @return bool
     */
    private function isUpgradeLockExists(): bool
    {
        $lockFile = BP . 'var' . DS . 'process' . DS . 'setup_upgrade.lock';
        
        if (!file_exists($lockFile)) {
            return false;
        }
        
        // 尝试以非阻塞方式打开锁文件并获取锁
        // 如果获取失败，说明锁被其他进程持有（系统更新正在运行）
        $handle = @fopen($lockFile, 'r');
        if ($handle === false) {
            // 文件无法打开，可能不存在或权限问题，认为锁不存在
            return false;
        }
        
        // 尝试以非阻塞方式获取共享锁（只读）
        // 如果获取失败，说明其他进程持有排他锁（系统更新正在运行）
        $lockAcquired = @flock($handle, LOCK_SH | LOCK_NB);
        
        // 无论是否获取到锁，都要关闭文件句柄
        @flock($handle, LOCK_UN);
        @fclose($handle);
        
        // 如果无法获取锁，说明系统更新正在运行
        return !$lockAcquired;
    }
    
    /**
     * 检查进程是否还在运行
     * 
     * @param int $pid 进程ID
     * @return bool
     */
    private function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows 系统：使用 tasklist 命令检查进程
            $output = [];
            $returnVar = 0;
            @exec("tasklist /FI \"PID eq {$pid}\" 2>NUL", $output, $returnVar);
            if ($returnVar === 0 && !empty($output)) {
                // 检查输出中是否包含进程ID
                foreach ($output as $line) {
                    if (strpos($line, (string)$pid) !== false) {
                        return true;
                    }
                }
            }
            return false;
        } else {
            // Unix/Linux 系统：使用 kill -0 检查进程
            return @posix_kill($pid, 0);
        }
    }
}
