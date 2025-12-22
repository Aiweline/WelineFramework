<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Api\Sql\Dialect;

use Weline\Framework\Database\Connection\Api\Sql\Query;

/**
 * 将 Query 维护的中间结构转换为具体数据库 SQL。
 */
interface DialectAdapterInterface
{
    public function compile(Query $query, string $action): string;
}

