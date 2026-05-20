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
use Weline\Framework\Output\PrintInterface as OutputPrintInterface;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\CliServerService;
use Weline\Server\Service\Control\IpcControlGateway;
use Weline\Server\Service\Contract\ServerInstanceInfo;
use Weline\Server\Service\Contract\ServiceInfo;
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
            $allInfo = $manager->getAllInstanceInfo(false);
            $processInfoMap = $this->buildProcessInfoMap($allInfo);
            
            foreach ($allInfo as $name => $info) {
                $isRunning = $this->isInstanceRunning($info, $processInfoMap);
                
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
     * @param array<string, ServerInstanceInfo> $instances
     * @return array<int, array{pid: int, exists: bool, name: string, command: string, memory: string, cpu: string, start_time: string}>
     */
    protected function buildProcessInfoMap(array $instances): array
    {
        return [];
    }

    /**
     * @param array<int, array{pid: int, exists: bool, name: string, command: string, memory: string, cpu: string, start_time: string}> $processInfoMap
     */
    protected function isInstanceRunning(ServerInstanceInfo $info, array $processInfoMap): bool
    {
        if ($info->controlPort <= 0) {
            return false;
        }

        $status = (new IpcControlGateway())->getStatus($info->name, 1.5);
        return $status['success'] && (bool)($status['data']['running'] ?? false);
    }

    protected function isSharedDependencyService(ServiceInfo $service): bool
    {
        return $service->role === ControlMessage::ROLE_SESSION_SERVER
            || $service->role === ControlMessage::ROLE_MEMORY_SERVER;
    }

    protected function isSharedExternalService(ServiceInfo $service): bool
    {
        return (bool) ($service->metadata['shared_external'] ?? false);
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
     * 显示详细列表
     */
    protected function showSimpleList(array $instances, bool $runningOnly): void
    {
        $boxInnerWidth = 90;
        $typeWidth = 5;
        $statusWidth = \max(
            $this->displayWidth((string) __('运行中')),
            $this->displayWidth((string) __('已停止'))
        );
        $portWidth = 5;
        $pidWidth = 7;
        $uptimeWidth = 8;

        $this->printer->note(__(''));
        $this->printer->note('╭' . \str_repeat('─', $boxInnerWidth) . '╮');
        $this->printer->note($this->renderBoxContent((string) __('服务器实例列表'), $boxInnerWidth, STR_PAD_BOTH));
        $this->printer->note('├' . \str_repeat('─', $boxInnerWidth) . '┤');

        if (empty($instances)) {
            $emptyText = $runningOnly ? __('没有找到运行中的服务器实例') : __('没有找到任何服务器实例');
            $this->printer->note($this->renderBoxContent((string) $emptyText, $boxInnerWidth));
            $this->printer->note($this->renderBoxContent((string) __('使用 php bin/w server:start [name] 启动 Weline Server'), $boxInnerWidth));
            $this->printer->note($this->renderBoxContent((string) __('使用 php bin/w server:start --cli 启动 CLI 服务器'), $boxInnerWidth));
            $this->printer->note('╰' . \str_repeat('─', $boxInnerWidth) . '╯');
            return;
        }

        $sampleType = $this->padDisplayWidth('[WLS]', $typeWidth);
        $sampleStatus = $this->padDisplayWidth((string) __('运行中'), $statusWidth);
        $samplePort = $this->padDisplayWidth('9502', $portWidth);
        $samplePid = $this->padDisplayWidth('1234567', $pidWidth);
        $sampleUptime = $this->padDisplayWidth('123d 12h', $uptimeWidth, STR_PAD_LEFT);
        $rowFixedWidth = $this->displayWidth("● {$sampleType}  {$sampleStatus}  Port: {$samplePort}  PID: {$samplePid}  {$sampleUptime}") + 2;
        $nameWidth = 12;
        foreach ($instances as $name => $_status) {
            $nameWidth = \max($nameWidth, $this->displayWidth((string) $name));
        }
        $nameWidth = \min($nameWidth, \max(12, $boxInnerWidth - $rowFixedWidth));

        $welineRunning = 0;
        $welineStopped = 0;
        $cliRunning = 0;

        foreach ($instances as $name => $status) {
            $isRunning = ($status['status'] ?? '') === 'running' || ($status['is_running'] ?? false);
            $type = $status['type'] ?? 'weline';

            if ($isRunning) {
                if ($type === 'cli') {
                    $cliRunning++;
                } else {
                    $welineRunning++;
                }
            } elseif ($type !== 'cli') {
                $welineStopped++;
            }

            $typeLabel = $type === 'cli' ? '[CLI]' : '[WLS]';
            $statusIcon = $isRunning ? '●' : '○';
            $statusIconColor = $isRunning ? OutputPrintInterface::SUCCESS : OutputPrintInterface::NOTE;
            $statusText = $isRunning ? (string) __('运行中') : (string) __('已停止');
            $statusColor = $isRunning ? OutputPrintInterface::SUCCESS : OutputPrintInterface::NOTE;
            $statusColumn = $this->colorizeSegment($this->padDisplayWidth($statusText, $statusWidth), $statusColor);
            $typeColumn = $this->padDisplayWidth($typeLabel, $typeWidth);
            $nameColumn = $this->padDisplayWidth((string) $name, $nameWidth);
            $portColumn = $this->padDisplayWidth((string) ($status['port'] ?? '-'), $portWidth);
            $pidColumn = $this->padDisplayWidth((string) ($status['pid'] ?? '-'), $pidWidth);
            $uptimeColumn = $this->padDisplayWidth((string) ($status['running_time'] ?? '-'), $uptimeWidth, STR_PAD_LEFT);
            $plainStatusColumn = $this->padDisplayWidth($statusText, $statusWidth);
            $rowPrefix = " {$typeColumn} {$nameColumn}  ";
            $rowSuffix = "  Port: {$portColumn}  PID: {$pidColumn}  {$uptimeColumn}";
            $plainRowContent = "{$statusIcon}{$rowPrefix}{$plainStatusColumn}{$rowSuffix}";
            $rowPadding = \str_repeat(' ', \max(0, $boxInnerWidth - $this->displayWidth($plainRowContent)));

            echo $this->colorizeSegment('│', OutputPrintInterface::NOTE)
                . $this->colorizeSegment($statusIcon, $statusIconColor)
                . $this->colorizeSegment($rowPrefix, OutputPrintInterface::NOTE)
                . $statusColumn
                . $this->colorizeSegment($rowSuffix . $rowPadding . '│', OutputPrintInterface::NOTE)
                . PHP_EOL;
        }

        $this->printer->note('├' . \str_repeat('─', $boxInnerWidth) . '┤');
        $summaryLine = (string) __('总计: %{total}  |  Weline: ●%{wrun} ○%{wstop}  |  CLI: ●%{clirun}', [
            'total' => \count($instances),
            'wrun' => $welineRunning,
            'wstop' => $welineStopped,
            'clirun' => $cliRunning,
        ]);
        $this->printer->note($this->renderBoxContent($summaryLine, $boxInnerWidth));
        $this->printer->note('╰' . \str_repeat('─', $boxInnerWidth) . '╯');
        $this->printer->note(__(''));
        $this->printer->note(__('[WLS] = Weline Server (高性能)  |  [CLI] = PHP 内置服务器(开发)'));
        $this->printer->note(__(''));
    }

    protected function renderBoxContent(string $content, int $width, int $padType = STR_PAD_RIGHT): string
    {
        return '│' . $this->padDisplayWidth($content, $width, $padType) . '│';
    }

    protected function padDisplayWidth(string $text, int $width, int $padType = STR_PAD_RIGHT): string
    {
        if ($width <= 0) {
            return '';
        }

        $plainText = $text;
        $displayWidth = $this->displayWidth($plainText);
        if ($displayWidth > $width) {
            if (\function_exists('mb_strimwidth')) {
                $plainText = (string) \mb_strimwidth($plainText, 0, $width, '', 'UTF-8');
            } else {
                $plainText = \substr($plainText, 0, $width);
            }
            $displayWidth = $this->displayWidth($plainText);
        }

        $padding = $width - $displayWidth;
        if ($padding <= 0) {
            return $plainText;
        }

        return match ($padType) {
            STR_PAD_LEFT => \str_repeat(' ', $padding) . $plainText,
            STR_PAD_BOTH => \str_repeat(' ', intdiv($padding, 2)) . $plainText . \str_repeat(' ', $padding - intdiv($padding, 2)),
            default => $plainText . \str_repeat(' ', $padding),
        };
    }

    protected function displayWidth(string $text): int
    {
        $plainText = \preg_replace('/\e\[[\d;]*m/', '', $text) ?? $text;

        if (\function_exists('mb_strwidth')) {
            return \mb_strwidth($plainText, 'UTF-8');
        }

        return \strlen($plainText);
    }

    protected function colorizeSegment(string $text, string $color): string
    {
        if (\method_exists($this->printer, 'colorize')) {
            return $this->printer->colorize($text, $color);
        }

        return $text;
    }

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
