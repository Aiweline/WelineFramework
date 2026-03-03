<?php
declare(strict_types=1);

/**
 * Weline Server - 实例列表命令
 * 
 * 显示所有服务器实例及其状态（包括 CLI 和 Weline Server）
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Console\Server;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\CliServerService;
use Weline\Server\Service\ServerInstanceManager;

/**
 * server:listing - 列出所有服务器实例
 */
class Listing extends CommandAbstract
{
    private ServerInstanceManager $instanceManager;
    private CliServerService $cliServerService;
    
    public function __construct(
        ServerInstanceManager $instanceManager,
        CliServerService $cliServerService
    ) {
        $this->instanceManager = $instanceManager;
        $this->cliServerService = $cliServerService;
    }
    
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $runningOnly = isset($args['r']) || isset($args['running']);
        $detailed = isset($args['d']) || isset($args['detailed']);
        $json = isset($args['json']);
        $typeFilter = $args['type'] ?? $args['t'] ?? null; // weline, cli, or null for all
        
        // 收集所有实例
        $allInstances = $this->collectAllInstances($typeFilter, $runningOnly);
        
        if ($json) {
            $this->outputJson($allInstances);
            return;
        }
        
        if ($detailed) {
            $this->showDetailedList($allInstances);
        } else {
            $this->showSimpleList($allInstances, $runningOnly);
        }
    }
    
    /**
     * 收集所有服务器实例（CLI + Weline Server）
     *
     * 使用 ServerInstanceManager 获取 Weline Server 的统一实例信息
     */
    protected function collectAllInstances(?string $typeFilter, bool $runningOnly): array
    {
        $allInstances = [];
        
        // 1. 获取 CLI 服务器状态
        if ($typeFilter === null || $typeFilter === 'cli') {
            $cliStatus = $this->cliServerService->getCliServerStatus();
            if ($cliStatus) {
                if (!$runningOnly || $cliStatus['is_running']) {
                    $allInstances['cli-server'] = $cliStatus;
                }
            }
        }
        
        // 2. 获取所有 Weline Server 实例（通过 ServerInstanceManager）
        if ($typeFilter === null || $typeFilter === 'weline') {
            $manager = $this->getInstanceManager();
            $allInfo = $manager->getAllInstanceInfo();
            
            foreach ($allInfo as $name => $info) {
                $isRunning = $info->isMasterRunning() || $info->getServiceStats()['running'] > 0;
                
                if ($runningOnly && !$isRunning) {
                    continue;
                }
                
                $status = [
                    'name' => $info->name,
                    'type' => 'weline',
                    'type_name' => __('Weline Server'),
                    'status' => $isRunning ? 'running' : 'stopped',
                    'is_running' => $isRunning,
                    'pid' => $info->masterPid > 0 ? $info->masterPid : null,
                    'host' => $info->host,
                    'port' => $info->port,
                    'count' => $info->workerCount,
                    'daemon' => true,
                    'started_at' => $info->startedAt,
                    'running_time' => $this->formatRunningTime($info->startedTimestamp),
                ];
                
                $allInstances[$name] = $status;
            }
        }
        
        return $allInstances;
    }
    
    /**
     * 格式化运行时长
     */
    protected function formatRunningTime(int $startedTimestamp): string
    {
        if ($startedTimestamp <= 0) {
            return '-';
        }
        
        $seconds = \time() - $startedTimestamp;
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        if ($seconds < 3600) {
            $minutes = (int) ($seconds / 60);
            return "{$minutes}m";
        }
        if ($seconds < 86400) {
            $hours = (int) ($seconds / 3600);
            $minutes = (int) (($seconds % 3600) / 60);
            return "{$hours}h {$minutes}m";
        }
        $days = (int) ($seconds / 86400);
        $hours = (int) (($seconds % 86400) / 3600);
        return "{$days}d {$hours}h";
    }
    
    /**
     * 获取实例管理器
     */
    protected function getInstanceManager(): ServerInstanceManager
    {
        return ObjectManager::getInstance(ServerInstanceManager::class);
    }
    
    /**
     * 显示简洁列表
     */
    protected function showSimpleList(array $instances, bool $runningOnly): void
    {
        $this->printer->note(__(''));
        $this->printer->note(__('┌──────────────────────────────────────────────────────────────────────────────────────┐'));
        $this->printer->note(__('│                           服务器实例列表                                             │'));
        $this->printer->note(__('├──────────────────────────────────────────────────────────────────────────────────────┤'));
        
        if (empty($instances)) {
            $this->printer->note(__('│  没有找到任何服务器实例                                                               │'));
            $this->printer->note(__('│  使用 php bin/w server:start [name] 启动 Weline Server                                │'));
            $this->printer->note(__('│  使用 php bin/w server:start --cli 启动 CLI 服务器                                    │'));
            $this->printer->note(__('└──────────────────────────────────────────────────────────────────────────────────────┘'));
            return;
        }
        
        $welineRunning = 0;
        $welineStopped = 0;
        $cliRunning = 0;
        
        foreach ($instances as $name => $status) {
            $isRunning = ($status['status'] ?? '') === 'running' || ($status['is_running'] ?? false);
            $type = $status['type'] ?? 'weline';
            
            if ($isRunning) {
                $statusIcon = '●';
                $statusText = __('运行中');
                if ($type === 'cli') {
                    $cliRunning++;
                } else {
                    $welineRunning++;
                }
            } else {
                $statusIcon = '○';
                $statusText = __('已停止');
                $welineStopped++;
            }
            
            $typeLabel = $type === 'cli' ? '[CLI]' : '[WLS]';
            
            $line = sprintf(
                '│  %s %s %-12s  %s  Port: %-5s  PID: %-7s  %s',
                $statusIcon,
                $typeLabel,
                $name,
                $statusText,
                $status['port'] ?? '-',
                $status['pid'] ?? '-',
                str_pad($status['running_time'] ?? '-', 12)
            );
            
            $line = str_pad($line, 91) . '│';
            $this->printer->note($line);
        }
        
        $this->printer->note(__('├──────────────────────────────────────────────────────────────────────────────────────┤'));
        
        $total = count($instances);
        $totalRunning = $welineRunning + $cliRunning;
        $summaryLine = '│  ' . __('总计：%{total}  |  Weline: ●%{wrun} ○%{wstop}  |  CLI: ●%{clirun}', [
            'total' => $total,
            'wrun' => $welineRunning,
            'wstop' => $welineStopped,
            'clirun' => $cliRunning,
        ]);
        $summaryLine = \str_pad($summaryLine, 91) . '│';
        $this->printer->note($summaryLine);
        
        $this->printer->note(__('└──────────────────────────────────────────────────────────────────────────────────────┘'));
        $this->printer->note(__(''));
        $this->printer->note(__('[WLS] = Weline Server (高性能)  |  [CLI] = PHP 内置服务器 (开发)'));
        $this->printer->note(__(''));
    }
    
    /**
     * 显示详细列表
     */
    protected function showDetailedList(array $instances): void
    {
        if (empty($instances)) {
            $this->printer->warning(__('没有找到任何服务器实例'));
            return;
        }
        
        $this->printer->note(__(''));
        $this->printer->note(__('═══════════════════════════════════════════════════════════════════════════════'));
        $this->printer->note(__('                         服务器实例详细列表'));
        $this->printer->note(__('═══════════════════════════════════════════════════════════════════════════════'));
        
        $count = 0;
        foreach ($instances as $name => $status) {
            $count++;
            $isRunning = ($status['status'] ?? '') === 'running' || ($status['is_running'] ?? false);
            $type = $status['type'] ?? 'weline';
            $typeName = $status['type_name'] ?? ($type === 'cli' ? __('PHP 内置服务器') : __('Weline Server'));
            
            $this->printer->note(__(''));
            $this->printer->note(__('───────────────────────────────────────────────────────────────────────────────'));
            
            if ($isRunning) {
                $this->printer->success(__('[%{1}] ● 运行中', [$name]));
            } else {
                $this->printer->warning(__('[%{1}] ○ 已停止', [$name]));
            }
            
            $this->printer->note(__(''));
            $this->printer->note(__('  ├─ 服务类型   : %{1}', [$typeName]));
            $this->printer->note(__('  ├─ PID         : %{1}', [$status['pid'] ?? '-']));
            $this->printer->note(__('  ├─ 监听地址   : %{1}:%{2}', [$status['host'] ?? '-', $status['port'] ?? '-']));
            
            if ($type === 'weline') {
                $this->printer->note(__('  ├─ Worker 数  : %{1}', [$status['count'] ?? '-']));
            }
            
            $this->printer->note(__('  ├─ 运行模式   : %{1}', [($status['daemon'] ?? false) ? __('守护进程') : __('前台模式')]));
            $this->printer->note(__('  ├─ 启动者     : %{1}', [$status['started_by'] ?? '-']));
            $this->printer->note(__('  ├─ 启动时间   : %{1}', [$status['started_at'] ?? '-']));
            $this->printer->note(__('  └─ 运行时长   : %{1}', [$status['running_time'] ?? '-']));
        }
        
        $this->printer->note(__(''));
        $this->printer->note(__('═══════════════════════════════════════════════════════════════════════════════'));
        $this->printer->note(__('总计：%{1} 个实例', [$count]));
        $this->printer->note(__(''));
    }
    
    /**
     * 输出 JSON 格式
     */
    protected function outputJson(array $instances): void
    {
        echo json_encode(array_values($instances), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
    
    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('列出所有服务器实例（CLI 和 Weline Server）');
    }
    
    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:listing',
            __('列出所有服务器实例及其状态，包括 Weline Server 和 PHP CLI 服务器'),
            [
                '-r, --running' => __('仅显示运行中的实例'),
                '-d, --detailed' => __('显示详细信息'),
                '-t, --type <type>' => __('按类型过滤：weline 或 cli'),
                '--json' => __('以 JSON 格式输出'),
                '--help' => __('显示帮助信息'),
            ],
            [],
            [
                __('显示所有实例') => 'php bin/w server:listing',
                __('仅 Weline Server') => 'php bin/w server:listing -t weline',
                __('仅 CLI 服务器') => 'php bin/w server:listing -t cli',
                __('仅运行中') => 'php bin/w server:listing -r',
                __('详细信息') => 'php bin/w server:listing -d',
            ]
        );
    }
    
    /**
     * 命令别名
     */
    public function aliases(): array
    {
        return ['server:list', 'server:ls', 'server:ps', 'ser:listing', 'ser:list', 'ser:ls'];
    }
}
