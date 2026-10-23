<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Release\Api;

/** 批量、幂等、可暂停恢复、可验证的数据迁移。 */
interface DataTransformInterface
{
    /** 全局唯一 transform ID。 */
    public function id(): string;

    public function scope(): SchemaScopeInterface;

    public function fromVersion(): string;

    public function toVersion(): string;

    public function table(): string;

    /** 预估影响行数。 */
    public function estimateRows(): int;

    /** @return array<string, mixed> 源/目标列与策略 */
    public function plan(): array;

    /**
     * 执行一批，返回下一游标与批结果。
     *
     * @param array<string, mixed> $cursor
     * @return array<string, mixed>
     */
    public function applyBatch(array $cursor, int $limit): array;

    /**
     * 校验已处理数据。
     *
     * @param array<string, mixed> $cursor
     * @return array<string, mixed>
     */
    public function verify(array $cursor): array;

    /** 无逆变换时必须 requiresSnapshot() 返回 true。 */
    public function inverse(): ?self;

    public function requiresSnapshot(): bool;

    public function batchSize(): int;

    public function throttleMs(): int;
}
