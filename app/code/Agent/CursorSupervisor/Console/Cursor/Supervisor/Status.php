<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Console\Cursor\Supervisor;

use Agent\CursorSupervisor\Service\CursorSupervisorService;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\System\Process\Processer;

/**
 * 查看 Cursor 智能监督助手状态
 */
class Status extends CommandAbstract
{
    public function execute(array $args = [], array $data = [])
    {
        // 检查是否显示帮助
        if (isset($args['h']) || isset($args['help']) || isset($data['h']) || isset($data['help'])) {
            $this->printer->printing($this->help());
            return;
        }
        
        $processName = '--name=' . CursorSupervisorService::PROCESS_NAME;
        
        // 获取进程数据
        $processData = Processer::getData($processName);
        $pid = (int) ($processData['pid'] ?? 0);
        
        $this->printer->printing('');
        $this->printer->printing('═══════════════════════════════════════════════════');
        $this->printer->printing('       Cursor 智能监督助手状态');
        $this->printer->printing('═══════════════════════════════════════════════════');
        $this->printer->printing('');
        
        if ($pid <= 0) {
            $this->printer->warning('状态: 未运行');
            $this->printer->printing('');
            $this->printer->note('启动命令: php bin/w cursor:supervisor:start');
            return;
        }
        
        $isRunning = Processer::isRunningByPid($pid);
        
        if ($isRunning) {
            $this->printer->success('状态: 运行中 ✅');
        } else {
            $this->printer->warning('状态: 已停止（残留 PID 文件）');
        }
        
        $this->printer->printing('');
        $this->printer->printing('进程信息:');
        $this->printer->printing("  PID: {$pid}");
        
        if (isset($processData['date'])) {
            $this->printer->printing("  启动时间: {$processData['date']}");
        }
        
        if (isset($processData['time'])) {
            $runtime = time() - (int) $processData['time'];
            $this->printer->printing("  运行时长: " . $this->formatDuration($runtime));
        }
        
        // 获取详细进程信息
        if ($isRunning) {
            $processInfo = Processer::getProcessInfo($pid);
            if ($processInfo && isset($processInfo['memory'])) {
                $this->printer->printing("  内存使用: {$processInfo['memory']}");
            }
        }
        
        $this->printer->printing('');
        
        // 日志信息
        $logFile = BP . 'var/log/cursor-supervisor.log';
        if (file_exists($logFile)) {
            $this->printer->printing('日志文件: var/log/cursor-supervisor.log');
            $this->printer->printing('查看日志: tail -f var/log/cursor-supervisor.log');
        }
        
        $this->printer->printing('');
        
        if (!$isRunning) {
            $this->printer->note('提示: 进程已停止，可执行以下命令清理或重启');
            $this->printer->printing('  清理: php bin/w cursor:supervisor:stop');
            $this->printer->printing('  启动: php bin/w cursor:supervisor:start');
        } else {
            $this->printer->note('提示: 停止服务 - php bin/w cursor:supervisor:stop');
        }
        
        $this->printer->printing('');
    }
    
    /**
     * 格式化时长
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds} 秒";
        }
        
        $minutes = floor($seconds / 60);
        $secs = $seconds % 60;
        
        if ($minutes < 60) {
            return "{$minutes} 分 {$secs} 秒";
        }
        
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        
        if ($hours < 24) {
            return "{$hours} 小时 {$mins} 分";
        }
        
        $days = floor($hours / 24);
        $hrs = $hours % 24;
        
        return "{$days} 天 {$hrs} 小时";
    }
    
    public function tip(): string
    {
        return __('查看 Cursor 智能监督助手状态');
    }
    
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'cursor:supervisor:status',
            '查看 Cursor 智能监督助手的运行状态',
            [
                '-h, --help' => '显示帮助信息',
            ],
            [],
            [
                '查看状态' => 'php bin/w cursor:supervisor:status',
            ]
        );
    }
}
