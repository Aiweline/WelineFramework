<?php
declare(strict_types=1);

namespace Weline\Server\Service\Protocol\LongLived;

class WebSocketMatcher implements MatcherInterface
{
    public function match(string $rawRequest): ?array
    {
        $hasUpgradeWebsocket = \preg_match('/\r\nUpgrade:\s*websocket\s*(?:\r\n|$)/i', $rawRequest) === 1;
        $hasConnectionUpgrade = \preg_match('/\r\nConnection:\s*[^\r\n]*\bupgrade\b[^\r\n]*(?:\r\n|$)/i', $rawRequest) === 1;
        $hasWsKey = \preg_match('/\r\nSec-WebSocket-Key:\s*[^\r\n]+/i', $rawRequest) === 1;

        // RFC 6455：须同时有 Upgrade: websocket，且至少有 Sec-WebSocket-Key 或 Connection: Upgrade。
        // 单独 Connection: upgrade（如 nginx 误配反代头）不应占长连接槽位。
        if ($hasUpgradeWebsocket && ($hasWsKey || $hasConnectionUpgrade)) {
            return ['is_long_lived' => true, 'layer' => 'layer-1-header', 'protocol' => 'websocket'];
        }

        return null;
    }
}

