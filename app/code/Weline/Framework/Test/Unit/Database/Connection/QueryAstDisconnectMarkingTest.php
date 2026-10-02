<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Connection;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Pool\ConnectionPool;

/**
 * 断连连接必须让连接池丢弃：Pgsql 适配器已在自己的执行路径上标记，MySQL
 * 适配器没有对应处理，于是已断开的连接会留在池中被反复取出复用，持续产生
 * 2006 "server has gone away"（生产上单次爆发 15870 条）。
 */
final class QueryAstDisconnectMarkingTest extends TestCase
{
    public function testMySqlGoneAwayMessageIsClassifiedAsDisconnect(): void
    {
        $exception = new \PDOException(
            'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'
        );

        self::assertTrue(
            ConnectionPool::isDisconnectException($exception),
            'MySQL 2006 必须被判定为断连，否则坏连接不会被丢弃',
        );
    }

    public function testSharedExecutePathMarksDisconnectedConnectionsUnhealthy(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 8)
            . '/app/code/Weline/Framework/Database/Connection/Api/Sql/QueryAst.php',
        );

        $start = \strpos($source, 'private function executePreparedStatement(');
        self::assertIsInt($start, '未找到共享执行收口方法');
        $end = \strpos($source, "\n    public function fetch(", $start);
        self::assertIsInt($end);
        $method = \substr($source, $start, $end - $start);

        // 必须用共享的断连判定，而不是只依赖各适配器自觉。
        self::assertStringContainsString('ConnectionPool::isDisconnectException(', $method);
        self::assertStringContainsString('ConnectionPool::markConnectionUnhealthy(', $method);
        // 断连后必须丢弃已绑定到旧连接的语句，下一次在新连接上重新 prepare。
        self::assertStringContainsString('$this->PDOStatement = null;', $method);
    }
}
