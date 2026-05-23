<?php
declare(strict_types=1);

/**
 * WLS Worker 扩缩容命令
 *
 * 支持手动扩缩容和自动扩缩容配置：
 * - php bin/w server:scale --workers=N - 手动设置 Worker 数量
 * - php bin/w server:scale --auto - 启用自动扩缩容
 * - php bin/w server:scale --no-auto - 禁用自动扩缩容
 * - php bin/w server:scale --status - 查看扩缩容状态
 *
 * @author Aiweline
 */

namespace Weline\Server\Console\Server;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Output\Cli\Printing;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Control\IpcControlGateway;
use Weline\Server\Service\ServerInstanceManager;

class Scale extends CommandAbstract
{
    /**
     * IPC 超时（秒）
     */
    private const IPC_TIMEOUT = 10;

    public function execute(array $args = [], array $data = [])
    {
        $printer = new Printing();

        // 解析参数
        $workers = $data['workers'] ?? null;
        $auto = isset($data['auto']);
        $noAuto = isset($data['no-auto']);
        $status = isset($data['status']);
        $min = $data['min'] ?? null;
        $max = $data['max'] ?? null;

        // 参数冲突检查
        if ($auto && $noAuto) {
            $printer->error('Cannot use --auto and --no-auto together');
            return;
        }

        if ($workers !== null && ($auto || $noAuto)) {
            $printer->error('Cannot use --workers with --auto or --no-auto');
            return;
        }

        // 获取运行中的实例
        $instanceManager = ServerInstanceManager::getInstance();
        $instances = $instanceManager->getRunningInstances();

        if (empty($instances)) {
            $printer->error('No WLS server is running. Please start the server first with: php bin/w server:start');
            return;
        }

        // 默认使用第一个实例
        $instance = $instances[0];
        $controlPort = $instance['control_port'] ?? 0;
        $controlToken = (string)($instance['control_token'] ?? '');

        if ($controlPort === 0) {
            $printer->error('Failed to get control port from running instance');
            return;
        }

        // 查询状态
        if ($status) {
            $this->showStatus($controlPort, $controlToken, $printer);
            return;
        }

        // 配置自动扩缩容
        if ($auto || $noAuto) {
            $this->configureAutoScaling($controlPort, $auto, $min, $max, $printer);
            return;
        }

        // 手动扩缩容
        if ($workers !== null) {
            $this->scaleWorkers($controlPort, $controlToken, (int)$workers, $printer);
            return;
        }

        // 无参数，显示帮助
        $this->showHelp($printer);
    }

    /**
     * 手动扩缩容
     */
    private function scaleWorkers(int $controlPort, string $controlToken, int $targetWorkers, Printing $printer): void
    {
        if ($targetWorkers < 1) {
            $printer->error('Worker count must be at least 1');
            return;
        }

        $printer->printing('Scaling workers to ' . $targetWorkers . '...');

        try {
            $gateway = new IpcControlGateway('127.0.0.1', $controlPort);
            $message = ControlMessage::command(
                ControlMessage::ACTION_SCALE_WORKERS,
                '',
                ['target_workers' => $targetWorkers],
                $controlToken
            );

            $response = $gateway->sendAndWaitForResponse($message, self::IPC_TIMEOUT);

            if ($response === null) {
                $printer->error('Timeout waiting for response from Master');
                return;
            }

            $success = $response['success'] ?? false;
            $currentWorkers = $response['current_workers'] ?? 0;
            $addedPids = $response['added_pids'] ?? [];
            $removedPids = $response['removed_pids'] ?? [];
            $message = $response['message'] ?? '';

            if ($success) {
                $printer->success($message);
                $printer->printing("Current workers: {$currentWorkers}");

                if (!empty($addedPids)) {
                    $printer->printing('Added workers (PIDs): ' . implode(', ', $addedPids));
                }

                if (!empty($removedPids)) {
                    $printer->printing('Removed workers (PIDs): ' . implode(', ', $removedPids));
                }
            } else {
                $printer->error($message);
            }
        } catch (\Throwable $e) {
            $printer->error('Failed to scale workers: ' . $e->getMessage());
        }
    }

    /**
     * 配置自动扩缩容
     */
    private function configureAutoScaling(
        int $controlPort,
        bool $enable,
        ?string $min,
        ?string $max,
        Printing $printer
    ): void {
        $action = $enable ? 'Enabling' : 'Disabling';
        $printer->printing("{$action} auto-scaling...");

        // 读取当前配置
        $envFile = BP . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
        if (!\is_file($envFile)) {
            $printer->error("Configuration file not found: {$envFile}");
            return;
        }

        $config = include $envFile;
        if (!\is_array($config)) {
            $printer->error("Invalid configuration file format");
            return;
        }

        // 更新配置
        if (!isset($config['wls'])) {
            $config['wls'] = [];
        }
        if (!isset($config['wls']['scaling'])) {
            $config['wls']['scaling'] = [];
        }

        $config['wls']['scaling']['enabled'] = $enable;
        if ($min !== null) {
            $config['wls']['scaling']['min_workers'] = (int)$min;
        }
        if ($max !== null) {
            $config['wls']['scaling']['max_workers'] = (int)$max;
        }

        // 备份原配置
        $backupFile = $envFile . '.backup.' . \date('YmdHis');
        if (!\copy($envFile, $backupFile)) {
            $printer->error("Failed to create backup: {$backupFile}");
            return;
        }

        // 写入新配置
        $configContent = "<?php\nreturn " . \var_export($config, true) . ";\n";
        if (@\file_put_contents($envFile, $configContent, LOCK_EX) === false) {
            $printer->error("Failed to write configuration file");
            // 恢复备份
            @\copy($backupFile, $envFile);
            return;
        }

        $printer->success("Auto-scaling configuration updated successfully");
        $printer->printing("Backup saved to: {$backupFile}");
        $printer->printing('');
        $printer->printing('Configuration:');
        $printer->printing("  Enabled: " . ($enable ? 'true' : 'false'));
        if ($min !== null) {
            $printer->printing("  Min workers: {$min}");
        }
        if ($max !== null) {
            $printer->printing("  Max workers: {$max}");
        }
        $printer->printing('');
        $printer->warning('Note: Configuration changes will take effect after server reload');
        $printer->printing('Run: php bin/w server:reload');
    }

    /**
     * 显示扩缩容状态
     */
    private function showStatus(int $controlPort, string $controlToken, Printing $printer): void
    {
        try {
            $gateway = new IpcControlGateway('127.0.0.1', $controlPort);
            $message = ControlMessage::command(ControlMessage::ACTION_SCALING_STATUS, '', [], $controlToken);

            $response = $gateway->sendAndWaitForResponse($message, self::IPC_TIMEOUT);

            if ($response === null) {
                $printer->error('Timeout waiting for response from Master');
                return;
            }

            $enabled = $response['enabled'] ?? false;
            $currentWorkers = $response['current_workers'] ?? 0;
            $minWorkers = $response['min_workers'] ?? 1;
            $maxWorkers = $response['max_workers'] ?? 4;
            $metrics = $response['metrics'] ?? [];
            $locked = $response['locked'] ?? false;

            $printer->printing('=== Worker Scaling Status ===');
            $printer->printing('');
            $printer->printing('Auto-scaling: ' . ($enabled ? 'Enabled' : 'Disabled'));
            $printer->printing("Current workers: {$currentWorkers}");
            $printer->printing("Min workers: {$minWorkers}");
            $printer->printing("Max workers: {$maxWorkers}");
            $printer->printing('Scaling locked: ' . ($locked ? 'Yes' : 'No'));
            $printer->printing('');

            if (!empty($metrics)) {
                $printer->printing('=== Load Metrics ===');
                $printer->printing('');
                $printer->printing('Average CPU: ' . number_format($metrics['avg_cpu'] ?? 0, 2) . '%');
                $printer->printing('Max CPU: ' . number_format($metrics['max_cpu'] ?? 0, 2) . '%');
                $printer->printing('Average memory: ' . $this->formatBytes($metrics['avg_memory'] ?? 0));
                $printer->printing('Total queue: ' . ($metrics['total_queue'] ?? 0));
                $printer->printing('Max queue: ' . ($metrics['max_queue'] ?? 0));
                $printer->printing('Total connections: ' . ($metrics['total_connections'] ?? 0));
                $printer->printing('Average response time: ' . number_format($metrics['avg_response_time'] ?? 0, 2) . 'ms');
            }
        } catch (\Throwable $e) {
            $printer->error('Failed to get status: ' . $e->getMessage());
        }
    }

    /**
     * 显示帮助信息
     */
    private function showHelp(Printing $printer): void
    {
        $printer->printing('Usage: php bin/w server:scale [options]');
        $printer->printing('');
        $printer->printing('Options:');
        $printer->printing('  --workers=N    Set target worker count (manual scaling)');
        $printer->printing('  --auto         Enable auto-scaling');
        $printer->printing('  --no-auto      Disable auto-scaling');
        $printer->printing('  --min=N        Set minimum worker count (with --auto)');
        $printer->printing('  --max=N        Set maximum worker count (with --auto)');
        $printer->printing('  --status       Show current scaling status');
        $printer->printing('');
        $printer->printing('Examples:');
        $printer->printing('  php bin/w server:scale --workers=4');
        $printer->printing('  php bin/w server:scale --auto --min=2 --max=8');
        $printer->printing('  php bin/w server:scale --status');
    }

    /**
     * 格式化字节数
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return number_format($bytes, 2) . ' ' . $units[$i];
    }

    public function tip(): string
    {
        return 'Scale WLS workers up or down';
    }

    public function options(): array
    {
        return [
            '--workers=N' => 'Set target worker count',
            '--auto' => 'Enable auto-scaling',
            '--no-auto' => 'Disable auto-scaling',
            '--min=N' => 'Set minimum worker count',
            '--max=N' => 'Set maximum worker count',
            '--status' => 'Show scaling status',
        ];
    }
}
