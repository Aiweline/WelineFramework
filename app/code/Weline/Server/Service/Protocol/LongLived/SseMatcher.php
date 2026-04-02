<?php
declare(strict_types=1);

namespace Weline\Server\Service\Protocol\LongLived;

/**
 * SSE 协议匹配器
 *
 * 注意：仅凭 Accept: text/event-stream 头无法准确判断是否为 SSE 请求，
 * 因为浏览器在某些场景下会自动添加该头（如 iframe 加载包含 EventSource 的页面）。
 *
 * 真正的 SSE 判断应该在应用层（控制器调用 SseWriter::start()）完成。
 * 此匹配器仅用于 Worker 层面的初步检测，避免对明确的 SSE 连接做错误处理。
 */
class SseMatcher implements MatcherInterface
{
    public function match(string $rawRequest): ?array
    {
        // SSE 协议的判断不能仅依赖 Accept 头，因为：
        // 1. 浏览器可能在非 SSE 请求中也发送 Accept: text/event-stream
        // 2. 真正的 SSE 响应由控制器通过 SseWriter::start() 决定
        //
        // 因此，此匹配器返回 null，让请求按普通 HTTP 处理。
        // 如果控制器调用了 SseWriter::start()，会在响应阶段自动发送 SSE 头。
        return null;
    }
}

