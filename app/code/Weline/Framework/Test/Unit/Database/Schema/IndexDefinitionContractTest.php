<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Schema;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Schema\IndexDefinition;
use Weline\Framework\Database\Schema\IndexDefinitionContract;

final class IndexDefinitionContractTest extends TestCase
{
    public function testAssertDeclaredNamesRejectsCaseInsensitiveCollision(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IndexDefinitionContract::assertDeclaredNames([
            new IndexDefinition(name: 'idx_foo', columns: ['a']),
            new IndexDefinition(name: 'IDX_FOO', columns: ['b']),
        ]);
    }

    public function testResolveImplicitNameAvoidsReservedIdentities(): void
    {
        $reserved = ['uk_email' => true];
        $name = IndexDefinitionContract::resolveImplicitName('demo', 'email', $reserved);
        self::assertNotSame('uk_email', $name);
        self::assertStringStartsWith('uk_email_', $name);
    }

    public function testEqualsIgnoresMethodOnSqliteNonUniqueIndexes(): void
    {
        $declared = new IndexDefinition(name: 'idx_a', columns: ['a'], type: 'INDEX', method: 'BTREE');
        $actual = new IndexDefinition(name: 'idx_a', columns: ['a'], type: 'DEFAULT', method: 'HASH');
        self::assertTrue(IndexDefinitionContract::equals($declared, $actual, 'sqlite'));
        self::assertFalse(IndexDefinitionContract::equals($declared, $actual, 'mysql'));
    }
}
