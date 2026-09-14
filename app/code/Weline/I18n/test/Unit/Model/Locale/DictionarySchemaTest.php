<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Model\Locale;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\I18n\Model\Locale\Dictionary;

final class DictionarySchemaTest extends TestCase
{
    public function testLocaleModuleDictionaryIndexIsDeclaredAlongsideLocaleIndex(): void
    {
        // Exercise the actual declaration parser without constructing a database-backed Model.
        $parseIndexes = new \ReflectionMethod(SchemaParser::class, 'parseIndexes');
        $definitions = $parseIndexes->invoke(new SchemaParser(), new \ReflectionClass(Dictionary::class));
        $indexes = [];
        foreach ($definitions as $index) {
            $indexes[$index->name] = $index;
        }

        self::assertArrayHasKey('idx_locale_module', $indexes);
        self::assertSame(['locale_code', 'source_module'], $indexes['idx_locale_module']->columns);
        self::assertSame('DEFAULT', $indexes['idx_locale_module']->type);
        self::assertSame('BTREE', $indexes['idx_locale_module']->method);
        self::assertArrayHasKey('idx_code', $indexes);
        self::assertSame(['locale_code'], $indexes['idx_code']->columns);
    }
}
