<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Api;

/**
 * Optional adapter capability for non-blocking index builds.
 *
 * PostgreSQL implements this with CREATE/DROP INDEX CONCURRENTLY and advisory
 * locks. Other adapters may omit it; the async applier then falls back to the
 * adapter's normal index DDL.
 */
interface ConcurrentIndexBuildInterface
{
    public function supportsConcurrentIndexBuild(): bool;

    /** @param array{name:string,columns:list<string>,type?:string,method?:string,comment?:string} $idx */
    public function buildAddIndexConcurrentlySql(string $table, array $idx): string;

    public function buildDropIndexConcurrentlySql(string $table, string $indexName): string;

    /**
     * Apply session-level build controls (application_name, lock_timeout,
     * statement_timeout, maintenance_work_mem, max_parallel_maintenance_workers).
     *
     * @param array<string,scalar> $options
     */
    public function configureConcurrentIndexBuild(array $options): void;

    public function resetConcurrentIndexBuild(): void;

    public function tryAcquireIndexBuildLock(string $table): bool;

    public function releaseIndexBuildLock(string $table): void;

    public function isIndexValid(string $table, string $indexName): bool;

    public function isIndexPresent(string $table, string $indexName): bool;

    public function dropInvalidIndex(string $table, string $indexName): bool;

    public function cancelIndexBuild(string $applicationName): bool;

    public function renameIndex(string $table, string $fromPhysicalName, string $toLogicalName): bool;

    /**
     * Snapshot of pg_stat_progress_create_index for one application_name.
     *
     * @return array<string,mixed>
     */
    public function indexBuildProgress(string $applicationName): array;
}
