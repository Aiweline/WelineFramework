<?php
declare(strict_types=1);

namespace Weline\Ai\Service;

use Weline\Framework\Cache\CacheInterface;

/**
 * 队列服务
 * 
 * 功能：
 * - 异步任务处理
 * - 延迟任务执行
 * - 任务优先级管理
 * - 失败重试机制
 * 
 * 注意：这是基于缓存的简单队列实现
 * 生产环境建议使用 Redis Queue 或 RabbitMQ
 * 
 * @package Weline_Ai
 */
class QueueService
{
    /**
     * 队列名称前缀
     */
    private const QUEUE_PREFIX = 'ai_queue_';

    /**
     * 任务状态
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * 优先级
     */
    public const PRIORITY_LOW = 1;
    public const PRIORITY_NORMAL = 5;
    public const PRIORITY_HIGH = 10;

    /**
     * 缓存接口
     */
    private CacheInterface $cache;

    public function __construct(CacheInterface $cache)
    {
        $this->cache = $cache;
    }

    /**
     * 推送任务到队列
     *
     * @param string $queueName 队列名称
     * @param string $taskType 任务类型
     * @param array $payload 任务数据
     * @param int $priority 优先级
     * @param int $delaySeconds 延迟秒数
     * @return string 任务ID
     */
    public function push(
        string $queueName,
        string $taskType,
        array $payload,
        int $priority = self::PRIORITY_NORMAL,
        int $delaySeconds = 0
    ): string {
        $taskId = $this->generateTaskId();
        
        $task = [
            'id' => $taskId,
            'queue' => $queueName,
            'type' => $taskType,
            'payload' => $payload,
            'priority' => $priority,
            'status' => self::STATUS_PENDING,
            'created_at' => time(),
            'execute_at' => time() + $delaySeconds,
            'attempts' => 0,
            'max_attempts' => 3,
            'last_error' => null,
        ];

        // 存储任务
        $this->saveTask($taskId, $task);

        // 添加到队列索引
        $this->addToQueue($queueName, $taskId, $priority);

        return $taskId;
    }

    /**
     * 从队列获取任务
     *
     * @param string $queueName
     * @return array|null
     */
    public function pop(string $queueName): ?array
    {
        $queueKey = $this->getQueueKey($queueName);
        $queueData = $this->cache->get($queueKey);

        if (!$queueData) {
            return null;
        }

        $queue = json_decode($queueData, true);
        if (empty($queue)) {
            return null;
        }

        // 按优先级排序
        usort($queue, function ($a, $b) {
            return $b['priority'] - $a['priority'];
        });

        // 获取第一个可执行的任务
        foreach ($queue as $index => $item) {
            $task = $this->getTask($item['task_id']);
            
            if (!$task) {
                // 任务不存在，从队列中移除
                unset($queue[$index]);
                continue;
            }

            // 检查是否到达执行时间
            if ($task['execute_at'] > time()) {
                continue;
            }

            // 检查状态
            if ($task['status'] !== self::STATUS_PENDING) {
                unset($queue[$index]);
                continue;
            }

            // 更新任务状态
            $task['status'] = self::STATUS_PROCESSING;
            $task['attempts']++;
            $this->saveTask($task['id'], $task);

            // 从队列中移除
            unset($queue[$index]);
            $this->cache->set($queueKey, json_encode(array_values($queue)));

            return $task;
        }

        // 更新队列（移除无效任务）
        $this->cache->set($queueKey, json_encode(array_values($queue)));

        return null;
    }

    /**
     * 标记任务完成
     *
     * @param string $taskId
     * @return bool
     */
    public function complete(string $taskId): bool
    {
        $task = $this->getTask($taskId);
        if (!$task) {
            return false;
        }

        $task['status'] = self::STATUS_COMPLETED;
        $task['completed_at'] = time();
        $this->saveTask($taskId, $task);

        return true;
    }

    /**
     * 标记任务失败
     *
     * @param string $taskId
     * @param string $error
     * @param bool $retry
     * @return bool
     */
    public function fail(string $taskId, string $error, bool $retry = true): bool
    {
        $task = $this->getTask($taskId);
        if (!$task) {
            return false;
        }

        $task['last_error'] = $error;
        $task['failed_at'] = time();

        // 检查是否重试
        if ($retry && $task['attempts'] < $task['max_attempts']) {
            // 重新入队，延迟执行
            $task['status'] = self::STATUS_PENDING;
            $task['execute_at'] = time() + (60 * $task['attempts']); // 指数退避

            $this->saveTask($taskId, $task);
            $this->addToQueue($task['queue'], $taskId, $task['priority']);
        } else {
            // 标记为最终失败
            $task['status'] = self::STATUS_FAILED;
            $this->saveTask($taskId, $task);
        }

        return true;
    }

    /**
     * 获取任务详情
     *
     * @param string $taskId
     * @return array|null
     */
    public function getTask(string $taskId): ?array
    {
        $key = $this->getTaskKey($taskId);
        $data = $this->cache->get($key);

        if (!$data) {
            return null;
        }

        return json_decode($data, true);
    }

    /**
     * 获取队列长度
     *
     * @param string $queueName
     * @return int
     */
    public function getQueueLength(string $queueName): int
    {
        $queueKey = $this->getQueueKey($queueName);
        $queueData = $this->cache->get($queueKey);

        if (!$queueData) {
            return 0;
        }

        $queue = json_decode($queueData, true);
        return count($queue);
    }

    /**
     * 清空队列
     *
     * @param string $queueName
     * @return bool
     */
    public function clearQueue(string $queueName): bool
    {
        $queueKey = $this->getQueueKey($queueName);
        $this->cache->delete($queueKey);
        return true;
    }

    /**
     * 生成任务ID
     *
     * @return string
     */
    private function generateTaskId(): string
    {
        return 'task_' . uniqid() . '_' . bin2hex(random_bytes(8));
    }

    /**
     * 保存任务
     *
     * @param string $taskId
     * @param array $task
     * @return void
     */
    private function saveTask(string $taskId, array $task): void
    {
        $key = $this->getTaskKey($taskId);
        // 任务保留 24 小时
        $this->cache->set($key, json_encode($task), 86400);
    }

    /**
     * 添加到队列索引
     *
     * @param string $queueName
     * @param string $taskId
     * @param int $priority
     * @return void
     */
    private function addToQueue(string $queueName, string $taskId, int $priority): void
    {
        $queueKey = $this->getQueueKey($queueName);
        $queueData = $this->cache->get($queueKey);

        $queue = $queueData ? json_decode($queueData, true) : [];
        
        $queue[] = [
            'task_id' => $taskId,
            'priority' => $priority,
            'added_at' => time(),
        ];

        $this->cache->set($queueKey, json_encode($queue));
    }

    /**
     * 获取队列键
     *
     * @param string $queueName
     * @return string
     */
    private function getQueueKey(string $queueName): string
    {
        return self::QUEUE_PREFIX . 'index_' . $queueName;
    }

    /**
     * 获取任务键
     *
     * @param string $taskId
     * @return string
     */
    private function getTaskKey(string $taskId): string
    {
        return self::QUEUE_PREFIX . 'task_' . $taskId;
    }

    /**
     * 获取队列统计信息
     *
     * @param string $queueName
     * @return array
     */
    public function getQueueStats(string $queueName): array
    {
        $queueKey = $this->getQueueKey($queueName);
        $queueData = $this->cache->get($queueKey);

        if (!$queueData) {
            return [
                'length' => 0,
                'pending' => 0,
                'processing' => 0,
                'failed' => 0,
            ];
        }

        $queue = json_decode($queueData, true);
        $stats = [
            'length' => count($queue),
            'pending' => 0,
            'processing' => 0,
            'failed' => 0,
        ];

        foreach ($queue as $item) {
            $task = $this->getTask($item['task_id']);
            if ($task) {
                switch ($task['status']) {
                    case self::STATUS_PENDING:
                        $stats['pending']++;
                        break;
                    case self::STATUS_PROCESSING:
                        $stats['processing']++;
                        break;
                    case self::STATUS_FAILED:
                        $stats['failed']++;
                        break;
                }
            }
        }

        return $stats;
    }
}

