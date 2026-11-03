<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Cdn;

/**
 * purge 全链路观测：影响判定 → 计划 → 批次执行 → 预算 → 预热，各阶段都留一条结构化记录。
 *
 * 实现可以是日志、指标、或者测试里的内存收集器；这里只约定字段，不绑定出口。
 */
interface PurgeObserverInterface
{
    /**
     * @param array{stage:string,zone?:string,mode?:string,reason?:string,count?:int,extra?:array<string,mixed>} $event
     */
    public function record(array $event): void;
}
