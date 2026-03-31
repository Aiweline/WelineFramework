<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Console\Cursor\Supervisor;

use Agent\CursorSupervisor\Service\CursorSupervisorService;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;

/**
 * Watchdog 子进程命令
 * 
 * 作为后台子进程运行，负责文件监控和任务同步
 * 由 cursor:supervisor:start 交互模式自动启动
 */
class Watchdog extends CommandAbstract
{
    private CursorSupervisorService $supervisorService;
    
    public function __construct(CursorSupervisorService $supervisorService)
    {
        $this->supervisorService = $supervisorService;
    }
    
    public function execute(array $args = [], array $data = [])
    {
        // 检查是否显示帮助
        if (isset($args['h']) || isset($args['help']) || isset($data['h']) || isset($data['help'])) {
            $this->printer->printing($this->help());
            return;
        }
        
        // 获取监控路径
        $watchPaths = [];
        $pathArg = $args['path'] ?? $args['p'] ?? $data['path'] ?? $data['p'] ?? null;
        if ($pathArg) {
            $paths = is_array($pathArg) ? $pathArg : explode(',', $pathArg);
            foreach ($paths as $path) {
                $fullPath = $this->resolvePath(trim($path));
                if ($fullPath) {
                    $watchPaths[] = $fullPath;
                }
            }
        }
        
        // 默认监控 app/code
        if (empty($watchPaths)) {
            $defaultPath = BP . 'app' . DS . 'code';
            if (is_dir($defaultPath)) {
                $watchPaths[] = $defaultPath;
            }
        }
        
        if (empty($watchPaths)) {
            $this->log('❌ 无有效监控路径，退出');
            return;
        }
        
        // 获取选项
        $verbose = isset($args['v']) || isset($args['verbose']) || isset($data['v']) || isset($data['verbose']);
        $interval = (int) ($args['interval'] ?? $args['i'] ?? $data['interval'] ?? $data['i'] ?? 500);
        $docSync = self::resolveDocSyncOption($args, $data);
        $docInterval = (int) ($args['doc-interval'] ?? $data['doc-interval'] ?? 5);
        $autoTrigger = self::resolveAutoTriggerOption($args, $data);
        
        $this->log('🐕 Watchdog 子进程启动');
        $this->log('📂 监控路径: ' . implode(', ', $watchPaths));
        
        // 配置并启动监控服务
        $this->supervisorService
            ->setWatchPaths($watchPaths)
            ->setCheckInterval($interval)
            ->setVerbose($verbose)
            ->setEnableDocSync($docSync)
            ->setDocCheckInterval($docInterval)
            ->setAutoTrigger($autoTrigger)
            ->start();
    }
    
    private function resolvePath(string $path): ?string
    {
        if (is_file($path) || is_dir($path)) {
            return realpath($path);
        }
        
        $fullPath = BP . ltrim(str_replace(['/', '\\'], DS, $path), DS);
        if (is_file($fullPath) || is_dir($fullPath)) {
            return realpath($fullPath);
        }
        
        return null;
    }

    /**
     * 解析文档同步开关，兼容 --doc-sync 与 --no-doc-sync
     */
    public static function resolveDocSyncOption(array $args = [], array $data = []): bool
    {
        if (isset($args['doc-sync']) || isset($data['doc-sync'])) {
            return true;
        }

        if (isset($args['no-doc-sync']) || isset($data['no-doc-sync'])) {
            return false;
        }

        return true;
    }

    /**
     * 解析自动触发开关，兼容 --auto-trigger 与 --no-auto-trigger
     */
    public static function resolveAutoTriggerOption(array $args = [], array $data = []): bool
    {
        if (isset($args['auto-trigger']) || isset($data['auto-trigger'])) {
            return true;
        }

        if (isset($args['no-auto-trigger']) || isset($data['no-auto-trigger'])) {
            return false;
        }

        return true;
    }
    
    private function log(string $message): void
    {
        $time = date('H:i:s');
        echo "[{$time}] {$message}\n";
    }
    
    public function tip(): string
    {
        return __('Watchdog 子进程（由交互模式自动启动）');
    }
    
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'cursor:supervisor:watchdog',
            'Watchdog 子进程命令。通常由 cursor:supervisor:start 交互模式自动启动，不需要手动调用。',
            [
                '-p, --path <paths>' => '监控路径',
                '-i, --interval <ms>' => '检查间隔',
                '-v, --verbose' => '详细输出',
                '--no-doc-sync' => '禁用文档同步',
                '--doc-interval <s>' => '文档检查间隔',
                '--no-auto-trigger' => '禁用自动触发',
            ]
        );
    }
}
