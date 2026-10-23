<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Release\Api;

/** 不可变发布清单：代码版本、schema 清单校验和、快照/备份引用与回滚策略。 */
interface ReleaseManifestInterface
{
    /** 全局唯一发布标识。 */
    public function releaseId(): string;

    /** 单调递增的 schema 世代。 */
    public function schemaEpoch(): int;

    public function gitCommit(): string;

    public function gitTag(): ?string;

    public function deployVersion(): string;

    /** @return array<string, array<string, mixed>> 模块名 => 版本/校验和/检查点 */
    public function modules(): array;

    public function schemaManifestChecksum(): string;

    public function snapshotId(): ?string;

    public function backupSetId(): ?string;

    /** 回滚策略：L0|L1|L2|L3。 */
    public function rollbackPolicy(): string;

    /** @return array<string, mixed> */
    public function toArray(): array;
}
