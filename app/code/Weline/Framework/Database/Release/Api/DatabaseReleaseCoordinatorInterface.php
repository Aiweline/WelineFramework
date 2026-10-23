<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Release\Api;

/** 发布编排入口：检查、规划、执行与回滚。 */
interface DatabaseReleaseCoordinatorInterface
{
    /**
     * 检查发布与当前库状态。
     *
     * @return array<string, mixed>
     */
    public function inspect(string $releaseId): array;

    /**
     * 生成执行计划，不落库。
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function plan(string $releaseId, array $options = []): array;

    /**
     * 执行发布。
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function apply(string $releaseId, array $options = []): array;

    /**
     * 回滚到目标发布。
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function rollback(string $releaseId, string $targetReleaseId, array $options = []): array;
}
