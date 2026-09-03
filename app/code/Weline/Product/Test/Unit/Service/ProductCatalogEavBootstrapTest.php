<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class ProductCatalogEavBootstrapTest extends TestCase
{
    public function testHanfuOptionIdentityUsesNormalizedValueBeforeGeneratedCode(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ProductCatalogEavBootstrap.php',
        );

        $valueLookup = strpos($source, '$existingOptions = $option->clearData()');
        $codeLookup = strpos($source, '->where(Option::schema_fields_code, $code)');

        self::assertNotFalse($valueLookup);
        self::assertNotFalse($codeLookup);
        self::assertLessThan($codeLookup, $valueLookup);
        self::assertStringContainsString('$this->normalizeOptionLabel(', $source);
        self::assertStringContainsString(
            "->order('main_table.' . Option::schema_fields_option_id)",
            $source,
        );
    }
}
