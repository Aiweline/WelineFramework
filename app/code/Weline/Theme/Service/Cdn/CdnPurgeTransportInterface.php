<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Cdn;

/**
 * purge 请求的传输层。真实实现走 HTTP；测试里换成 fake，就能在无凭据、无网络的前提下
 * 验证「请求形状、鉴权头、成功判定」。
 */
interface CdnPurgeTransportInterface
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     *
     * @return array{status:int,body:string}
     */
    public function post(string $url, array $payload, array $headers): array;
}
