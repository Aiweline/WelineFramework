<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Console\Cursor\Supervisor;

use Agent\CursorSupervisor\Service\CursorSupervisorService;
use Agent\CursorSupervisor\Service\InteractiveShellService;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\System\Process\Processer;

/**
 * 启动 Cursor 智能监督助手
 */
class Start extends CommandAbstract
{
    private CursorSupervisorService $supervisorService;
    private InteractiveShellService $interactiveShell;
    
    public function __construct(
        CursorSupervisorService $supervisorService,
        InteractiveShellService $interactiveShell
    ) {
        $this->supervisorService = $supervisorService;
        $this->interactiveShell = $interactiveShell;
    }
    
    public function execute(array $args = [], array $data = [])
    {
        // 提取位置参数
        $positionalArgs = [];
        foreach ($args as $key => $arg) {
            if (is_int($key) && !str_starts_with((string)$arg, '-')) {
                $positionalArgs[] = $arg;
            }
        }
        array_shift($positionalArgs); // 移除命令名
        
        // 检查是否显示帮助
        if (isset($args['h']) || isset($args['help']) || isset($data['h']) || isset($data['help'])) {
            $this->printer->printing($this->help());
            return;
        }
        
        // 检查是否已在运行
        if (CursorSupervisorService::isRunning()) {
            $pid = CursorSupervisorService::getPid();
            $this->printer->warning("Cursor 监督助手已在运行中 (PID: {$pid})");
            $this->printer->note('如需重启，请先执行: php bin/w cursor:supervisor:stop');
            return;
        }
        
        // 获取监控路径
        $watchPaths = [];
        
        // 从参数获取路径
        if (!empty($positionalArgs)) {
            foreach ($positionalArgs as $path) {
                $fullPath = $this->resolvePath($path);
                if ($fullPath) {
                    $watchPaths[] = $fullPath;
                } else {
                    $this->printer->warning("路径不存在，已跳过: {$path}");
                }
            }
        }
        
        // 从 --path 参数获取
        $pathArg = $args['path'] ?? $args['p'] ?? $data['path'] ?? $data['p'] ?? null;
        if ($pathArg) {
            $paths = is_array($pathArg) ? $pathArg : explode(',', $pathArg);
            foreach ($paths as $path) {
                $fullPath = $this->resolvePath(trim($path));
                if ($fullPath) {
                    $watchPaths[] = $fullPath;
                } else {
                    $this->printer->warning("路径不存在，已跳过: {$path}");
                }
            }
        }
        
        // 默认监控 app/code 目录
        if (empty($watchPaths)) {
            $defaultPath = BP . 'app' . DS . 'code';
            if (is_dir($defaultPath)) {
                $watchPaths[] = $defaultPath;
            } else {
                $this->printer->error('未指定监控路径，且默认路径 app/code 不存在');
                return;
            }
        }
        
        // 去重
        $watchPaths = array_unique($watchPaths);
        
        // 获取选项
        $verbose = isset($args['v']) || isset($args['verbose']) || isset($data['v']) || isset($data['verbose']);
        $daemon = isset($args['d']) || isset($args['daemon']) || isset($data['d']) || isset($data['daemon']);
        $interval = (int) ($args['interval'] ?? $args['i'] ?? $data['interval'] ?? $data['i'] ?? 500);
        $docSync = !isset($args['no-doc-sync']) && !isset($data['no-doc-sync']);
        $docInterval = (int) ($args['doc-interval'] ?? $data['doc-interval'] ?? 5);
        $autoTrigger = !isset($args['no-auto-trigger']) && !isset($data['no-auto-trigger']);
        // 交互模式默认开启，除非显式禁用
        $interactive = !isset($args['no-interactive']) && !isset($data['no-interactive']);
        
        // 守护进程模式
        if ($daemon) {
            $this->startDaemon($watchPaths, $interval, $verbose, $docSync, $docInterval, $autoTrigger);
            return;
        }
        
        // 前台模式（带交互式命令支持）
        $this->startForeground($watchPaths, $interval, $verbose, $docSync, $docInterval, $autoTrigger, $interactive);
    }
    
    /**
     * 前台运行
     */
    private function startForeground(array $watchPaths, int $interval, bool $verbose, bool $docSync, int $docInterval, bool $autoTrigger, bool $interactive = false): void
    {
        $this->printer->success('🚀 Cursor 智能监督助手启动中 (Headless Agent Control)');
        $this->printer->note('按 Ctrl+C 停止');
        $this->printer->printing('');
        $this->printer->printing('📋 功能模式:');
        $this->printer->printing('   - 文档同步: ' . ($docSync ? '✅ 启用' : '❌ 禁用'));
        $this->printer->printing('   - 自动触发: ' . ($autoTrigger ? '✅ 启用 (模拟 Ctrl+K)' : '❌ 禁用'));
        $this->printer->printing('   - 交互模式: ' . ($interactive ? '✅ 启用' : '❌ 禁用'));
        $this->printer->printing('');
        
        if ($interactive) {
            $this->printer->printing('┌────────────────────────────────────────────────────────────┐');
            $this->printer->printing('│  🎮 交互模式已启用（监控自动启动）                          │');
            $this->printer->printing('│  命令: /plan /pro /commit /monitor /test /help /exit      │');
            $this->printer->printing('│  💬 直接输入文字即可与 AI 聊天                              │');
            $this->printer->printing('└────────────────────────────────────────────────────────────┘');
            $this->printer->printing('');
            
            // 启用交互模式
            $this->interactiveShell->setVerbose($verbose);
            $this->startInteractiveMode($watchPaths, $interval, $verbose, $docSync, $docInterval, $autoTrigger);
        } else {
            $this->supervisorService
                ->setWatchPaths($watchPaths)
                ->setCheckInterval($interval)
                ->setVerbose($verbose)
                ->setEnableDocSync($docSync)
                ->setDocCheckInterval($docInterval)
                ->setAutoTrigger($autoTrigger)
                ->start();
        }
    }
    
    /**
     * 交互模式运行（监控默认启动，聊天与监控并行）
     */
    private function startInteractiveMode(array $watchPaths, int $interval, bool $verbose, bool $docSync, int $docInterval, bool $autoTrigger): void
    {
        // 配置交互服务（快速设置，不会阻塞）
        $this->interactiveShell
            ->setVerbose($verbose)
            ->setSupervisorConfig([
                'watchPaths' => $watchPaths,
                'interval' => $interval,
                'docSync' => $docSync,
                'docInterval' => $docInterval,
                'autoTrigger' => $autoTrigger,
            ]);
        
        // 跳过自动启动监控，让用户手动执行 /monitor start
        // 这样可以避免任何可能的阻塞问题
        $this->printer->printing("💡 提示: 使用 /monitor start 启动文件监控");
        $this->printer->printing('');
        
        $running = true;
        
        while ($running) {
            echo "\033[36m> \033[0m";
            $input = @fgets(STDIN);
            
            if ($input === false) {
                usleep(100000);
                continue;
            }
            
            $input = trim($input);
            
            if (empty($input)) {
                continue;
            }
            
            // 处理退出命令
            if (in_array(strtolower($input), ['/exit', '/quit', '/q'])) {
                echo "👋 再见！\n";
                $running = false;
                break;
            }
            
            // 委托给 InteractiveShellService 处理（包括 /monitor 命令）
            $this->interactiveShell->processInput($input);
        }
    }
    
    /**
     * 自动启动文件监控（后台进程，完全不阻塞）
     * 
     * 使用最简单的方式启动后台进程，避免任何可能阻塞的检查
     */
    private function autoStartMonitor(array $watchPaths, int $interval, bool $docSync, bool $autoTrigger): void
    {
        $this->printer->printing("📡 正在启动文件监控...");
        
        try {
            $processName = 'cursor-file-monitor';
            $paths = implode(',', $watchPaths);
            
            // 构建监控命令
            $phpBin = PHP_BINARY;
            $script = BP . 'bin' . DS . 'w';
            $args = "cursor:supervisor:watchdog --path=\"{$paths}\" --interval={$interval}";
            if ($docSync) {
                $args .= ' --doc-sync';
            }
            if ($autoTrigger) {
                $args .= ' --auto-trigger';
            }
            $args .= ' --name=' . $processName;
            
            // Windows: 使用 start /B 启动后台进程
            if (PHP_OS_FAMILY === 'Windows') {
                $cmd = "start /B \"\" \"{$phpBin}\" \"{$script}\" {$args} > NUL 2>&1";
                pclose(popen($cmd, 'r'));
            } else {
                // Linux/macOS: 使用 nohup
                $cmd = "nohup \"{$phpBin}\" \"{$script}\" {$args} > /dev/null 2>&1 &";
                exec($cmd);
            }
            
            $this->printer->printing("   ✅ 后台进程已启动");
            $this->printer->printing("   使用 /monitor status 查看状态");
            $this->printer->printing('');
        } catch (\Throwable $e) {
            $this->printer->warning("⚠️ 监控启动异常: " . $e->getMessage());
            $this->printer->printing("   可手动执行 /monitor start");
            $this->printer->printing('');
        }
    }
    
    /**
     * 守护进程模式
     */
    private function startDaemon(array $watchPaths, int $interval, bool $verbose, bool $docSync, int $docInterval, bool $autoTrigger): void
    {
        $processName = CursorSupervisorService::PROCESS_NAME;
        
        // 构建命令
        $pathsArg = escapeshellarg(implode(',', $watchPaths));
        $cmd = 'php ' . BP . 'bin/w cursor:supervisor:start';
        $cmd .= " --path={$pathsArg}";
        $cmd .= " --interval={$interval}";
        $cmd .= " --doc-interval={$docInterval}";
        if ($verbose) {
            $cmd .= ' --verbose';
        }
        if (!$docSync) {
            $cmd .= ' --no-doc-sync';
        }
        if (!$autoTrigger) {
            $cmd .= ' --no-auto-trigger';
        }
        $cmd .= " --name={$processName}";
        
        // 创建进程
        $pid = Processer::create($cmd, false);
        
        if ($pid > 0) {
            $this->printer->success("🚀 Cursor 智能监督助手已在后台启动 (Headless Agent Control)");
            $this->printer->note("PID: {$pid}");
            $this->printer->note("监控路径: " . implode(', ', $watchPaths));
            $this->printer->note("文档同步: " . ($docSync ? '已启用' : '已禁用'));
            $this->printer->note("自动触发: " . ($autoTrigger ? '已启用' : '已禁用'));
            $this->printer->printing('');
            $this->printer->printing('查看日志: tail -f var/log/cursor-supervisor.log');
            $this->printer->printing('停止服务: php bin/w cursor:supervisor:stop');
        } else {
            $this->printer->error('守护进程启动失败');
        }
    }
    
    /**
     * 解析路径
     */
    private function resolvePath(string $path): ?string
    {
        // 绝对路径
        if (is_file($path) || is_dir($path)) {
            return realpath($path);
        }
        
        // 相对于项目根目录
        $fullPath = BP . ltrim(str_replace(['/', '\\'], DS, $path), DS);
        if (is_file($fullPath) || is_dir($fullPath)) {
            return realpath($fullPath);
        }
        
        return null;
    }
    
    public function tip(): string
    {
        return __('启动 Cursor 智能监督助手（Headless Agent Control），监控 PHP 文件变化并自动触发 Cursor 执行');
    }
    
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'cursor:supervisor:start',
            '启动 Cursor 智能监督助手（Headless Agent Control）。' .
            '实时监控 PHP 文件变化和文档任务，通过 SUPERVISOR_TASK 信号弹和 mission.json 决策包精准控制 Cursor AI 智能体。' .
            '默认启用交互模式并自动启动文件监控，运行时可输入命令（/plan /pro /commit /test）或直接聊天',
            [
                '-p, --path <paths>' => '监控路径（多个路径用逗号分隔）',
                '-i, --interval <ms>' => '检查间隔（毫秒，默认 500）',
                '-v, --verbose' => '详细输出模式（显示逻辑检查结果）',
                '-d, --daemon' => '守护进程模式（后台运行）',
                '--no-interactive' => '禁用交互模式（默认开启）',
                '--no-doc-sync' => '禁用文档任务同步',
                '--doc-interval <s>' => '文档检查间隔（秒，默认 5）',
                '--no-auto-trigger' => '禁用自动触发（不模拟按键）',
                '-h, --help' => '显示帮助信息',
            ],
            [],
            [
                '监控默认路径' => 'php bin/w cursor:supervisor:start',
                '禁用交互模式' => 'php bin/w cursor:supervisor:start --no-interactive',
                '监控指定目录' => 'php bin/w cursor:supervisor:start app/code/Agent',
                '后台运行' => 'php bin/w cursor:supervisor:start -d',
                '详细模式' => 'php bin/w cursor:supervisor:start -v',
                '禁用自动触发' => 'php bin/w cursor:supervisor:start --no-auto-trigger',
                '纯监控模式' => 'php bin/w cursor:supervisor:start --no-doc-sync --no-auto-trigger',
            ]
        );
    }
}
