<?php

/*
 * 模块数据恢复命令
 *
 * 支持从 ModuleManager 记录的数据库备份中恢复指定模块的表。
 */

namespace Weline\Framework\Module\Console\Module;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\App\System;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\ConsoleException;
use Weline\Framework\Manager\ObjectManager;

class Restore extends CommandAbstract
{
    private System $system;

    public function __construct(System $system)
    {
        $this->system = $system;
    }

    /**
     * @DESC         |从数据库备份中恢复模块数据表
     *
     * 参数区：
     *
     * @param array $args
     * @param array $data
     * @return mixed|void
     * @throws Exception
     * @throws ConsoleException
     */
    public function execute(array $args = [], array $data = [])
    {
        array_shift($args);

        // 解析参数：module 名称和可选的 --backup=时间戳
        $moduleName      = $args['module'] ?? $args['m'] ?? null;
        $backupTimestamp = $args['backup'] ?? null;

        if (is_array($moduleName)) {
            throw new ConsoleException(__('一次仅支持恢复一个模块，请使用 -m 或 --module 指定单个模块名'));
        }

        if (!$moduleName) {
            throw new ConsoleException(__('缺少模块名参数。示例：php bin/w module:restore -m Weline_Demo'));
        }

        $modules = Env::getInstance()->getModuleList();
        if (!isset($modules[$moduleName])) {
            $this->printer->warning(__('模块 %{1} 在系统注册中不存在（可能已卸载），但仍可尝试恢复数据库表。', [$moduleName]));
        }

        $this->printer->note('');
        $this->printer->note('═══════════════════════════════════════════════════════════════');
        $this->printer->setup(__('开始从备份中恢复模块数据：%{1}', [$moduleName]));
        if ($backupTimestamp) {
            $this->printer->note(__('指定备份时间戳：%{1}', [$backupTimestamp]));
        } else {
            $this->printer->note(__('未指定备份时间戳，将使用最新的备份记录'));
        }
        $this->printer->note('═══════════════════════════════════════════════════════════════');

        // 确认操作
        $this->printer->warning(__('此操作将删除当前模块相关表（如果存在），并用备份表覆盖。'));
        $this->printer->warning(__('请确保已经备份当前数据。'));
        $this->printer->setup(__('输入 "yes" 或 "y" 确认继续，其他任何输入将取消：'));
        $confirm = strtolower(trim($this->system->input()));
        if ($confirm !== 'yes' && $confirm !== 'y') {
            $this->printer->note(__('操作已取消。'));
            return;
        }

        /** @var \Weline\Framework\Event\EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
        $eventData = [
            'module_name' => $moduleName,
            'backup_timestamp' => $backupTimestamp ?: null,
            'result' => null,
        ];
        $eventsManager->dispatch('Weline_Framework_UninstallService::module_db_restore', $eventData);
        $result = $eventData['result'] ?? null;
        if (!is_array($result)) {
            throw new ConsoleException(__('未检测到数据库恢复监听器，请确认提供恢复能力的模块已安装并启用。'));
        }

        if (!empty($result['success'])) {
            $this->printer->success(__('模块 %{1} 数据库表恢复成功。', [$moduleName]));
        } else {
            $this->printer->error(__('模块 %{1} 数据库表恢复失败：%{2}', [
                $moduleName,
                $result['message'] ?? '',
            ]));
        }
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'module:restore',
            '从数据库备份中恢复模块的数据表',
            [
                '-m, --module=<模块名>'   => '指定要恢复的模块（必填）',
                '--backup=<时间戳>'      => '可选，指定备份时间戳（YYYYMMDD_HHMMSS），默认使用最新备份',
                '-h, --help'             => '显示帮助信息',
            ],
            [
                '安全提示'   => '恢复操作会删除当前模块表并用备份表覆盖，执行前请确认当前数据不再需要',
                '备份来源'   => '备份数据来自模块卸载时的表重命名备份记录',
                '模块依赖'   => '需要 Weline_ModuleManager 模块提供的 ModuleBackupService 和备份记录表',
            ],
            [
                '恢复最新备份'     => 'php bin/w module:restore -m Weline_Demo',
                '恢复指定备份批次' => 'php bin/w module:restore -m Weline_Demo --backup=20250127_143000',
            ],
            'php bin/w module:restore -m <模块名> [--backup=<时间戳>]'
        );
    }
}


