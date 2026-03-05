<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema;

/**
 * Schema 扩展点接口：供模块通过 extends 提供声明式表结构，由 SchemaDiffStage 统一 diff 与执行。
 * 可选实现：Eav 等动态表可由此提供 TableSchema 列表，替代或补充 #[Col] 注解。
 */
interface SchemaProviderInterface
{
    /**
     * 返回本提供者声明的表结构列表，供 SchemaDiff 与库表比较并执行 DDL。
     *
     * @return list<TableSchema>
     */
    public function getTableSchemas(): array;
}
