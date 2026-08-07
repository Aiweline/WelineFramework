<?php
declare(strict_types=1);

namespace Weline\Server\Service\Protocol\LongLived;

/**
 * SSE 协议匹配器（Worker 层启发式）
 *
 * 当任意一行 Accept 含 text/event-stream（含多行 Accept、带 charset 等参数）时返回
 * `is_long_lived=true, protocol=sse`（与 {@see PathFallbackMatcher} 路径规则互补）。
 *
 * **与 Fiber 的关系**：普通 HTTP 与长连在 WLS 中**都可以**跑在 Fiber 上；本类**不是**「启用 Fiber」的开关。
 *
 * **`is_long_lived` 在 Worker 中的实际消费**：
 * - HTTP/1.x 登记 `longLivedConnections`；其 fd 不再按「读完一整份 HTTP」模型抢读；
 * - HTTP/2 SSE 只登记 `(connection, stream)` Fiber，不登记为整连接长连；TLS fd 必须继续读取
 *   `WINDOW_UPDATE`、`RST_STREAM`、PING 以及同一连接上的其它 stream；
 * - 挂起的 Fiber 元数据带 `is_long_lived`：**不**按短连接的 `fiberIdleTtl` 做闲置回收（`worker.php`：`wlsReleaseIdleFibersStep`；`worker_ssl.php`：主循环 Fiber 巡检）。
 *
 * **`protocol === 'sse'`**（`worker.php` 与 `worker_ssl.php` 已对齐）：
 * - 设置 `is_sse_protocol` 并注册 {@see \Weline\Framework\Http\Sse\SseContext}::setWriteCallback；H1 经连接写队列刷写，H2 经 `ConnectionAdapter` 生成增量 DATA 并遵守连接/流级流控；
 * - H1 收尾排空后关闭连接；H2 仅发送该 stream 的 END_STREAM，不能关闭仍承载其它请求的 TLS 连接。
 *
 * **状态隔离**：并发 Fiber 依赖 {@see \Weline\Framework\Runtime\WlsFiberContext} capture/restore、入口 `wlsFiberRequestContextEnter`/`Leave`；禁止 static/单例跨 Fiber 缓存请求态。
 * 业务语义以 {@see \Weline\Framework\Http\Sse\SseWriter} 为准；本类仅为原始请求启发式，**非**是否 SSE 的终审。
 */
class SseMatcher implements MatcherInterface
{
    public function match(string $rawRequest): ?array
    {
        // 扫描全部 Accept 行（部分客户端/代理先发 */* 再发 text/event-stream）
        if (\preg_match_all('/^Accept:\s*([^\r\n]+)/im', $rawRequest, $matches) < 1) {
            return null;
        }
        foreach ($matches[1] as $value) {
            $accept = \strtolower(\trim((string) $value));
            if ($accept !== '' && \str_contains($accept, 'text/event-stream')) {
                return ['is_long_lived' => true, 'layer' => 'layer-1-header', 'protocol' => 'sse'];
            }
        }

        return null;
    }
}
