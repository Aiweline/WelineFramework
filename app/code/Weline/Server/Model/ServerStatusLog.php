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
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** 服务器状态日志模型 - 定期记录服务器各进程的运行状态 */
#[Table(comment: '服务器状态日志表')]
#[Index(name: 'idx_instance', columns: ['instance'])]
#[Index(name: 'idx_process_type', columns: ['process_type'])]
#[Index(name: 'idx_status', columns: ['status'])]
#[Index(name: 'idx_created_at', columns: ['created_at'])]
#[Index(name: 'idx_instance_type', columns: ['instance', 'process_type'])]
class ServerStatusLog extends Model
{
    public const schema_table = 'weline_server_status_log';
    public const schema_primary_key = 'log_id';
    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: '日志ID')]
    public const schema_fields_ID = 'log_id';
    #[Col('varchar', 50, default: 'default', comment: '实例名称')]
    public const schema_fields_INSTANCE = 'instance';
    #[Col('varchar', 20, nullable: false, comment: '进程类型')]
    public const schema_fields_PROCESS_TYPE = 'process_type';
    #[Col('varchar', 50, comment: '进程标识')]
    public const schema_fields_PROCESS_ID = 'process_id';
    #[Col('int', 11, default: 0, comment: 'Worker编号')]
    public const schema_fields_WORKER_ID = 'worker_id';
    #[Col('int', 11, default: 0, comment: '监听端口')]
    public const schema_fields_PORT = 'port';
    #[Col('int', 11, default: 0, comment: '系统进程PID')]
    public const schema_fields_PID = 'pid';
    #[Col('varchar', 20, default: 'running', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('int', 11, default: 0, comment: '当前连接数')]
    public const schema_fields_CONNECTIONS = 'connections';
    #[Col('int', 11, default: 0, comment: '活跃请求数')]
    public const schema_fields_ACTIVE_REQUESTS = 'active_requests';
    #[Col('bigint', 20, default: 0, comment: '总处理请求数')]
    public const schema_fields_TOTAL_REQUESTS = 'total_requests';
    #[Col('bigint', 20, default: 0, comment: '内存使用')]
    public const schema_fields_MEMORY_USAGE = 'memory_usage';
    #[Col('bigint', 20, default: 0, comment: '内存峰值')]
    public const schema_fields_MEMORY_PEAK = 'memory_peak';
    #[Col('decimal', '5,2', default: '0.00', comment: 'CPU使用率')]
    public const schema_fields_CPU_USAGE = 'cpu_usage';
    #[Col('int', 11, default: 0, comment: '运行时间秒')]
    public const schema_fields_UPTIME = 'uptime';
    #[Col('text', comment: '最后错误信息')]
    public const schema_fields_LAST_ERROR = 'last_error';
    #[Col('text', comment: '额外数据JSON')]
    public const schema_fields_EXTRA_DATA = 'extra_data';
    #[Col('datetime', comment: '记录时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    public const PROCESS_TYPE_MASTER = 'master';
    public const PROCESS_TYPE_DISPATCHER = 'dispatcher';
    public const PROCESS_TYPE_WORKER = 'worker';
    public const STATUS_RUNNING = 'running';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_ERROR = 'error';
    public const STATUS_STARTING = 'starting';
/**
     * 保存前自动设置时间戳
     */
    public function save_before(): void
    {
        parent::save_before();
        
        if (!$this->getData(self::schema_fields_CREATED_AT)) {
            $this->setData(self::schema_fields_CREATED_AT, \date('Y-m-d H:i:s'));
        }
    }
    
    // =============== Getter/Setter 方法 ===============
    
    public function getLogId(): int
    {
        return (int) $this->getData(self::schema_fields_ID);
    }
    
    public function setInstance(string $instance): self
    {
        $this->setData(self::schema_fields_INSTANCE, $instance);
        return $this;
    }
    
    public function getInstance(): string
    {
        return (string) ($this->getData(self::schema_fields_INSTANCE) ?: 'default');
    }
    
    public function setProcessType(string $type): self
    {
        $this->setData(self::schema_fields_PROCESS_TYPE, $type);
        return $this;
    }
    
    public function getProcessType(): string
    {
        return (string) $this->getData(self::schema_fields_PROCESS_TYPE);
    }
    
    public function setProcessId(string $id): self
    {
        $this->setData(self::schema_fields_PROCESS_ID, $id);
        return $this;
    }
    
    public function getProcessId(): string
    {
        return (string) $this->getData(self::schema_fields_PROCESS_ID);
    }
    
    public function setWorkerId(int $id): self
    {
        $this->setData(self::schema_fields_WORKER_ID, $id);
        return $this;
    }
    
    public function getWorkerId(): int
    {
        return (int) $this->getData(self::schema_fields_WORKER_ID);
    }
    
    public function setPort(int $port): self
    {
        $this->setData(self::schema_fields_PORT, $port);
        return $this;
    }
    
    public function getPort(): int
    {
        return (int) $this->getData(self::schema_fields_PORT);
    }
    
    public function setPid(int $pid): self
    {
        $this->setData(self::schema_fields_PID, $pid);
        return $this;
    }
    
    public function getPid(): int
    {
        return (int) $this->getData(self::schema_fields_PID);
    }
    
    public function setStatus(string $status): self
    {
        $this->setData(self::schema_fields_STATUS, $status);
        return $this;
    }
    
    public function getStatus(): string
    {
        return (string) ($this->getData(self::schema_fields_STATUS) ?: self::STATUS_RUNNING);
    }
    
    public function setConnections(int $count): self
    {
        $this->setData(self::schema_fields_CONNECTIONS, $count);
        return $this;
    }
    
    public function getConnections(): int
    {
        return (int) $this->getData(self::schema_fields_CONNECTIONS);
    }
    
    public function setActiveRequests(int $count): self
    {
        $this->setData(self::schema_fields_ACTIVE_REQUESTS, $count);
        return $this;
    }
    
    public function getActiveRequests(): int
    {
        return (int) $this->getData(self::schema_fields_ACTIVE_REQUESTS);
    }
    
    public function setTotalRequests(int $count): self
    {
        $this->setData(self::schema_fields_TOTAL_REQUESTS, $count);
        return $this;
    }
    
    public function getTotalRequests(): int
    {
        return (int) $this->getData(self::schema_fields_TOTAL_REQUESTS);
    }
    
    public function setMemoryUsage(int $bytes): self
    {
        $this->setData(self::schema_fields_MEMORY_USAGE, $bytes);
        return $this;
    }
    
    public function getMemoryUsage(): int
    {
        return (int) $this->getData(self::schema_fields_MEMORY_USAGE);
    }
    
    public function setMemoryPeak(int $bytes): self
    {
        $this->setData(self::schema_fields_MEMORY_PEAK, $bytes);
        return $this;
    }
    
    public function getMemoryPeak(): int
    {
        return (int) $this->getData(self::schema_fields_MEMORY_PEAK);
    }
    
    public function setCpuUsage(float $percent): self
    {
        $this->setData(self::schema_fields_CPU_USAGE, $percent);
        return $this;
    }
    
    public function getCpuUsage(): float
    {
        return (float) $this->getData(self::schema_fields_CPU_USAGE);
    }
    
    public function setUptime(int $seconds): self
    {
        $this->setData(self::schema_fields_UPTIME, $seconds);
        return $this;
    }
    
    public function getUptime(): int
    {
        return (int) $this->getData(self::schema_fields_UPTIME);
    }
    
    public function setLastError(string $error): self
    {
        $this->setData(self::schema_fields_LAST_ERROR, $error);
        return $this;
    }
    
    public function getLastError(): string
    {
        return (string) $this->getData(self::schema_fields_LAST_ERROR);
    }
    
    public function setExtraData(array $data): self
    {
        $this->setData(self::schema_fields_EXTRA_DATA, \json_encode($data, JSON_UNESCAPED_UNICODE));
        return $this;
    }
    
    public function getExtraData(): array
    {
        $json = $this->getData(self::schema_fields_EXTRA_DATA);
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
            ->where(self::schema_fields_INSTANCE, $instance)
            ->order(self::schema_fields_CREATED_AT, 'DESC')
            ->pagination(1, 100)
            ->select()
            ->fetchArray();
        
        // 按进程类型和 ID 分组，只保留最新的
        $latestByProcess = [];
        foreach ($results as $row) {
            $key = $row[self::schema_fields_PROCESS_TYPE] . '_' . $row[self::schema_fields_PROCESS_ID];
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
            ->where(self::schema_fields_INSTANCE, $instance);
        
        if ($processType) {
            $query->where(self::schema_fields_PROCESS_TYPE, $processType);
        }
        
        return $query
            ->order(self::schema_fields_CREATED_AT, 'DESC')
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
            ->where(self::schema_fields_CREATED_AT, $cutoffDate, '<')
            ->count();
        
        if ($count > 0) {
            $this->clearQuery()
                ->where(self::schema_fields_CREATED_AT, $cutoffDate, '<')
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
            $type = $row[self::schema_fields_PROCESS_TYPE];
            $status = $row[self::schema_fields_STATUS];
            
            if ($type === self::PROCESS_TYPE_WORKER) {
                $stats['total_workers']++;
                if ($status === self::STATUS_RUNNING) {
                    $stats['running_workers']++;
                }
                $stats['total_connections'] += (int) $row[self::schema_fields_CONNECTIONS];
                $stats['total_requests'] += (int) $row[self::schema_fields_TOTAL_REQUESTS];
                $stats['total_memory'] += (int) $row[self::schema_fields_MEMORY_USAGE];
            } elseif ($type === self::PROCESS_TYPE_DISPATCHER) {
                $stats['dispatcher_running'] = ($status === self::STATUS_RUNNING);
            } elseif ($type === self::PROCESS_TYPE_MASTER) {
                $stats['master_running'] = ($status === self::STATUS_RUNNING);
            }
            
            $cpu = (float) $row[self::schema_fields_CPU_USAGE];
            if ($cpu > 0) {
                $cpuSum += $cpu;
                $cpuCount++;
            }
        }
        
        $stats['avg_cpu'] = $cpuCount > 0 ? \round($cpuSum / $cpuCount, 2) : 0.0;
        
        return $stats;
    }
}
