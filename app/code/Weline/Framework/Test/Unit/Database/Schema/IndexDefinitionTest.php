<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Schema;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Schema\IndexDefinition;

class IndexDefinitionTest extends TestCase
{
    public function testMisplacedBtreeTypeFallsBackToDefaultIndexType(): void
    {
        $definition = new IndexDefinition(
            name: 'idx_customer_id',
            columns: ['customer_id'],
            type: 'BTREE'
        );

        self::assertSame(TableInterface::index_type_DEFAULT, $definition->type);
        self::assertSame(TableInterface::index_method_BTREE, $definition->method);
    }

    public function testMisplacedHashTypeFallsBackToDefaultIndexType(): void
    {
        $definition = new IndexDefinition(
            name: 'idx_customer_email',
            columns: ['customer_email'],
            type: 'hash',
            method: ''
        );

        self::assertSame(TableInterface::index_type_DEFAULT, $definition->type);
        self::assertSame(TableInterface::index_method_HASH, $definition->method);
    }

    public function testExplicitIndexTypeIsPreserved(): void
    {
        $definition = new IndexDefinition(
            name: 'idx_card_number',
            columns: ['card_number'],
            type: 'unique'
        );

        self::assertSame(TableInterface::index_type_UNIQUE, $definition->type);
        self::assertSame(TableInterface::index_method_BTREE, $definition->method);
    }
}
