<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Console\Cursor\Supervisor;

use Agent\CursorSupervisor\Service\CursorSupervisorService;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\System\Process\Processer;

/**
 * 停止 Cursor 智能监督助手
 */
class Stop extends CommandAbstract
{
    public function execute(array $args = [], array $data = [])
    {
        // 检查是否显示帮助
        if (isset($args['h']) || isset($args['help']) || isset($data['h']) || isset($data['help'])) {
            $this->printer->printing($this->help());
            return;
        }
        
        $processName = '--name=' . CursorSupervisorService::PROCESS_NAME;
        
        // 快速检测：从文件获取 PID + 验证存活
        $pid = (int) Processer::getData($processName, 'pid');
        
        if ($pid <= 0) {
            $this->printer->warning('Cursor 监督助手未在运行');
            return;
        }
        
        if (!Processer::isRunningByPid($pid)) {
            $this->printer->warning('Cursor 监督助手进程已退出，清理残留文件...');
            Processer::removePidFile($processName);
            $this->printer->success('清理完成');
            return;
        }
        
        $this->printer->note("正在停止 Cursor 监督助手 (PID: {$pid})...");
        
        // 使用进程管理器销毁（杀死 + 清理 PID 文件）
        Processer::destroy($processName);
        
        // 验证是否停止成功
        SchedulerSystem::yieldDelay(500); // 等待 0.5 秒
        
        if (Processer::isRunningByPid($pid)) {
            $this->printer->warning('进程仍在运行，尝试强制终止...');
            Processer::killByPid($pid);
            Processer::removePidFile($processName);
            
            SchedulerSystem::yieldDelay(500);
            if (Processer::isRunningByPid($pid)) {
                $this->printer->error('无法停止进程，请手动终止 PID: ' . $pid);
                return;
            }
        }
        
        $this->printer->success('🛑 Cursor 智能监督助手已停止');
    }
    
    public function tip(): string
    {
        return __('停止 Cursor 智能监督助手');
    }
    
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'cursor:supervisor:stop',
            '停止 Cursor 智能监督助手守护进程',
            [
                '-h, --help' => '显示帮助信息',
            ],
            [],
            [
                '停止服务' => 'php bin/w cursor:supervisor:stop',
            ]
        );
    }
}
