<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Release\Api;

/** 发布级备份目录：备份集与对象（表/快照/日志）的登记与检索。 */
interface BackupCatalogInterface
{
    /**
     * 登记备份集。
     *
     * @param array<string, mixed> $set
     * @return int 备份集 ID
     */
    public function createSet(array $set): int;

    /**
     * 登记备份对象。
     *
     * @param array<string, mixed> $object
     * @return int 备份对象 ID
     */
    public function addObject(int $setId, array $object): int;

    /**
     * 按发布与作用域查找备份集。
     *
     * @return array<string, mixed>|null
     */
    public function findSet(string $releaseId, ?string $scopeKey = null): ?array;

    /**
     * 列出备份集下的对象。
     *
     * @param array<string, mixed> $filter
     * @return list<array<string, mixed>>
     */
    public function objects(int $setId, array $filter = []): array;
}
