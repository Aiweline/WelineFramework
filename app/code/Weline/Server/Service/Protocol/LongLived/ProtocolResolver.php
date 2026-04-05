<?php
declare(strict_types=1);

namespace Weline\Server\Service\Protocol\LongLived;

/**
 * Worker 侧「长连/协议分层」检测（仅原始 HTTP 报文，先于框架路由）。
 *
 * 由 `worker.php` / `worker_ssl.php` 在读完请求头后调用；返回值驱动长连槽位、select 读集、Fiber 闲置策略等。
 * 各 matcher 的字段含义及 `protocol` 在 HTTP/TLS Worker 中的差异见 {@see SseMatcher} 等实现类注释。
 */
class ProtocolResolver
{
    /** @var MatcherInterface[] */
    private array $matchers;

    public function __construct(?array $matchers = null)
    {
        $this->matchers = $matchers ?? [
            new SseMatcher(),
            new WebSocketMatcher(),
            new WebRtcSignalingMatcher(),
            new PathFallbackMatcher(),
        ];
    }

    /**
     * @return array{is_long_lived: bool, layer: string, protocol: string} protocol 为 sse|websocket|…|http 等，具体由命中 matcher 决定
     */
    public function detect(string $rawRequest): array
    {
        foreach ($this->matchers as $matcher) {
            $matched = $matcher->match($rawRequest);
            if ($matched !== null) {
                return $matched;
            }
        }
        return ['is_long_lived' => false, 'layer' => 'none', 'protocol' => 'http'];
    }
}

