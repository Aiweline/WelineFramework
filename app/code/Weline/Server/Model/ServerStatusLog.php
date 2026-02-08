<?php
declare(strict_types=1);

/**
 * Weline Server - 服务器状态日志模型
 * 
 * 记录服务器运行状态，包括 Worker、Dispatcher、Master 进程状态
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Model;

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 服务器状态日志模型
 * 
 * 定期记录服务器各进程的运行状态
 */
class ServerStatusLog extends Model
{
    public const fields_ID = 'log_id';
    public const fields_INSTANCE = 'instance';              // 实例名称
    public const fields_PROCESS_TYPE = 'process_type';      // 进程类型：master/dispatcher/worker
    public const fields_PROCESS_ID = 'process_id';          // 进程 ID（Worker ID 或 PID）
    public const fields_WORKER_ID = 'worker_id';            // Worker 编号（仅 worker 类型）
    public const fields_PORT = 'port';                      // 监听端口
    public const fields_PID = 'pid';                        // 系统进程 PID
    public const fields_STATUS = 'status';                  // 状态：running/stopped/error/starting
    public const fields_CONNECTIONS = 'connections';        // 当前连接数
    public const fields_ACTIVE_REQUESTS = 'active_requests';// 活跃请求数
    public const fields_TOTAL_REQUESTS = 'total_requests';  // 总处理请求数
    public const fields_MEMORY_USAGE = 'memory_usage';      // 内存使用（字节）
    public const fields_MEMORY_PEAK = 'memory_peak';        // 内存峰值（字节）
    public const fields_CPU_USAGE = 'cpu_usage';            // CPU 使用率（%）
    public const fields_UPTIME = 'uptime';                  // 运行时间（秒）
    public const fields_LAST_ERROR = 'last_error';          // 最后错误信息
    public const fields_EXTRA_DATA = 'extra_data';          // 额外数据（JSON）
    public const fields_CREATED_AT = 'created_at';
    
    // 进程类型
    public const PROCESS_TYPE_MASTER = 'master';
    public const PROCESS_TYPE_DISPATCHER = 'dispatcher';
    public const PROCESS_TYPE_WORKER = 'worker';
    
    // 状态
    public const STATUS_RUNNING = 'running';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_ERROR = 'error';
    public const STATUS_STARTING = 'starting';
    
    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }
    
    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $this->install($setup, $context);
        }
    }
    
    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }
        
        $setup->createTable('服务器状态日志表')
            ->addColumn(self::fields_ID, TableInterface::column_type_BIGINT, 20, 'primary key auto_increment', '日志ID')
            ->addColumn(self::fields_INSTANCE, TableInterface::column_type_VARCHAR, 50, "default 'default'", '实例名称')
            ->addColumn(self::fields_PROCESS_TYPE, TableInterface::column_type_VARCHAR, 20, 'not null', '进程类型')
            ->addColumn(self::fields_PROCESS_ID, TableInterface::column_type_VARCHAR, 50, '', '进程标识')
            ->addColumn(self::fields_WORKER_ID, TableInterface::column_type_INTEGER, 11, 'default 0', 'Worker编号')
            ->addColumn(self::fields_PORT, TableInterface::column_type_INTEGER, 11, 'default 0', '监听端口')
            ->addColumn(self::fields_PID, TableInterface::column_type_INTEGER, 11, 'default 0', '系统进程PID')
            ->addColumn(self::fields_STATUS, TableInterface::column_type_VARCHAR, 20, "default 'running'", '状态')
            ->addColumn(self::fields_CONNECTIONS, TableInterface::column_type_INTEGER, 11, 'default 0', '当前连接数')
            ->addColumn(self::fields_ACTIVE_REQUESTS, TableInterface::column_type_INTEGER, 11, 'default 0', '活跃请求数')
            ->addColumn(self::fields_TOTAL_REQUESTS, TableInterface::column_type_BIGINT, 20, 'default 0', '总处理请求数')
            ->addColumn(self::fields_MEMORY_USAGE, TableInterface::column_type_BIGINT, 20, 'default 0', '内存使用')
            ->addColumn(self::fields_MEMORY_PEAK, TableInterface::column_type_BIGINT, 20, 'default 0', '内存峰值')
            ->addColumn(self::fields_CPU_USAGE, TableInterface::column_type_DECIMAL, '5,2', 'default 0.00', 'CPU使用率')
            ->addColumn(self::fields_UPTIME, TableInterface::column_type_INTEGER, 11, 'default 0', '运行时间秒')
            ->addColumn(self::fields_LAST_ERROR, TableInterface::column_type_TEXT, 0, '', '最后错误信息')
            ->addColumn(self::fields_EXTRA_DATA, TableInterface::column_type_TEXT, 0, '', '额外数据JSON')
            ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, '', '记录时间')
            ->addIndex(TableInterface::index_type_KEY, 'idx_instance', self::fields_INSTANCE)
            ->addIndex(TableInterface::index_type_KEY, 'idx_process_type', self::fields_PROCESS_TYPE)
            ->addIndex(TableInterface::index_type_KEY, 'idx_status', self::fields_STATUS)
            ->addIndex(TableInterface::index_type_KEY, 'idx_created_at', self::fields_CREATED_AT)
            ->addIndex(TableInterface::index_type_KEY, 'idx_instance_type', [self::fields_INSTANCE, self::fields_PROCESS_TYPE])
            ->create();
    }
    
    /**
     * 保存前自动设置时间戳
     */
    public function save_before(): void
    {
        parent::save_before();
        
        if (!$this->getData(self::fields_CREATED_AT)) {
            $this->setData(self::fields_CREATED_AT, \date('Y-m-d H:i:s'));
        }
    }
    
    // =============== Getter/Setter 方法 ===============
    
    public function getLogId(): int
    {
        return (int) $this->getData(self::fields_ID);
    }
    
    public function setInstance(string $instance): self
    {
        $this->setData(self::fields_INSTANCE, $instance);
        return $this;
    }
    
    public function getInstance(): string
    {
        return (string) ($this->getData(self::fields_INSTANCE) ?: 'default');
    }
    
    public function setProcessType(string $type): self
    {
        $this->setData(self::fields_PROCESS_TYPE, $type);
        return $this;
    }
    
    public function getProcessType(): string
    {
        return (string) $this->getData(self::fields_PROCESS_TYPE);
    }
    
    public function setProcessId(string $id): self
    {
        $this->setData(self::fields_PROCESS_ID, $id);
        return $this;
    }
    
    public function getProcessId(): string
    {
        return (string) $this->getData(self::fields_PROCESS_ID);
    }
    
    public function setWorkerId(int $id): self
    {
        $this->setData(self::fields_WORKER_ID, $id);
        return $this;
    }
    
    public function getWorkerId(): int
    {
        return (int) $this->getData(self::fields_WORKER_ID);
    }
    
    public function setPort(int $port): self
    {
        $this->setData(self::fields_PORT, $port);
        return $this;
    }
    
    public function getPort(): int
    {
        return (int) $this->getData(self::fields_PORT);
    }
    
    public function setPid(int $pid): self
    {
        $this->setData(self::fields_PID, $pid);
        return $this;
    }
    
    public function getPid(): int
    {
        return (int) $this->getData(self::fields_PID);
    }
    
    public function setStatus(string $status): self
    {
        $this->setData(self::fields_STATUS, $status);
        return $this;
    }
    
    public function getStatus(): string
    {
        return (string) ($this->getData(self::fields_STATUS) ?: self::STATUS_RUNNING);
    }
    
    public function setConnections(int $count): self
    {
        $this->setData(self::fields_CONNECTIONS, $count);
        return $this;
    }
    
    public function getConnections(): int
    {
        return (int) $this->getData(self::fields_CONNECTIONS);
    }
    
    public function setActiveRequests(int $count): self
    {
        $this->setData(self::fields_ACTIVE_REQUESTS, $count);
        return $this;
    }
    
    public function getActiveRequests(): int
    {
        return (int) $this->getData(self::fields_ACTIVE_REQUESTS);
    }
    
    public function setTotalRequests(int $count): self
    {
        $this->setData(self::fields_TOTAL_REQUESTS, $count);
        return $this;
    }
    
    public function getTotalRequests(): int
    {
        return (int) $this->getData(self::fields_TOTAL_REQUESTS);
    }
    
    public function setMemoryUsage(int $bytes): self
    {
        $this->setData(self::fields_MEMORY_USAGE, $bytes);
        return $this;
    }
    
    public function getMemoryUsage(): int
    {
        return (int) $this->getData(self::fields_MEMORY_USAGE);
    }
    
    public function setMemoryPeak(int $bytes): self
    {
        $this->setData(self::fields_MEMORY_PEAK, $bytes);
        return $this;
    }
    
    public function getMemoryPeak(): int
    {
        return (int) $this->getData(self::fields_MEMORY_PEAK);
    }
    
    public function setCpuUsage(float $percent): self
    {
        $this->setData(self::fields_CPU_USAGE, $percent);
        return $this;
    }
    
    public function getCpuUsage(): float
    {
        return (float) $this->getData(self::fields_CPU_USAGE);
    }
    
    public function setUptime(int $seconds): self
    {
        $this->setData(self::fields_UPTIME, $seconds);
        return $this;
    }
    
    public function getUptime(): int
    {
        return (int) $this->getData(self::fields_UPTIME);
    }
    
    public function setLastError(string $error): self
    {
        $this->setData(self::fields_LAST_ERROR, $error);
        return $this;
    }
    
    public function getLastError(): string
    {
        return (string) $this->getData(self::fields_LAST_ERROR);
    }
    
    public function setExtraData(array $data): self
    {
        $this->setData(self::fields_EXTRA_DATA, \json_encode($data, JSON_UNESCAPED_UNICODE));
        return $this;
    }
    
    public function getExtraData(): array
    {
        $json = $this->getData(self::fields_EXTRA_DATA);
        if (empty($json)) {
            return [];
        }
        return \json_decode($json, true) ?: [];
    }
    
    // =============== 业务方法 ===============
    
    /**
     * 记录服务器状态
     */
    public function logStatus(array $data): self
    {
        $this->clearQuery()
            ->setInstance($data['instance'] ?? 'default')
            ->setProcessType($data['process_type'] ?? self::PROCESS_TYPE_WORKER)
            ->setProcessId($data['process_id'] ?? '')
            ->setWorkerId($data['worker_id'] ?? 0)
            ->setPort($data['port'] ?? 0)
            ->setPid($data['pid'] ?? 0)
            ->setStatus($data['status'] ?? self::STATUS_RUNNING)
            ->setConnections($data['connections'] ?? 0)
            ->setActiveRequests($data['active_requests'] ?? 0)
            ->setTotalRequests($data['total_requests'] ?? 0)
            ->setMemoryUsage($data['memory_usage'] ?? 0)
            ->setMemoryPeak($data['memory_peak'] ?? 0)
            ->setCpuUsage($data['cpu_usage'] ?? 0.0)
            ->setUptime($data['uptime'] ?? 0)
            ->setLastError($data['last_error'] ?? '')
            ->setExtraData($data['extra_data'] ?? [])
            ->save();
        
        return $this;
    }
    
    /**
     * 获取最新的服务器状态（按实例和进程类型分组）
     */
    public function getLatestStatus(string $instance = 'default'): array
    {
        // 获取最新的各进程状态
        $results = $this->clearQuery()
            ->where(self::fields_INSTANCE, $instance)
            ->order(self::fields_CREATED_AT, 'DESC')
            ->pagination(1, 100)
            ->select()
            ->fetchArray();
        
        // 按进程类型和 ID 分组，只保留最新的
        $latestByProcess = [];
        foreach ($results as $row) {
            $key = $row[self::fields_PROCESS_TYPE] . '_' . $row[self::fields_PROCESS_ID];
            if (!isset($latestByProcess[$key])) {
                $latestByProcess[$key] = $row;
            }
        }
        
        return \array_values($latestByProcess);
    }
    
    /**
     * 获取状态历史记录
     */
    public function getStatusHistory(
        string $instance = 'default',
        string $processType = '',
        int $limit = 100
    ): array {
        $query = $this->clearQuery()
            ->where(self::fields_INSTANCE, $instance);
        
        if ($processType) {
            $query->where(self::fields_PROCESS_TYPE, $processType);
        }
        
        return $query
            ->order(self::fields_CREATED_AT, 'DESC')
            ->pagination(1, $limit)
            ->select()
            ->fetchArray();
    }
    
    /**
     * 清理过期日志
     */
    public function cleanupOldLogs(int $keepDays = 7): int
    {
        $cutoffDate = \date('Y-m-d H:i:s', \time() - ($keepDays * 86400));
        
        $count = $this->clearQuery()
            ->where(self::fields_CREATED_AT, $cutoffDate, '<')
            ->count();
        
        if ($count > 0) {
            $this->clearQuery()
                ->where(self::fields_CREATED_AT, $cutoffDate, '<')
                ->delete()
                ->fetch();
        }
        
        return $count;
    }
    
    /**
     * 获取统计信息
     */
    public function getStatistics(string $instance = 'default'): array
    {
        $results = $this->getLatestStatus($instance);
        
        $stats = [
            'total_workers' => 0,
            'running_workers' => 0,
            'total_connections' => 0,
            'total_requests' => 0,
            'total_memory' => 0,
            'avg_cpu' => 0.0,
            'dispatcher_running' => false,
            'master_running' => false,
        ];
        
        $cpuSum = 0;
        $cpuCount = 0;
        
        foreach ($results as $row) {
            $type = $row[self::fields_PROCESS_TYPE];
            $status = $row[self::fields_STATUS];
            
            if ($type === self::PROCESS_TYPE_WORKER) {
                $stats['total_workers']++;
                if ($status === self::STATUS_RUNNING) {
                    $stats['running_workers']++;
                }
                $stats['total_connections'] += (int) $row[self::fields_CONNECTIONS];
                $stats['total_requests'] += (int) $row[self::fields_TOTAL_REQUESTS];
                $stats['total_memory'] += (int) $row[self::fields_MEMORY_USAGE];
            } elseif ($type === self::PROCESS_TYPE_DISPATCHER) {
                $stats['dispatcher_running'] = ($status === self::STATUS_RUNNING);
            } elseif ($type === self::PROCESS_TYPE_MASTER) {
                $stats['master_running'] = ($status === self::STATUS_RUNNING);
            }
            
            $cpu = (float) $row[self::fields_CPU_USAGE];
            if ($cpu > 0) {
                $cpuSum += $cpu;
                $cpuCount++;
            }
        }
        
        $stats['avg_cpu'] = $cpuCount > 0 ? \round($cpuSum / $cpuCount, 2) : 0.0;
        
        return $stats;
    }
}
