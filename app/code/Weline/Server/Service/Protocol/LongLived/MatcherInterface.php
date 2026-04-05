<?php
declare(strict_types=1);

namespace Weline\Server\Service\Protocol\LongLived;

interface MatcherInterface
{
    /**
     * @return array{is_long_lived: bool, layer: string, protocol: string}|null 未命中则 null；protocol 供 Worker 分支使用（HTTP/TLS 消费面可能不同，见各 matcher 注释）
     */
    public function match(string $rawRequest): ?array;
}

