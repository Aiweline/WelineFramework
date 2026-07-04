<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Table\Create;

final class PgsqlCreateTableTest extends TestCase
{
    public function testAutoIncrementWithoutPrimaryKeyDoesNotAddInlinePrimaryKey(): void
    {
        $create = new Create();

        $create->addColumn('id', 'int', 11, 'AUTO_INCREMENT', '');

        self::assertStringNotContainsString('PRIMARY KEY', $this->columnDefinition($create, 'id'));
        self::assertStringContainsString('"id" SERIAL', $this->columnDefinition($create, 'id'));
    }

    public function testAutoIncrementWithExplicitPrimaryKeyKeepsInlinePrimaryKey(): void
    {
        $create = new Create();

        $create->addColumn('id', 'int', 11, 'PRIMARY KEY AUTO_INCREMENT', '');

        self::assertStringContainsString('PRIMARY KEY', $this->columnDefinition($create, 'id'));
        self::assertStringContainsString('"id" SERIAL', $this->columnDefinition($create, 'id'));
    }

    private function columnDefinition(Create $create, string $column): string
    {
        $ref = new \ReflectionClass($create);
        $property = $ref->getParentClass()->getProperty('fields');
        $property->setAccessible(true);

        $fields = $property->getValue($create);

        return (string) ($fields[$column]['definition'] ?? '');
    }
}
