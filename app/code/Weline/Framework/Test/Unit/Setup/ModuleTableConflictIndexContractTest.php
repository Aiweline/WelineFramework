<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Setup\Model\ModuleTable;

final class ModuleTableConflictIndexContractTest extends TestCase
{
    public function testDeclaresTheCompositeUniqueConflictTarget(): void
    {
        $indexes = array_map(
            static fn (\ReflectionAttribute $attribute): Index => $attribute->newInstance(),
            (new \ReflectionClass(ModuleTable::class))->getAttributes(Index::class),
        );

        $hasConflictTarget = false;
        foreach ($indexes as $index) {
            if (
                strtoupper($index->type) === 'UNIQUE'
                && array_values((array) $index->columns) === ['name', 'model']
            ) {
                $hasConflictTarget = true;
                break;
            }
        }

        self::assertTrue($hasConflictTarget);
    }
}
