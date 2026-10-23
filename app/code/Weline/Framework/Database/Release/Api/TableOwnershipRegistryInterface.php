<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Release\Api;

/** 表所有权登记：一表一 owner，跨模块写入需显式授权。 */
interface TableOwnershipRegistryInterface
{
    /** 返回表的 owner 模块；未登记返回 null。 */
    public function ownerOf(string $scopeKey, string $tableName): ?string;

    /** 登记表 owner。 */
    public function register(string $scopeKey, string $tableName, string $ownerModule, string $source = 'model'): void;

    /** owner 不是给定模块时抛异常。 */
    public function assertWritable(string $scopeKey, string $tableName, string $moduleName): void;
}
