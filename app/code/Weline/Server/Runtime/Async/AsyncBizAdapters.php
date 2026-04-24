<?php
declare(strict_types=1);

namespace Weline\Server\Runtime\Async;

/**
 * 业务异步适配门面（过渡期）。
 *
 * 目标：
 * - 统一包装业务侧潜在阻塞调用入口；
 * - 让主循环在重业务阶段至少拥有协作式让出机会。
 *
 * 说明：
 * - 当前版本提供最小侵入包装，不改变业务返回值；
 * - 后续可逐步扩展 HTTP/DB/File 的真实 async 适配实现。
 */
final class AsyncBizAdapters
{
    /**
     * 读文件：调度器活跃时在读前让出；大负载读完后再让出一次，避免磁盘 I/O 长时间占满 Fiber。
     *
     * 用于 WLS Worker 静态资源、ACME 等小文件热路径；不改变 file_get_contents 语义。
     */
    public static function fileGetContentsWithYield(string $path, int $yieldAgainIfBytesAtLeast = 262144): string|false
    {
        if (\Weline\Framework\Runtime\SchedulerSystem::isSchedulerActive()) {
            \Weline\Framework\Runtime\SchedulerSystem::yield();
        }
        $data = @\file_get_contents($path);
        if ($data !== false
            && \strlen($data) >= $yieldAgainIfBytesAtLeast
            && \Weline\Framework\Runtime\SchedulerSystem::isSchedulerActive()) {
            \Weline\Framework\Runtime\SchedulerSystem::yield();
        }

        return $data;
    }

    /**
     * 包装业务分发调用并记录慢路径。
     *
     * 这里包住的是完整框架 dispatch；不能在边界处主动 yield，
     * 否则普通请求会先进入调度队列，响应发送前也可能再次排队。
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function dispatch(callable $callback): mixed
    {
        $startedAt = \microtime(true);
        try {
            return $callback();
        } finally {
            $elapsedMs = (\microtime(true) - $startedAt) * 1000;
            if ($elapsedMs >= 500) {
                \Weline\Server\Log\WlsLogger::warning_(
                    'AsyncBizAdapters dispatch slow path elapsed_ms=' . \round($elapsedMs, 2)
                );
            }
        }
    }
}

