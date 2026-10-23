<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Release\Api;

/** 整库一致性快照与恢复。 */
interface DatabaseSnapshotInterface
{
    /**
     * 为发布创建快照。
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed> 快照元数据
     */
    public function create(string $releaseId, SchemaScopeInterface $scope, array $options = []): array;

    /**
     * 恢复快照。
     *
     * @param array<string, mixed> $options
     */
    public function restore(string $snapshotId, array $options = []): void;

    /** 校验快照完整性。 */
    public function verify(string $snapshotId): bool;
}
