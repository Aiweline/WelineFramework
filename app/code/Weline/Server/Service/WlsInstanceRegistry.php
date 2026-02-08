<?php
declare(strict_types=1);

/**
 * Weline Server - WLS 实例注册表（只读）
 *
 * 统一提供 WLS 实例目录、实例列表与 Worker PID 列表，供 server:status、
 * CLI 重载观察者、FileWatcher 等复用，避免多处重复路径与解析逻辑。
 *
 * SOLID：单一职责（仅负责实例数据的读取与运行状态检测）、开闭原则（扩展通过新方法）、
 * 依赖倒置（调用方依赖本接口而非具体路径）。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Service;

use Weline\Framework\App\Env;
use Weline\Framework\System\Process\Processer;

class WlsInstanceRegistry
{
    /**
     * 实例信息存储相对路径（相对 VAR_DIR）
     */
    public const INSTANCE_SUBDIR = 'server' . \DIRECTORY_SEPARATOR . 'instances' . \DIRECTORY_SEPARATOR;

    /**
     * 获取实例信息所在目录（绝对路径）
     */
    public function getInstanceDir(): string
    {
        return Env::VAR_DIR . self::INSTANCE_SUBDIR;
    }

    /**
     * 实例是否存在
     */
    public function hasInstance(string $name): bool
    {
        return \is_file($this->getInstanceDir() . $name . '.json');
    }

    /**
     * 获取单个实例数据（不含校验），不存在或解析失败返回 null
     */
    public function getInstanceData(string $name): ?array
    {
        $file = $this->getInstanceDir() . $name . '.json';
        if (!\is_file($file)) {
            return null;
        }
        $content = @\file_get_contents($file);
        if ($content === false) {
            return null;
        }
        $data = \json_decode($content, true);
        return \is_array($data) ? $data : null;
    }

    /**
     * 获取所有实例名称（按文件名，不含 .json）
     *
     * @return string[]
     */
    public function listInstanceNames(): array
    {
        $dir = $this->getInstanceDir();
        if (!\is_dir($dir)) {
            return [];
        }
        $files = \glob($dir . '*.json');
        if ($files === false) {
            return [];
        }
        $names = [];
        foreach ($files as $path) {
            $names[] = \basename($path, '.json');
        }
        return $names;
    }

    /**
     * 获取所有实例数据，键为实例名
     *
     * @return array<string, array>
     */
    public function getAllInstanceData(): array
    {
        $dir = $this->getInstanceDir();
        if (!\is_dir($dir)) {
            return [];
        }
        $files = \glob($dir . '*.json');
        if ($files === false) {
            return [];
        }
        $out = [];
        foreach ($files as $path) {
            $content = @\file_get_contents($path);
            if ($content === false) {
                continue;
            }
            $data = \json_decode($content, true);
            if (!\is_array($data)) {
                continue;
            }
            $name = \basename($path, '.json');
            $out[$name] = $data;
        }
        return $out;
    }

    /**
     * 获取所有运行中的 Master 进程 PID 列表
     * 重载信号应发给 Master，由 Master 统一通知 Worker
     *
     * @return int[]
     */
    public function getRunningMasterPids(): array
    {
        $pids = [];
        foreach ($this->getAllInstanceData() as $data) {
            if (empty($data['master_enabled']) || empty($data['master_pid'])) {
                continue;
            }
            $pid = (int) $data['master_pid'];
            if ($pid > 0 && Processer::processExists($pid)) {
                $pids[] = $pid;
            }
        }
        return \array_values(\array_unique($pids));
    }

    /**
     * 获取所有实例的 Worker PID 列表（合并去重，仅正整数）
     *
     * @return int[]
     */
    public function getAllWorkerPids(): array
    {
        $all = [];
        foreach ($this->getAllInstanceData() as $data) {
            $pids = $data['worker_pids'] ?? [];
            foreach ($pids as $pid) {
                $pid = (int) $pid;
                if ($pid > 0) {
                    $all[] = $pid;
                }
            }
        }
        return \array_values(\array_unique($all));
    }

    /**
     * 获取所有真正运行中的 Worker PID 列表（通过端口检测验证）
     *
     * @return int[]
     */
    public function getRunningWorkerPids(): array
    {
        $runningPids = [];
        foreach ($this->getAllInstanceData() as $data) {
            $count = (int) ($data['count'] ?? 0);
            $pids = $data['worker_pids'] ?? [];

            if ($count <= 0) {
                continue;
            }

            // Dispatcher 模式下 Worker 监听内网端口（worker_port），否则监听主端口（port）
            $dispatcherEnabled = (bool) ($data['dispatcher_enabled'] ?? false);
            if ($dispatcherEnabled && isset($data['worker_port'])) {
                $basePort = (int) $data['worker_port'];
            } else {
                $basePort = (int) ($data['port'] ?? 0);
            }

            if ($basePort <= 0) {
                continue;
            }

            // 按端口验证每个 Worker 是否真正在运行
            for ($i = 0; $i < $count; $i++) {
                $workerPort = $basePort + $i;
                if (Processer::isPortInUse($workerPort)) {
                    // 端口在用，尝试获取对应 PID
                    $pid = $pids[$i] ?? 0;
                    if ($pid > 0) {
                        $runningPids[] = (int) $pid;
                    } else {
                        // 没有记录 PID 但端口在用，尝试通过端口查找 PID
                        $foundPid = $this->getPidByPort($workerPort);
                        if ($foundPid > 0) {
                            $runningPids[] = $foundPid;
                        }
                    }
                }
            }
        }
        return \array_values(\array_unique($runningPids));
    }

    /**
     * 检查指定实例是否有 Worker 真正在运行（通过端口检测）
     */
    public function isInstanceRunning(string $name): bool
    {
        $data = $this->getInstanceData($name);
        if ($data === null) {
            return false;
        }
        return $this->countRunningWorkers($data) > 0;
    }

    /**
     * 统计实例中真正运行的 Worker 数量
     */
    public function countRunningWorkers(array $instanceData): int
    {
        $count = (int) ($instanceData['count'] ?? 0);
        if ($count <= 0) {
            return 0;
        }

        // Dispatcher 模式下 Worker 监听内网端口（worker_port），否则监听主端口（port）
        $dispatcherEnabled = (bool) ($instanceData['dispatcher_enabled'] ?? false);
        if ($dispatcherEnabled && isset($instanceData['worker_port'])) {
            $basePort = (int) $instanceData['worker_port'];
        } else {
            $basePort = (int) ($instanceData['port'] ?? 0);
        }

        if ($basePort <= 0) {
            return 0;
        }

        $running = 0;
        for ($i = 0; $i < $count; $i++) {
            if (Processer::isPortInUse($basePort + $i)) {
                $running++;
            }
        }
        return $running;
    }

    /**
     * 是否有任意真正运行中的 WLS Worker（通过端口检测验证）
     *
     * 统一服务检测：不仅检查实例文件是否存在，还验证端口是否被占用
     */
    public function hasRunningWorkers(): bool
    {
        foreach ($this->getAllInstanceData() as $data) {
            if ($this->countRunningWorkers($data) > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * 获取所有真正运行中的实例统计信息
     *
     * @return array{instances: int, workers: int, ports: int[]}
     */
    public function getRunningStats(): array
    {
        $instances = 0;
        $workers = 0;
        $ports = [];

        foreach ($this->getAllInstanceData() as $data) {
            $count = (int) ($data['count'] ?? 0);
            $running = $this->countRunningWorkers($data);

            if ($running > 0) {
                $instances++;
                $workers += $running;
                
                // Dispatcher 模式下 Worker 监听内网端口（worker_port），否则监听主端口（port）
                $dispatcherEnabled = (bool) ($data['dispatcher_enabled'] ?? false);
                if ($dispatcherEnabled && isset($data['worker_port'])) {
                    $basePort = (int) $data['worker_port'];
                } else {
                    $basePort = (int) ($data['port'] ?? 0);
                }
                
                for ($i = 0; $i < $count; $i++) {
                    $workerPort = $basePort + $i;
                    if (Processer::isPortInUse($workerPort)) {
                        $ports[] = $workerPort;
                    }
                }
            }
        }

        return [
            'instances' => $instances,
            'workers' => $workers,
            'ports' => $ports,
        ];
    }

    /**
     * 通过端口查找 PID（跨平台）
     */
    protected function getPidByPort(int $port): int
    {
        $isWindows = \strtoupper(\substr(\PHP_OS, 0, 3)) === 'WIN';

        if ($isWindows) {
            $output = [];
            @\exec("netstat -ano | findstr :{$port} 2>NUL", $output);
            foreach ($output as $line) {
                if (\preg_match('/LISTENING\s+(\d+)$/', \trim($line), $matches)) {
                    return (int) $matches[1];
                }
            }
        } else {
            $output = [];
            @\exec("lsof -ti:{$port} 2>/dev/null", $output);
            if (!empty($output[0]) && \is_numeric(\trim($output[0]))) {
                return (int) \trim($output[0]);
            }
        }

        return 0;
    }
}
