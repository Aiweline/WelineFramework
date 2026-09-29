<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/** 运行时边缘 HTML/API 缓存失效；具体缓存路径和网关生命周期由提供者拥有。 */
interface RuntimeEdgeCacheInvalidatorInterface
{
    /**
     * 主机范围失效，不承诺 URL 精确删除。operationId 在相同主机集合上持久幂等。
     * @param list<string> $hosts 无协议、端口、路径的主机名
     * @return array{success:bool,completed:bool,applicable:bool,operation_id:string,
     * backend:string,granularity:string,hosts:list<string>,generation_by_host:array<string,int>,
     * reason:string,error_code:string,message:string}
     */
    public function invalidateHosts(array $hosts, string $operationId): array;
}
