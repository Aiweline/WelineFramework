<?php

declare(strict_types=1);

namespace Weline\Server\Shared\Connection;

/**
 * 连接信号管理器
 *
 * 防止连接风暴：
 * - 同一目标的连接请求去重
 * - 正在连接时，后续请求等待结果
 * - 失败后从队列取下一个重试，最多 3 次
 */
class ConnectionSignalManager
{
    /** @var array<string, array{status:string,result:mixed,waiters:int,retry_count:int,timestamp:float}> */
    private static array $signals = [];

    private const STATUS_IDLE = 'idle';
    private const STATUS_CONNECTING = 'connecting';
    private const STATUS_SUCCESS = 'success';
    private const STATUS_FAILED = 'failed';

    private const MAX_RETRIES = 3;
    private const SIGNAL_TTL = 5.0; // 信号 5 秒后过期

    /**
     * 尝试获取连接信号
     *
     * @param string $key 信号键（如 "memory_server:127.0.0.1:19971"）
     * @param callable $connector 连接回调，返回连接结果
     * @return mixed 连接结果，null 表示正在连接中或失败
     */
    public static function acquire(string $key, callable $connector): mixed
    {
        self::cleanupExpiredSignals();

        // 如果没有信号，创建新信号并立即执行
        if (!isset(self::$signals[$key])) {
            self::$signals[$key] = [
                'status' => self::STATUS_CONNECTING,
                'result' => null,
                'waiters' => 0,
                'retry_count' => 0,
                'timestamp' => \microtime(true),
            ];

            return self::executeConnection($key, $connector);
        }

        $signal = self::$signals[$key];

        // 如果正在连接，增加等待者计数并返回 null
        if ($signal['status'] === self::STATUS_CONNECTING) {
            self::$signals[$key]['waiters']++;
            return null;
        }

        // 如果连接成功，直接返回结果
        if ($signal['status'] === self::STATUS_SUCCESS) {
            return $signal['result'];
        }

        // 如果连接失败，检查是否可以重试
        if ($signal['status'] === self::STATUS_FAILED) {
            if ($signal['retry_count'] >= self::MAX_RETRIES) {
                // 达到最大重试次数，返回失败
                return null;
            }

            // 如果有等待者，让第一个等待者重试
            if ($signal['waiters'] > 0) {
                self::$signals[$key]['waiters']--;
                self::$signals[$key]['status'] = self::STATUS_CONNECTING;
                self::$signals[$key]['retry_count']++;
                self::$signals[$key]['timestamp'] = \microtime(true);

                return self::executeConnection($key, $connector);
            }

            // 没有等待者，返回失败
            return null;
        }

        return null;
    }

    /**
     * 标记连接成功
     */
    public static function markSuccess(string $key, mixed $result): void
    {
        if (!isset(self::$signals[$key])) {
            return;
        }

        self::$signals[$key]['status'] = self::STATUS_SUCCESS;
        self::$signals[$key]['result'] = $result;
        self::$signals[$key]['timestamp'] = \microtime(true);
    }

    /**
     * 标记连接失败
     */
    public static function markFailed(string $key): void
    {
        if (!isset(self::$signals[$key])) {
            return;
        }

        self::$signals[$key]['status'] = self::STATUS_FAILED;
        self::$signals[$key]['result'] = null;
        self::$signals[$key]['timestamp'] = \microtime(true);
    }

    /**
     * 重置信号（用于强制重连）
     */
    public static function reset(string $key): void
    {
        unset(self::$signals[$key]);
    }

    /**
     * 获取信号状态（用于调试）
     */
    public static function getStatus(string $key): ?array
    {
        return self::$signals[$key] ?? null;
    }

    /**
     * 执行连接
     */
    private static function executeConnection(string $key, callable $connector): mixed
    {
        try {
            $result = $connector();

            if ($result !== null && $result !== false) {
                self::markSuccess($key, $result);
                return $result;
            }

            self::markFailed($key);
            return null;
        } catch (\Throwable $e) {
            self::markFailed($key);
            return null;
        }
    }

    /**
     * 清理过期信号
     */
    private static function cleanupExpiredSignals(): void
    {
        $now = \microtime(true);
        foreach (self::$signals as $key => $signal) {
            if ($now - $signal['timestamp'] > self::SIGNAL_TTL) {
                unset(self::$signals[$key]);
            }
        }
    }
}
