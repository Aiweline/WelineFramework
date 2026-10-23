<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Release\Api;

/** 隔离作用域：chain / backup / checkpoint / journal 必带的作用域键。 */
interface SchemaScopeInterface
{
    public function connectionName(): string;

    public function databaseName(): string;

    public function schemaName(): string;

    /** 未分片时返回空字符串。 */
    public function shardKey(): string;

    /** 未启用多租户时返回空字符串。 */
    public function tenantKey(): string;

    /** 稳定作用域键，用于关联账本、备份与检查点。 */
    public function key(): string;
}
