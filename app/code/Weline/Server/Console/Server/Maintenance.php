<?php
declare(strict_types=1);

/**
 * Weline Server - 维护模式命令
 * 
 * 管理 WLS 的维护模式：启用/禁用维护 Worker，执行滚动重启。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Console\Server;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\ServerInstanceManager;

/**
 * server:maintenance - 维护模式管理
 * 
 * 子命令：
 * - enable  - 启用维护模式（启动维护 Worker）
 * - disable - 禁用维护模式（停止维护 Worker）
 * - rolling - 执行滚动重启（自动启用/禁用维护模式）
 * - status  - 查看维护模式状态
 */
class Maintenance extends CommandAbstract
{
    /** 等待模式最大超时时间（秒） */
    private const WAIT_MAX_TIMEOUT = 180;
    
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $instanceName = $this->parseInstanceName($args);
        
        // 解析子命令
        $subCommand = $this->parseSubCommand($args);
        
        if ($subCommand === '') {
            $this->showUsage();
            return;
        }
        
        /** @var ServerInstanceManager $manager */
        $manager = ObjectManager::getInstance(ServerInstanceManager::class);
        
        // 检查服务器是否运行
        $stats = $manager->getRunningStats();
        if (($stats['instances'] ?? 0) === 0) {
            $this->printer->warning(__('未检测到运行中的 WLS Master'));
            $this->printer->note(__('请先启动服务器：php bin/w server:start'));
            return;
        }
        
        switch ($subCommand) {
            case 'enable':
            case 'on':
                $this->executeEnable($instanceName);
                break;
                
            case 'disable':
            case 'off':
                $this->executeDisable($instanceName);
                break;
                
            case 'rolling':
            case 'restart':
                $this->executeRollingRestart($instanceName);
                break;
                
            case 'status':
                $this->executeStatus($instanceName);
                break;
                
            default:
                $this->printer->error(__('未知子命令：%{1}', [$subCommand]));
                $this->showUsage();
                break;
        }
        
        echo "\n";
    }
    
    /**
     * 启用维护模式
     */
    protected function executeEnable(string $instanceName): void
    {
        $this->printer->note(__('启用维护模式...'));
        
        $result = $this->sendMaintenanceCommand($instanceName, ControlMessage::ACTION_MAINTENANCE_ENABLE);
        
        if ($result === null) {
            $this->printer->warning(__('发送命令失败'));
            return;
        }
        
        if ($result['success'] ?? false) {
            $workerCount = $result['data']['maintenance_workers'] ?? 0;
            $this->printer->success(__('✓ 维护模式已启用'));
            if ($workerCount > 0) {
                $this->printer->note(__('  维护 Worker 数量：%{1}', [$workerCount]));
            }
        } else {
            $this->printer->error(__('✗ 启用失败：%{1}', [$result['message'] ?? '未知错误']));
        }
    }
    
    /**
     * 禁用维护模式
     */
    protected function executeDisable(string $instanceName): void
    {
        $this->printer->note(__('禁用维护模式...'));
        
        $result = $this->sendMaintenanceCommand($instanceName, ControlMessage::ACTION_MAINTENANCE_DISABLE);
        
        if ($result === null) {
            $this->printer->warning(__('发送命令失败'));
            return;
        }
        
        if ($result['success'] ?? false) {
            $this->printer->success(__('✓ 维护模式已禁用'));
        } else {
            $this->printer->error(__('✗ 禁用失败：%{1}', [$result['message'] ?? '未知错误']));
        }
    }
    
    /**
     * 执行滚动重启
     */
    protected function executeRollingRestart(string $instanceName): void
    {
        $this->printer->note(__('开始滚动重启...'));
        $this->printer->note(__('维护 Worker 将自动启用以接管流量'));
        
        $info = MasterProcess::getMasterInfo($instanceName);
        $controlPort = (int)($info['control_port'] ?? 0);
        
        if ($controlPort <= 0) {
            $this->printer->warning(__('无法获取控制端口，请检查 Master 是否运行'));
            return;
        }
        
        // 建立 IPC 连接
        $conn = @\stream_socket_client("tcp://127.0.0.1:{$controlPort}", $errno, $errstr, 5);
        if (!$conn) {
            $this->printer->warning(__('无法建立 IPC 连接: %{1}', [$errstr]));
            return;
        }
        
        \stream_set_timeout($conn, self::WAIT_MAX_TIMEOUT);
        \stream_set_blocking($conn, false);
        
        // 发送滚动重启命令
        $command = ControlMessage::command(ControlMessage::ACTION_ROLLING_RESTART);
        $written = @\fwrite($conn, $command);
        
        if ($written === false || $written === 0) {
            @\fclose($conn);
            $this->printer->warning(__('发送命令失败'));
            return;
        }
        
        echo "\n";
        $this->printer->success(__('✓ 滚动重启命令已发送'));
        $this->printer->note(__('等待 Orchestrator 完成滚动重启...'));
        
        // 等待完成
        $this->waitForCompletion($conn);
    }
    
    /**
     * 查看维护模式状态
     */
    protected function executeStatus(string $instanceName): void
    {
        $result = $this->sendMaintenanceCommand($instanceName, ControlMessage::ACTION_STATUS);
        
        if ($result === null) {
            $this->printer->warning(__('获取状态失败'));
            return;
        }
        
        $data = $result['data'] ?? [];
        $maintenanceMode = $data['maintenance_mode'] ?? false;
        $rollingRestart = $data['rolling_restart_in_progress'] ?? false;
        $maintenanceWorkers = 0;
        
        // 从 services 快照中统计维护 Worker（结构：role => ['instances' => [...]]）
        $services = $data['services'] ?? [];
        $maintenanceGroup = $services['maintenance']['instances'] ?? [];
        if (\is_array($maintenanceGroup)) {
            foreach ($maintenanceGroup as $service) {
                if (($service['state'] ?? '') === 'ready') {
                    $maintenanceWorkers++;
                }
            }
        }
        
        echo "\n";
        $this->printer->note(__('维护模式状态'));
        echo "  ────────────────────────────────────\n";
        echo __('  维护模式：%{1}', [$maintenanceMode ? '✓ 已启用' : '✗ 未启用']) . "\n";
        echo __('  滚动重启：%{1}', [$rollingRestart ? '● 进行中' : '○ 无']) . "\n";
        echo __('  维护 Worker：%{1}', [$maintenanceWorkers]) . "\n";
        echo "  ────────────────────────────────────\n";
    }
    
    /**
     * 发送维护模式命令
     */
    protected function sendMaintenanceCommand(string $instanceName, string $action): ?array
    {
        $info = MasterProcess::getMasterInfo($instanceName);
        $controlPort = (int)($info['control_port'] ?? 0);
        
        if ($controlPort <= 0) {
            $this->printer->warning(__('无法获取控制端口，请检查 Master 是否运行'));
            return null;
        }
        
        // 建立 IPC 连接
        $conn = @\stream_socket_client("tcp://127.0.0.1:{$controlPort}", $errno, $errstr, 5);
        if (!$conn) {
            $this->printer->warning(__('无法建立 IPC 连接: %{1}', [$errstr]));
            return null;
        }
        
        \stream_set_timeout($conn, 30);
        \stream_set_blocking($conn, false);
        
        // 发送命令
        $command = ControlMessage::command($action);
        $written = @\fwrite($conn, $command);
        
        if ($written === false || $written === 0) {
            @\fclose($conn);
            return null;
        }
        
        // 等待响应
        $buffer = '';
        $deadline = \microtime(true) + 10;
        
        while (\microtime(true) < $deadline) {
            $data = @\fread($conn, 4096);
            
            if ($data === false) {
                @\fclose($conn);
                return null;
            }
            
            if ($data !== '') {
                $buffer .= $data;
                $messages = ControlMessage::extractMessages($buffer);
                
                foreach ($messages as $msg) {
                    if (($msg['type'] ?? '') === ControlMessage::TYPE_COMMAND_RESULT) {
                        @\fclose($conn);
                        return $msg;
                    }
                }
            }
            
            SchedulerSystem::usleep(50000);
        }
        
        @\fclose($conn);
        return null;
    }
    
    /**
     * 等待滚动重启完成
     */
    protected function waitForCompletion($conn): void
    {
        $startTime = \microtime(true);
        $deadline = $startTime + self::WAIT_MAX_TIMEOUT;
        $buffer = '';
        $lastProgress = '';
        
        while (\microtime(true) < $deadline) {
            $data = @\fread($conn, 4096);
            
            if ($data === false) {
                @\fclose($conn);
                echo "\n";
                $this->printer->warning(__('连接异常断开'));
                return;
            }
            
            if ($data !== '') {
                $buffer .= $data;
                $messages = ControlMessage::extractMessages($buffer);
                
                foreach ($messages as $msg) {
                    $type = $msg['type'] ?? '';
                    
                    switch ($type) {
                        case ControlMessage::TYPE_RELOAD_COMPLETED:
                            $this->handleCompleted($msg, $conn);
                            return;
                            
                        case ControlMessage::TYPE_RELOAD_FAILED:
                            $this->handleFailed($msg, $conn);
                            return;
                            
                        case ControlMessage::TYPE_RELOAD_PROGRESS:
                            $lastProgress = $this->handleProgress($msg, $lastProgress);
                            break;
                            
                        case ControlMessage::TYPE_COMMAND_RESULT:
                            if (!($msg['success'] ?? true)) {
                                echo "\n";
                                $this->printer->error(__('✗ 滚动重启失败：%{1}', [$msg['message'] ?? '未知错误']));
                                @\fclose($conn);
                                return;
                            }
                            break;
                    }
                }
            }
            
            SchedulerSystem::usleep(50000);
        }
        
        @\fclose($conn);
        echo "\n";
        $this->printer->warning(__('等待超时（%{1}s），重启可能仍在进行中', [self::WAIT_MAX_TIMEOUT]));
        $this->printer->note(__('可用 server:maintenance status 查看当前状态'));
    }
    
    /**
     * 处理完成事件
     */
    protected function handleCompleted(array $msg, $conn): void
    {
        $progress = $msg['progress'] ?? 0;
        $total = $msg['total'] ?? 0;
        
        echo "\n";
        $this->printer->success(__('✓ 滚动重启完成（%{1}/%{2} Worker）', [$progress, $total]));
        $this->printer->note(__('维护模式已自动禁用'));
        @\fclose($conn);
    }
    
    /**
     * 处理失败事件
     */
    protected function handleFailed(array $msg, $conn): void
    {
        $message = $msg['message'] ?? __('未知错误');
        
        echo "\n";
        $this->printer->error(__('✗ 滚动重启失败：%{1}', [$message]));
        @\fclose($conn);
    }
    
    /**
     * 处理进度事件
     */
    protected function handleProgress(array $msg, string $lastProgress): string
    {
        $message = $msg['message'] ?? '';
        $progress = $msg['progress'] ?? 0;
        $total = $msg['total'] ?? 0;
        
        $progressText = $total > 0 
            ? "  [{$progress}/{$total}] {$message}"
            : "  {$message}";
        
        if ($progressText !== $lastProgress) {
            $clearLen = \max(\strlen($lastProgress), \strlen($progressText)) + 5;
            echo "\r" . \str_repeat(' ', $clearLen) . "\r";
            echo $progressText;
        }
        
        return $progressText;
    }
    
    /**
     * 解析子命令
     */
    protected function parseSubCommand(array $args): string
    {
        // 从位置参数中查找子命令
        foreach ($args as $key => $val) {
            if (\is_int($key) && \is_string($val) && !str_starts_with($val, '-')) {
                $lower = \strtolower($val);
                if (\in_array($lower, ['enable', 'on', 'disable', 'off', 'rolling', 'restart', 'status'], true)) {
                    return $lower;
                }
            }
        }
        
        // 从命名参数中查找
        if (isset($args['enable']) || isset($args['on'])) {
            return 'enable';
        }
        if (isset($args['disable']) || isset($args['off'])) {
            return 'disable';
        }
        if (isset($args['rolling']) || isset($args['restart'])) {
            return 'rolling';
        }
        if (isset($args['status'])) {
            return 'status';
        }
        
        return '';
    }
    
    /**
     * 解析实例名称
     */
    protected function parseInstanceName(array $args): string
    {
        $skipCommands = ['enable', 'on', 'disable', 'off', 'rolling', 'restart', 'status'];
        $positionalArgs = [];
        foreach ($args as $key => $val) {
            if (\is_int($key) && \is_string($val) && !str_starts_with($val, '-')) {
                $positionalArgs[] = $val;
            }
        }
        \array_shift($positionalArgs);  // 移除命令名（如 server:maintenance）
        foreach ($positionalArgs as $val) {
            $lower = \strtolower($val);
            if (!\in_array($lower, $skipCommands, true)) {
                return $val;
            }
        }
        return $args['instance'] ?? $args['name'] ?? 'default';
    }
    
    /**
     * 显示用法
     */
    protected function showUsage(): void
    {
        echo "\n";
        $this->printer->note(__('用法：server:maintenance <子命令> [实例名]'));
        echo "\n";
        $this->printer->note(__('子命令：'));
        echo "  enable, on     - " . __('启用维护模式（启动维护 Worker）') . "\n";
        echo "  disable, off   - " . __('禁用维护模式（停止维护 Worker）') . "\n";
        echo "  rolling        - " . __('执行滚动重启（自动管理维护模式）') . "\n";
        echo "  status         - " . __('查看维护模式状态') . "\n";
        echo "\n";
        $this->printer->note(__('示例：'));
        echo "  php bin/w server:maintenance enable\n";
        echo "  php bin/w server:maintenance rolling\n";
        echo "  php bin/w server:maintenance status\n";
    }
    
    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('WLS 维护模式管理（滚动重启、维护 Worker）');
    }
    
    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:maintenance <子命令> [实例名]',
            __('管理 WLS 的维护模式：启用/禁用维护 Worker，执行滚动重启。'),
            [
                'enable, on' => __('启用维护模式：启动维护 Worker，准备接管流量'),
                'disable, off' => __('禁用维护模式：停止维护 Worker，恢复正常运行'),
                'rolling' => __('执行滚动重启：逐个重启 Worker，期间由维护 Worker 接管流量'),
                'status' => __('查看维护模式状态'),
                '[instance]' => __('实例名称（默认：default）'),
            ],
            [
                __('滚动重启') => __('逐个重启 Worker 进程，期间由维护 Worker 接管流量，实现零停机更新'),
                __('维护 Worker') => __('临时启动的 Worker 进程，用于在滚动重启期间处理请求'),
                __('自动管理') => __('rolling 命令会自动启用/禁用维护模式，无需手动操作'),
            ],
            [
                __('滚动重启（推荐）') => 'php bin/w server:maintenance rolling',
                __('查看状态') => 'php bin/w server:maintenance status',
                __('手动启用维护模式') => 'php bin/w server:maintenance enable',
                __('手动禁用维护模式') => 'php bin/w server:maintenance disable',
            ]
        );
    }
}
