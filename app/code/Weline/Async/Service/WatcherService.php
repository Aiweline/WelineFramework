<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Async\Service;

use Weline\Async\Model\SyncMapping;
use Weline\Framework\Manager\ObjectManager;

/**
 * Watcher管理服务
 * 
 * 负责启动/停止watcher、PID管理、状态监控
 * 
 * @package Weline_Async
 */
class WatcherService
{
    private ConfigService $configService;
    private string $pidsDir;
    private string $logsDir;
    private string $binDir;

    public function __construct(ConfigService $configService)
    {
        $this->configService = $configService;
        $this->pidsDir = BP . DS . 'var' . DS . 'async' . DS . 'pids';
        $this->logsDir = BP . DS . 'var' . DS . 'async' . DS . 'logs';
        $this->binDir = BP . DS . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Async' . DS . 'bin';
        
        // 确保目录存在
        if (!is_dir($this->pidsDir)) {
            mkdir($this->pidsDir, 0755, true);
        }
        if (!is_dir($this->logsDir)) {
            mkdir($this->logsDir, 0755, true);
        }
    }

    /**
     * 启动watcher
     * 
     * @param int|string $mappingId 映射ID（可以是数字或'project'）
     * @return array 启动结果
     */
    public function startWatcher(int|string $mappingId): array
    {
        // 检查是否已经在运行
        if ($this->isWatcherRunning($mappingId)) {
            return [
                'success' => false,
                'message' => 'Watcher已经在运行中'
            ];
        }

        // 生成配置文件
        try {
            if ($mappingId === 'project') {
                $configFile = $this->configService->saveProjectWatcherConfigToFile();
            } else {
                $configFile = $this->configService->saveWatcherConfigToFile((int)$mappingId);
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => '生成配置文件失败: ' . $e->getMessage()
            ];
        }

        // 检查Node.js是否可用
        $nodePath = $this->getNodePath();
        if (!$nodePath) {
            return [
                'success' => false,
                'message' => 'Node.js未安装或不在PATH中'
            ];
        }

        // 检查watcher.js是否存在
        $watcherJs = $this->binDir . DS . 'watcher.js';
        if (!file_exists($watcherJs)) {
            return [
                'success' => false,
                'message' => 'watcher.js文件不存在'
            ];
        }

        // 构建启动命令
        $mappingIdStr = is_string($mappingId) ? $mappingId : (string)$mappingId;
        $logFile = $this->logsDir . DS . "mapping_{$mappingIdStr}.log";
        $pidFile = $this->pidsDir . DS . "mapping_{$mappingIdStr}.pid";
        
        // 根据操作系统选择后台运行方式
        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = sprintf(
                'start /B %s %s %s > %s 2>&1',
                escapeshellarg($nodePath),
                escapeshellarg($watcherJs),
                escapeshellarg($configFile),
                escapeshellarg($logFile)
            );
        } else {
            $cmd = sprintf(
                'nohup %s %s %s > %s 2>&1 & echo $! > %s',
                escapeshellarg($nodePath),
                escapeshellarg($watcherJs),
                escapeshellarg($configFile),
                escapeshellarg($logFile),
                escapeshellarg($pidFile)
            );
        }

        exec($cmd);
        
        // 等待一下，检查进程是否启动成功
        sleep(1);
        
        if ($this->isWatcherRunning($mappingId)) {
            return [
                'success' => true,
                'message' => 'Watcher启动成功',
                'pid' => $this->getWatcherPid($mappingId)
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Watcher启动失败，请查看日志'
            ];
        }
    }

    /**
     * 停止watcher
     * 
     * @param int|string $mappingId 映射ID（可以是数字或'project'）
     * @return array 停止结果
     */
    public function stopWatcher(int|string $mappingId): array
    {
        if (!$this->isWatcherRunning($mappingId)) {
            return [
                'success' => false,
                'message' => 'Watcher未运行'
            ];
        }

        $pid = $this->getWatcherPid($mappingId);
        if (!$pid) {
            return [
                'success' => false,
                'message' => '无法获取PID'
            ];
        }

        // 根据操作系统选择终止方式
        if (PHP_OS_FAMILY === 'Windows') {
            exec("taskkill /F /PID {$pid} 2>&1", $output, $returnVar);
        } else {
            exec("kill {$pid} 2>&1", $output, $returnVar);
        }

        // 删除PID文件
        $mappingIdStr = is_string($mappingId) ? $mappingId : (string)$mappingId;
        $pidFile = $this->pidsDir . DS . "mapping_{$mappingIdStr}.pid";
        if (file_exists($pidFile)) {
            unlink($pidFile);
        }

        if ($returnVar === 0) {
            return [
                'success' => true,
                'message' => 'Watcher已停止'
            ];
        } else {
            return [
                'success' => false,
                'message' => '停止Watcher失败: ' . implode("\n", $output)
            ];
        }
    }

    /**
     * 重启watcher
     * 
     * @param int|string $mappingId 映射ID（可以是数字或'project'）
     * @return array 重启结果
     */
    public function restartWatcher(int|string $mappingId): array
    {
        // 先停止
        $stopResult = $this->stopWatcher($mappingId);
        if (!$stopResult['success'] && $this->isWatcherRunning($mappingId)) {
            // 如果停止失败但进程仍在运行，返回错误
            return [
                'success' => false,
                'message' => '停止Watcher失败，无法重启: ' . $stopResult['message']
            ];
        }

        // 等待一下确保进程完全停止
        sleep(1);

        // 再启动
        return $this->startWatcher($mappingId);
    }

    /**
     * 检查watcher是否在运行
     * 
     * @param int|string $mappingId 映射ID（可以是数字或'project'）
     * @return bool
     */
    public function isWatcherRunning(int|string $mappingId): bool
    {
        $pid = $this->getWatcherPid($mappingId);
        if (!$pid) {
            return false;
        }

        // 检查进程是否存在
        if (PHP_OS_FAMILY === 'Windows') {
            exec("tasklist /FI \"PID eq {$pid}\" 2>NUL | find \"{$pid}\"", $output, $returnVar);
            return $returnVar === 0;
        } else {
            exec("ps -p {$pid} 2>&1", $output, $returnVar);
            return $returnVar === 0;
        }
    }

    /**
     * 获取watcher的PID
     * 
     * @param int|string $mappingId 映射ID（可以是数字或'project'）
     * @return int|null
     */
    public function getWatcherPid(int|string $mappingId): ?int
    {
        $mappingIdStr = is_string($mappingId) ? $mappingId : (string)$mappingId;
        $pidFile = $this->pidsDir . DS . "mapping_{$mappingIdStr}.pid";
        if (!file_exists($pidFile)) {
            return null;
        }

        $pid = trim(file_get_contents($pidFile));
        return $pid ? (int)$pid : null;
    }

    /**
     * 获取所有运行中的watcher状态
     * 
     * @return array
     */
    public function getAllWatchersStatus(): array
    {
        /** @var SyncMapping $mappingModel */
        $mappingModel = ObjectManager::getInstance(SyncMapping::class);
        
        $mappings = $mappingModel->clear()
            ->select()
            ->fetch()
            ->getItems();
        
        $status = [];
        
        // 添加后台配置的映射
        foreach ($mappings as $mapping) {
            $mappingId = $mapping->getId();
            $isRunning = $this->isWatcherRunning($mappingId);
            $pid = $isRunning ? $this->getWatcherPid($mappingId) : null;
            
            $status[] = [
                'mapping_id' => $mappingId,
                'type' => 'database',
                'local_path' => $mapping->getData(SyncMapping::schema_fields_LOCAL_PATH),
                'remote_path' => $mapping->getData(SyncMapping::schema_fields_REMOTE_PATH),
                'status' => $mapping->getData(SyncMapping::schema_fields_STATUS),
                'is_running' => $isRunning,
                'pid' => $pid,
            ];
        }
        
        // 添加项目配置的映射（如果存在）
        if ($this->configService->hasProjectConfig()) {
            try {
                $projectConfig = $this->configService->generateProjectWatcherConfig();
                $isRunning = $this->isWatcherRunning('project');
                $pid = $isRunning ? $this->getWatcherPid('project') : null;
                
                $status[] = [
                    'mapping_id' => 'project',
                    'type' => 'project',
                    'local_path' => $projectConfig['mapping']['local_path'] ?? '',
                    'remote_path' => $projectConfig['mapping']['remote_path'] ?? '',
                    'status' => 1, // 项目配置始终为开启状态
                    'is_running' => $isRunning,
                    'pid' => $pid,
                ];
            } catch (\Exception $e) {
                // 配置错误，忽略
            }
        }
        
        return $status;
    }

    /**
     * 启动项目配置的watcher
     * 
     * @return array 启动结果
     */
    public function startProjectWatcher(): array
    {
        return $this->startWatcher('project');
    }

    /**
     * 停止项目配置的watcher
     * 
     * @return array 停止结果
     */
    public function stopProjectWatcher(): array
    {
        return $this->stopWatcher('project');
    }

    /**
     * 获取Node.js路径
     * 
     * @return string|null
     */
    private function getNodePath(): ?string
    {
        $paths = ['node', 'nodejs'];
        
        foreach ($paths as $path) {
            exec("which {$path} 2>&1", $output, $returnVar);
            if ($returnVar === 0 && !empty($output[0])) {
                return trim($output[0]);
            }
        }
        
        // Windows下尝试常见路径
        if (PHP_OS_FAMILY === 'Windows') {
            $commonPaths = [
                'C:\\Program Files\\nodejs\\node.exe',
                'C:\\Program Files (x86)\\nodejs\\node.exe',
            ];
            
            foreach ($commonPaths as $path) {
                if (file_exists($path)) {
                    return $path;
                }
            }
        }
        
        return null;
    }
}
