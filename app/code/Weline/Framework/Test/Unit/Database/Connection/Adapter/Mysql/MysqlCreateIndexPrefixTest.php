<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Connection\Adapter\Mysql;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Weline\Framework\Database\Connection\Adapter\Mysql\Table\Create;
use Weline\Framework\Database\Connection\Api\Sql\AbstractTable;

final class MysqlCreateIndexPrefixTest extends TestCase
{
    public function testVarcharCommentMentioningBlobDoesNotGetPrefix(): void
    {
        $create = new Create();
        $this->setProtected($create, 'fields', [
            'blob_key' => "`blob_key` varchar(128) NOT NULL COMMENT 'Shared blob key'",
        ]);

        self::assertSame(0, $this->prefixLength($create, 'blob_key'));
    }

    public function testTextColumnGetsDefaultPrefix(): void
    {
        $create = new Create();
        $this->setProtected($create, 'fields', [
            'body' => "`body` text NOT NULL COMMENT 'Body blob storage'",
        ]);

        self::assertSame(191, $this->prefixLength($create, 'body'));
    }

    public function testAddIndexSqlOmitsPrefixForVarcharBlobComment(): void
    {
        $create = new Create();
        $this->setProtected($create, 'fields', [
            'blob_key' => "`blob_key` varchar(128) NOT NULL COMMENT 'Shared blob key'",
        ]);
        $create->addIndex('INDEX', 'idx_blob_key', ['blob_key'], '', 'BTREE');

        $indexes = $this->getProtected($create, 'indexes');
        $sql = implode("\n", $indexes);
        self::assertStringContainsString('INDEX `idx_blob_key`(`blob_key`)', $sql);
        self::assertStringNotContainsString('`blob_key`(191)', $sql);
    }

    private function prefixLength(Create $create, string $column): int
    {
        $method = new ReflectionMethod(Create::class, 'mysqlTextIndexPrefixLength');
        $method->setAccessible(true);

        return (int)$method->invoke($create, $column);
    }

    private function setProtected(object $object, string $property, mixed $value): void
    {
        $prop = new ReflectionProperty(AbstractTable::class, $property);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
    }

    /** @return mixed */
    private function getProtected(object $object, string $property): mixed
    {
        $prop = new ReflectionProperty(AbstractTable::class, $property);
        $prop->setAccessible(true);

        return $prop->getValue($object);
    }
}
