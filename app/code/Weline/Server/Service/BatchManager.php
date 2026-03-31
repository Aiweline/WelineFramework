<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Server\IPC\ControlMessage;
use Weline\Server\Log\WlsLogger;

/**
 * 批量协调管理器（SOLID: 单一职责 - 仅负责批量操作的协调和跟踪）
 *
 * 职责：
 * 1. 生成唯一 batch_id
 * 2. 跟踪批量操作状态
 * 3. 聚合子进程响应
 * 4. 处理超时
 * 5. 提供批量消息发送接口
 *
 * 不负责：
 * - 具体业务逻辑（stop/reload 等）
 * - 进程管理（由 ServiceOrchestrator 处理）
 * - 消息发送（由 MasterControlServer/IPC 处理）
 */
class BatchManager
{
    /** 批量操作状态常量 */
    public const STATE_PENDING = 'pending';      // 等待中
    public const STATE_RUNNING = 'running';       // 执行中
    public const STATE_COMPLETED = 'completed';  // 已完成
    public const STATE_TIMEOUT = 'timeout';      // 超时
    public const STATE_CANCELLED = 'cancelled';  // 已取消

    /**
     * 批量操作记录
     *
     * @var array<string, array{
     *     id: string,
     *     action: string,
     *     message_type: string,
     *     payload: array,
     *     targets: array{roles?: list<string>, instance_ids?: list<int>, launch_ids?: list<string>},
     *     state: string,
     *     expected: list<int>,
     *     acked: list<int>,
     *     responses: array<int, array>,
     *     created_at: float,
     *     expires_at: float,
     *     completed_at: ?float,
     *     client_id: ?int
     * }>
     */
    private array $operations = [];

    /** 活跃批量操作的最大数量（防止内存泄漏） */
    private int $maxOperations = 100;

    /** 默认批量操作超时（秒） */
    private float $defaultTimeout = 30.0;

    /** 已完成的批量操作保留时间（秒） */
    private float $completedRetention = 60.0;

    /**
     * 生成唯一的批量操作 ID
     */
    public function generateBatchId(string $prefix = 'batch'): string
    {
        return \sprintf(
            '%s-%d-%s',
            $prefix,
            \time(),
            \bin2hex(\random_bytes(4))
        );
    }

    /**
     * 创建新的批量操作
     *
     * @param string $action 操作名称（如 'stop', 'reload'）
     * @param string $messageType 要发送的消息类型
     * @param array $payload 消息负载
     * @param array $targets 目标：['roles' => [...], 'instance_ids' => [...]]
     * @param list<int> $expectedClients 期望响应的客户端 ID 列表
     * @param float|null $timeout 超时时间（秒）
     * @param int|null $clientId 请求来源的客户端 ID
     * @return string batch_id
     */
    public function createOperation(
        string $action,
        string $messageType,
        array $payload = [],
        array $targets = [],
        array $expectedClients = [],
        ?float $timeout = null,
        ?int $clientId = null
    ): string {
        // 清理过期操作
        $this->cleanup();

        $batchId = $this->generateBatchId($action);

        // 如果操作过多，删除最老的已完成操作
        if (\count($this->operations) >= $this->maxOperations) {
            $this->evictOldestCompleted();
        }

        $now = \microtime(true);
        $expiresAt = $now + ($timeout ?? $this->defaultTimeout);

        $this->operations[$batchId] = [
            'id' => $batchId,
            'action' => $action,
            'message_type' => $messageType,
            'payload' => $payload,
            'targets' => $targets,
            'state' => self::STATE_PENDING,
            'expected' => $expectedClients,
            'acked' => [],
            'responses' => [],
            'created_at' => $now,
            'expires_at' => $expiresAt,
            'completed_at' => null,
            'client_id' => $clientId,
        ];

        WlsLogger::debug_(
            "[BatchManager] 创建批量操作 {$batchId}（{$action}），期望 " . \count($expectedClients) . " 个响应，超时 " . ($timeout ?? $this->defaultTimeout) . 's'
        );

        return $batchId;
    }

    /**
     * 获取批量操作
     */
    public function getOperation(string $batchId): ?array
    {
        return $this->operations[$batchId] ?? null;
    }

    /**
     * 启动批量操作（状态从 pending 变为 running）
     */
    public function startOperation(string $batchId): bool
    {
        if (!isset($this->operations[$batchId])) {
            return false;
        }

        if ($this->operations[$batchId]['state'] !== self::STATE_PENDING) {
            return false;
        }

        $this->operations[$batchId]['state'] = self::STATE_RUNNING;

        WlsLogger::debug_("[BatchManager] 启动批量操作 {$batchId}");

        return true;
    }

    /**
     * 记录 ACK（子进程确认收到）
     */
    public function recordAck(string $batchId, int $clientId): bool
    {
        if (!isset($this->operations[$batchId])) {
            return false;
        }

        $op = &$this->operations[$batchId];

        // 避免重复 ACK
        if (\in_array($clientId, $op['acked'], true)) {
            return false;
        }

        $op['acked'][] = $clientId;

        WlsLogger::debug_(
            "[BatchManager] 批量操作 {$batchId} 收到 ACK（{$clientId}），进度 " . \count($op['acked']) . '/' . \count($op['expected'])
        );

        // 检查是否所有子进程都已 ACK
        $this->checkCompletion($batchId);

        return true;
    }

    /**
     * 记录响应
     */
    public function recordResponse(string $batchId, int $clientId, array $response): bool
    {
        if (!isset($this->operations[$batchId])) {
            return false;
        }

        $op = &$this->operations[$batchId];

        $op['responses'][$clientId] = $response;

        WlsLogger::debug_(
            "[BatchManager] 批量操作 {$batchId} 收到响应（{$clientId}），进度 " . \count($op['responses']) . '/' . \count($op['expected'])
        );

        // 检查是否所有子进程都已响应
        $this->checkCompletion($batchId);

        return true;
    }

    /**
     * 取消批量操作
     */
    public function cancelOperation(string $batchId): bool
    {
        if (!isset($this->operations[$batchId])) {
            return false;
        }

        $this->operations[$batchId]['state'] = self::STATE_CANCELLED;
        $this->operations[$batchId]['completed_at'] = \microtime(true);

        WlsLogger::info_("[BatchManager] 取消批量操作 {$batchId}");

        return true;
    }

    /**
     * 检查批量操作是否完成
     */
    private function checkCompletion(string $batchId): void
    {
        if (!isset($this->operations[$batchId])) {
            return;
        }

        $op = &$this->operations[$batchId];

        // 只有 running 状态才能完成
        if ($op['state'] !== self::STATE_RUNNING) {
            return;
        }

        // 所有期望的客户端都已响应
        if (\count($op['responses']) >= \count($op['expected'])) {
            $op['state'] = self::STATE_COMPLETED;
            $op['completed_at'] = \microtime(true);

            WlsLogger::info_(
                "[BatchManager] 批量操作 {$batchId}（{$op['action']}）完成，" . \count($op['responses']) . " 个响应"
            );
        }
    }

    /**
     * 检查并处理超时
     *
     * @return list<string> 超时批量操作的 ID 列表
     */
    public function checkTimeouts(): array
    {
        $now = \microtime(true);
        $timedOut = [];

        foreach ($this->operations as $batchId => &$op) {
            if ($op['state'] === self::STATE_RUNNING && $now >= $op['expires_at']) {
                $op['state'] = self::STATE_TIMEOUT;
                $op['completed_at'] = $now;
                $timedOut[] = $batchId;

                WlsLogger::warning_(
                    "[BatchManager] 批量操作 {$batchId}（{$op['action']}）超时，" .
                    \count($op['acked']) . '/' . \count($op['expected']) . ' 已响应'
                );
            }
        }

        return $timedOut;
    }

    /**
     * 获取待处理的批量操作
     *
     * @return array<string, array>
     */
    public function getPendingOperations(): array
    {
        $pending = [];

        foreach ($this->operations as $batchId => $op) {
            if ($op['state'] === self::STATE_RUNNING || $op['state'] === self::STATE_PENDING) {
                $pending[$batchId] = $op;
            }
        }

        return $pending;
    }

    /**
     * 构建批量广播消息
     *
     * @param string $batchId 批量操作 ID
     * @param string $messageType 要发送的消息类型
     * @param array $payload 消息负载
     * @param array $targets 目标
     * @param int $expiresAt 过期时间戳
     */
    public function buildBatchMessage(
        string $batchId,
        string $messageType,
        array $payload = [],
        array $targets = [],
        int $expiresAt = 0
    ): string {
        return ControlMessage::batchBroadcast(
            $batchId,
            $messageType,
            $payload,
            $targets,
            $expiresAt
        );
    }

    /**
     * 构建批量停止消息
     */
    public function buildBatchStopMessage(
        string $batchId,
        array $targets = [],
        int $expiresAt = 0
    ): string {
        return ControlMessage::batchStop($batchId, $targets, $expiresAt);
    }

    /**
     * 构建批量重载消息
     */
    public function buildBatchReloadMessage(
        string $batchId,
        string $reloadType = ControlMessage::RELOAD_TYPE_CODE,
        array $targets = [],
        int $expiresAt = 0
    ): string {
        return ControlMessage::batchReload($batchId, $reloadType, $targets, $expiresAt);
    }

    /**
     * 清理已过期的已完成操作
     */
    private function cleanup(): void
    {
        $now = \microtime(true);

        foreach ($this->operations as $batchId => $op) {
            if (
                ($op['state'] === self::STATE_COMPLETED ||
                 $op['state'] === self::STATE_TIMEOUT ||
                 $op['state'] === self::STATE_CANCELLED) &&
                $op['completed_at'] !== null &&
                ($now - $op['completed_at']) > $this->completedRetention
            ) {
                unset($this->operations[$batchId]);

                WlsLogger::debug_("[BatchManager] 清理过期批量操作 {$batchId}");
            }
        }
    }

    /**
     * 驱逐最老的已完成操作
     */
    private function evictOldestCompleted(): void
    {
        $oldest = null;
        $oldestId = null;

        foreach ($this->operations as $batchId => $op) {
            if ($op['completed_at'] !== null) {
                if ($oldest === null || $op['completed_at'] < $oldest) {
                    $oldest = $op['completed_at'];
                    $oldestId = $batchId;
                }
            }
        }

        if ($oldestId !== null) {
            unset($this->operations[$oldestId]);
            WlsLogger::debug_("[BatchManager] 驱逐最老已完成操作 {$oldestId}");
        }
    }

    /**
     * 获取操作统计
     */
    public function getStats(): array
    {
        $stats = [
            'total' => \count($this->operations),
            'pending' => 0,
            'running' => 0,
            'completed' => 0,
            'timeout' => 0,
            'cancelled' => 0,
        ];

        foreach ($this->operations as $op) {
            $stats[$op['state']]++;
        }

        return $stats;
    }

    /**
     * 检查目标是否匹配批量操作
     *
     * @param array $targets 批量操作的目标描述
     * @param string $role 子进程角色
     * @param int $instanceId 子进程实例 ID
     * @param string $launchId 子进程 launch ID
     * @return bool
     */
    public static function targetMatches(
        array $targets,
        string $role,
        int $instanceId,
        string $launchId = ''
    ): bool {
        // 如果没有指定 targets，匹配所有人
        if (empty($targets)) {
            return true;
        }

        // 检查角色
        if (isset($targets['roles'])) {
            if (!\in_array($role, $targets['roles'], true)) {
                return false;
            }
        }

        // 检查实例 ID
        if (isset($targets['instance_ids'])) {
            if (!\in_array($instanceId, $targets['instance_ids'], true)) {
                return false;
            }
        }

        // 检查 launch ID
        if (isset($targets['launch_ids'])) {
            if (!\in_array($launchId, $targets['launch_ids'], true)) {
                return false;
            }
        }

        return true;
    }
}
