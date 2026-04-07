<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Module\Console\Module;

use Weline\Framework\App\Env;
use Weline\Framework\App\System;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\ConsoleException;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Handle;
use Weline\Framework\Module\Helper\Data;
use Weline\Framework\Registry\Service\RegistryUpdateService;
use Weline\Framework\Setup\Model\ModuleTable;
use Weline\Framework\Uninstall\UninstallService;

class Remove extends CommandAbstract
{
    /**
     * @var System
     */
    private System $system;

    /**
     * @var Data
     */
    private Data $data;

    /**
     * @var Handle
     */
    private Handle $handle;

    public function __construct(
        System  $system,
        Data    $data,
        Handle  $handle
    )
    {
        $this->system = $system;
        $this->data = $data;
        $this->handle = $handle;
    }

    /**
     * @DESC         |执行方法
     *
     * 参数区：
     *
     * @param array $args
     *
     * @return mixed|void
     * @throws \Weline\Framework\App\Exception
     * @throws ConsoleException
     */
    public function execute(array $args = [], array $data = [])
    {
        $args = $this->normalizeModuleArgs($args);

        // 获得模块列表
        $module_list = Env::getInstance()->getModuleList();
        if (empty($args)) {
            throw new ConsoleException('缺少模块名参数。示例：module:remove Aiweline_demo Aiweline_Test');
        }
        
        $this->printer->setup(__('提示：此命令将执行以下模块的卸载或清理程序。'));
        
        // 分类模块：正常存在的模块、代码不存在但已注册的模块、未注册的模块
        $normalModules = [];
        $codeNotExistModules = [];
        $notRegisteredModules = [];
        
        foreach ($args as $module) {
            if (!isset($module_list[$module])) {
                $notRegisteredModules[] = $module;
                $this->printer->warning($module . __(' - 模块不存在（未注册）'));
            } elseif (!is_dir($module_list[$module]['base_path'])) {
                $codeNotExistModules[] = $module;
                $this->printer->warning($module . __(' - 模块已安装但代码已删除'));
            } else {
                $normalModules[] = $module;
                $this->printer->note($module . __(' - 将执行卸载程序'));
            }
        }
        
        // 合并可处理模块（正常模块 + 代码不存在模块 + 未注册模块）
        $removableModules = array_merge($normalModules, $codeNotExistModules, $notRegisteredModules);
        
        if (empty($removableModules)) {
            $this->printer->error(__('无可卸载模块'));
            return;
        }
        
        // 统一批量确认，不再逐个询问
        $this->printer->setup(__('以下模块将被卸载：'));
        foreach ($removableModules as $module) {
            if (in_array($module, $notRegisteredModules, true)) {
                $suffix = __('（仅清理 module_table 残留）');
            } elseif (in_array($module, $codeNotExistModules, true)) {
                $suffix = __('（仅清理注册信息）');
            } else {
                $suffix = __('（执行卸载程序）');
            }
            $this->printer->note('  - ' . $module . ' ' . $suffix);
        }
        
        $this->printer->setup(__('是否继续（y/n）？'));
        $input = $this->system->input();
        
        if (strtolower(chop($input)) !== 'y') {
            $this->printer->warning(__('已取消执行！'));
            return;
        }
        
        if (empty($module_list)) {
            $this->printer->error('请先更新模块:bin/w module:upgrade');
            exit();
        }
        
        $uninstalledCount = 0;
        
        foreach ($removableModules as $module) {
            $isNotRegistered = in_array($module, $notRegisteredModules, true);
            $isCodeNotExist = in_array($module, $codeNotExistModules, true);

            if ($isNotRegistered) {
                // 未注册模块无法执行卸载流程，仅尝试清理 module_table 残留
                $this->printer->note(__('模块 %{1} 未注册，尝试清理 module_table 残留...', [$module]));
                $this->cleanupModuleTableRecords($module);
                $uninstalledCount++;
                continue;
            }
            
            if ($isCodeNotExist) {
                // 代码不存在的模块，仅清理注册信息
                $this->printer->note(__('清理模块 %{1} 的注册信息...', [$module]));
                $this->cleanupModuleTableRecords($module);
                unset($module_list[$module]);
                $this->printer->success(__('模块 %{1} 注册信息已清理', [$module]));
                $uninstalledCount++;
                continue;
            }
            
            // 正常模块执行完整卸载流程
            $this->printer->note(__('执行 ') . $module . __(' 卸载程序...'));

            // 卸载前数据库备份由外部模块通过事件实现（如 ModuleManager）
            $eventManager = ObjectManager::getInstance(EventsManager::class);
            $beforeBackupEventData = [
                'module_name' => $module,
                'result' => null,
            ];
            $eventManager->dispatch('Weline_Framework_Module::remove_before_backup', $beforeBackupEventData);
            $backupResult = $beforeBackupEventData['result'] ?? null;
            if (is_array($backupResult) && isset($backupResult['success'])) {
                $this->printer->note(__('开始为模块 %{1} 生成 MDP 数据包并重命名备份表...', [$module]));
                $dbBackupInfo = $backupResult;
                if (empty($dbBackupInfo['success'])) {
                    $this->printer->error(__('模块 %{1} 数据库备份失败：%{2}，已取消卸载。', [
                        $module,
                        $dbBackupInfo['message'] ?? '',
                    ]));
                    continue;
                }
                $this->printer->success(__('模块 %{1} 数据库表备份完成（时间戳：%{2}）', [
                    $module,
                    $dbBackupInfo['backup_timestamp'] ?? '',
                ]));
            }
            
            // 通过事件通知卸载服务执行卸载（文件级备份 + 事件）
            $eventData = [
                'type' => UninstallService::TYPE_MODULE,
                'name' => $module,
                'auto_backup' => true,
            ];
            $eventManager->dispatch('Weline_Framework_UninstallService::uninstall', $eventData);
            
            // 获取卸载结果
            $uninstallResult = $eventData['uninstall_result'] ?? null;
            if ($uninstallResult && isset($uninstallResult['success'])) {
                if ($uninstallResult['success']) {
                    $this->printer->success(__('模块 %{1} 备份成功', [$module]));
                    if (!empty($uninstallResult['backup_path'])) {
                        $this->printer->note(__('备份路径：%{1}', [$uninstallResult['backup_path']]));
                    }
                    // 显示卸载步骤
                    if (!empty($uninstallResult['steps'])) {
                        foreach ($uninstallResult['steps'] as $step) {
                            if (isset($step['message'])) {
                                $this->printer->note('  - ' . $step['message']);
                            }
                        }
                    }
                } else {
                    $this->printer->warning(__('模块 %{1} 备份失败：%{2}', [
                        $module,
                        $uninstallResult['message'] ?? __('未知错误')
                    ]));
                }
            }
            
            // 执行实际的卸载逻辑（数据库操作等）
            $this->handle->remove($module);
            $this->cleanupModuleTableRecords($module);
            // 卸载数组中模块
            unset($module_list[$module]);
            $uninstalledCount++;
        }
        
        // 更新模块数据
        $this->data->updateModules($module_list);
        
        // 卸载完成后，自动执行注册表更新（重建 extends、事件、hook 等）
        if ($uninstalledCount > 0) {
            $this->printer->note(__('正在更新系统注册表（Extends、事件、Hook、插件等）...'));
            try {
                /** @var RegistryUpdateService $registryService */
                $registryService = ObjectManager::getInstance(RegistryUpdateService::class);
                $ok = $registryService->updateAllRegistries(false, false, false);
                if ($ok) {
                    $this->printer->success(__('✓ 系统注册表已更新完成。'));
                } else {
                    $this->printer->warning(__('部分注册表更新失败，建议手动执行：php bin/w setup:upgrade'));
                }
            } catch (\Exception $e) {
                $this->printer->warning(__('注册表更新时发生错误：%{1}，建议手动执行：php bin/w setup:upgrade', [$e->getMessage()]));
            }
            
            // 收集 Tag 注册表
            try {
                $this->printer->note(__('正在更新标签注册表...'));
                $eventManager = ObjectManager::getInstance(EventsManager::class);
                $taglibEventData = ['result' => null];
                $eventManager->dispatch('Weline_Framework_Module::remove_after_taglib_collect', $taglibEventData);
                $taglibResult = $taglibEventData['result'] ?? null;
                if (is_array($taglibResult) && isset($taglibResult['success']) && !$taglibResult['success']) {
                    $this->printer->warning(__('标签注册表更新失败：%{1}', [$taglibResult['message'] ?? '']));
                } else {
                    $this->printer->success(__('✓ 标签注册表已更新完成。'));
                }
            } catch (\Exception $e) {
                $this->printer->warning(__('标签注册表更新时发生错误：%{1}', [$e->getMessage()]));
            }
            
            // 执行菜单 + ACL 差集同步，移除已卸载模块在 weline_acl 中的残留菜单/权限
            try {
                $this->printer->note(__('正在同步菜单与 ACL 差集（移除已卸载模块残留资源）...'));
                // 必须先刷新 Env 缓存，否则 Scanner 仍使用旧的模块列表
                Env::getInstance()->getModuleList(true);
                Env::getInstance()->getActiveModules(true);
                $this->syncAclResourcesAfterModuleRemoval($removableModules);
                $this->printer->success(__('✓ 菜单与 ACL 已同步完成。'));
            } catch (\Exception $e) {
                $this->printer->warning(__('菜单与 ACL 差集同步时发生错误：%{1}，可手动执行：php bin/w setup:upgrade --route', [$e->getMessage()]));
            }
            
            $this->printer->success(__('模块卸载完成，共卸载 %{1} 个模块。', [$uninstalledCount]));
        }
    }

    /**
     * @DESC         |命令提示
     *
     * 参数区：
     *
     * @return string
     */
    public function tip(): string
    {
        return '批量卸载模块，执行卸载脚本并自动更新系统注册表';
    }

    /**
     * 卸载模块后执行菜单与 ACL 的全量差集同步。
     *
     * 流程与 setup:upgrade 的路由收集阶段保持一致：
     * 1. 清空本轮收集 registry，并触发菜单预收集
     * 2. updateRoutes([])：重新收集现存模块路由，并落库控制器 ACL
     * 3. 直接按「当前有效菜单 ∪ 当前有效 ACL」做差集，删除残留资源
     *
     * @throws \Weline\Framework\App\Exception
     */
    private function syncAclResourcesAfterModuleRemoval(array $moduleNames = []): void
    {
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        $aclDiffBeginEventData = [
            'module_names' => $moduleNames,
        ];
        $eventsManager->dispatch('Weline_Framework_Module::remove_acl_diff_begin', $aclDiffBeginEventData);

        $beforeRouteCollectionEventData = [];
        $eventsManager->dispatch('Weline_Framework_Setup::before_route_collection', $beforeRouteCollectionEventData);

        /** @var \Weline\Framework\Router\Service\RouteUpdateService $routeUpdateService */
        $routeUpdateService = ObjectManager::getInstance(\Weline\Framework\Router\Service\RouteUpdateService::class);
        $routeUpdateService->updateRoutes([]);

        $aclDiffCleanupEventData = [
            'module_names' => $moduleNames,
            'result' => null,
        ];
        $eventsManager->dispatch('Weline_Framework_Module::remove_acl_diff_cleanup', $aclDiffCleanupEventData);
    }

    /**
     * 标准化命令行参数，避免把 module:remove 本身识别为模块名。
     *
     * @param array<int|string, mixed> $args
     * @return array<int, string>
     */
    private function normalizeModuleArgs(array $args): array
    {
        if (isset($args[0]) && is_string($args[0]) && str_starts_with($args[0], 'module:')) {
            array_shift($args);
        }

        $modules = [];
        foreach ($args as $arg) {
            if (!is_scalar($arg)) {
                continue;
            }
            $value = trim((string) $arg);
            if ($value === '' || str_starts_with($value, '-')) {
                continue;
            }
            if ($value === 'module:remove') {
                continue;
            }
            $modules[] = $value;
        }

        return array_values(array_unique($modules));
    }

    /**
     * 卸载模块后同步清理 weline_module_table 中的模块归属记录。
     */
    private function cleanupModuleTableRecords(string $moduleName): void
    {
        try {
            /** @var ModuleTable $moduleTable */
            $moduleTable = ObjectManager::getInstance(ModuleTable::class);
            $moduleTable->clearData()->clearQuery()
                ->where(ModuleTable::schema_fields_module_name, $moduleName)
                ->delete();
            $this->printer->note(__('已清理模块 %{1} 的 module_table 记录。', [$moduleName]));
        } catch (\Throwable $e) {
            $this->printer->warning(__('清理模块 %{1} 的 module_table 记录失败：%{2}', [$moduleName, $e->getMessage()]));
        }
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
            ],
            [
                'module_name' => '要卸载的模块名，支持多个模块空格分隔',
            ],
            [
                '卸载单个模块' => 'php bin/w module:remove Vendor_Module',
                '批量卸载多个模块' => 'php bin/w module:remove Vendor_Module1 Vendor_Module2 Vendor_Module3',
            ]
        );
    }
}
