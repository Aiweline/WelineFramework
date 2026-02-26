<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Console\Cursor\Orchestrator;

use Agent\CursorBase\Service\TaskPoolService;
use Agent\CursorSupervisor\Service\MasterBrainService;
use Agent\CursorSupervisor\Service\CursorDriverService;
use Agent\CursorSupervisor\Service\WatchdogService;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Output\Printer;

/**
 * PHP Agent Orchestrator - 主命令
 * 
 * 分布式任务总线：Master Brain + Task Pool + Driver + Watchdog
 */
class Run extends CommandAbstract
{
    private WatchdogService $watchdog;
    private MasterBrainService $masterBrain;
    private TaskPoolService $taskPool;
    private CursorDriverService $driver;
    
    public function __construct(
        WatchdogService $watchdog,
        MasterBrainService $masterBrain,
        TaskPoolService $taskPool,
        CursorDriverService $driver
    ) {
        $this->watchdog = $watchdog;
        $this->masterBrain = $masterBrain;
        $this->taskPool = $taskPool;
        $this->driver = $driver;
    }
    
    public function execute(array $args = [], array $data = []): void
    {
        // 解析选项
        $verbose = isset($args['v']) || isset($args['verbose']);
        $daemon = isset($args['d']) || isset($args['daemon']);
        $autoTrigger = !isset($args['no-auto-trigger']);
        $runTests = isset($args['test']) || isset($args['t']);
        $maxParallel = (int) ($args['parallel'] ?? $args['p'] ?? 3);
        $model = $args['model'] ?? $args['m'] ?? 'deepseek';
        $interval = (int) ($args['interval'] ?? $args['i'] ?? 2);
        
        // 配置服务
        $this->masterBrain->setModel($model)->setVerbose($verbose);
        $this->driver->setMaxParallelAgents($maxParallel)->setAutoTrigger($autoTrigger)->setVerbose($verbose);
        $this->watchdog->setCheckInterval($interval)->setRunTests($runTests)->setVerbose($verbose);
        
        // 显示启动信息
        $this->showBanner();
        
        $this->printer->printing('📋 配置信息:');
        $this->printer->printing("   - AI 模型: {$model}");
        $this->printer->printing("   - 最大并行: {$maxParallel}");
        $this->printer->printing("   - 检查间隔: {$interval}s");
        $this->printer->printing("   - 自动触发: " . ($autoTrigger ? '是' : '否'));
        $this->printer->printing("   - 运行测试: " . ($runTests ? '是' : '否'));
        $this->printer->printing('');
        
        if ($daemon) {
            $this->startDaemon($args);
            return;
        }
        
        // 前台模式
        $this->printer->note('按 Ctrl+C 停止');
        $this->printer->printing('');
        
        $this->watchdog->start();
    }
    
    /**
     * 显示启动横幅
     */
    private function showBanner(): void
    {
        $banner = <<<'BANNER'
╔══════════════════════════════════════════════════════════════════╗
║                                                                  ║
║   🤖 PHP Agent Orchestrator v3.0                                 ║
║   ─────────────────────────────────────                          ║
║   分布式任务总线 | 多智能体并行 | 自我监督                        ║
║                                                                  ║
║   组件:                                                          ║
║   ├─ 🧠 Master Brain  (任务拆解)                                 ║
║   ├─ 📋 Task Pool     (任务看板)                                 ║
║   ├─ 🚀 Cursor Driver (实例驱动)                                 ║
║   └─ 🐕 Watchdog      (自动审计)                                 ║
║                                                                  ║
╚══════════════════════════════════════════════════════════════════╝

BANNER;
        
        $this->printer->success($banner);
    }
    
    /**
     * 守护进程模式
     */
    private function startDaemon(array $args): void
    {
        $this->printer->note('守护进程模式暂未实现，请使用前台模式');
    }
    
    public function tip(): string
    {
        return __('启动 PHP Agent Orchestrator（分布式任务总线）');
    }
    
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'cursor:orchestrator:run',
            '启动 PHP Agent Orchestrator，实现分布式任务编排、多智能体并行、自我监督',
            [
                '-v, --verbose' => '详细输出模式',
                '-d, --daemon' => '守护进程模式',
                '-p, --parallel <n>' => '最大并行 Agent 数（默认 3）',
                '-m, --model <name>' => 'AI 模型（默认 deepseek）',
                '-i, --interval <s>' => '检查间隔秒数（默认 2）',
                '-t, --test' => '启用自动测试',
                '--no-auto-trigger' => '禁用自动触发 Cursor',
            ],
            [],
            [
                '启动编排器' => 'php bin/w cursor:orchestrator:run',
                '详细模式' => 'php bin/w cursor:orchestrator:run -v',
                '5 个并行' => 'php bin/w cursor:orchestrator:run -p 5',
                '使用 Claude' => 'php bin/w cursor:orchestrator:run -m claude',
            ]
        );
    }
}
