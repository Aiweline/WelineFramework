<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;

/**
 * Source-string contract: ThemeScopeVersion has no page_type; owns chrome_payload_json.
 */
final class ThemeScopeVersionModelContractTest extends TestCase
{
    public function testModelHasChromePayloadAndNoPageType(): void
    {
        $path = \dirname(__DIR__, 3) . '/Model/ThemeScopeVersion.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);

        self::assertStringContainsString('final class ThemeScopeVersion', $src);
        self::assertStringContainsString("schema_table = 'theme_scope_version'", $src);
        self::assertStringContainsString("schema_fields_CHROME_PAYLOAD_JSON = 'chrome_payload_json'", $src);
        self::assertStringContainsString("schema_fields_STRUCTURE_KEY = 'structure_key'", $src);
        self::assertStringContainsString('uk_theme_scope_version_number', $src);
        self::assertStringContainsString('idx_theme_scope_version_current', $src);
        self::assertStringContainsString('idx_theme_scope_version_published', $src);

        self::assertStringNotContainsString('schema_fields_PAGE_TYPE', $src);
        self::assertStringNotContainsString("= 'page_type'", $src);
        self::assertDoesNotMatchRegularExpression("/#\\[Col[^\\]]*page_type/i", $src);
    }
}
