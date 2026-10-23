<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Release\Api;

/** 物理 readiness：异步索引/约束/健康门禁。 */
interface PhysicalReadinessInterface
{
    /**
     * 检查发布涉及的物理对象是否就绪。
     *
     * @param list<string> $scopeKeys
     * @return array<string, mixed>
     */
    public function check(string $releaseId, array $scopeKeys = []): array;

    /**
     * 等待就绪；timeoutSeconds 为 0 表示不等待。
     *
     * @return array<string, mixed>
     */
    public function wait(string $releaseId, int $timeoutSeconds = 0): array;
}
