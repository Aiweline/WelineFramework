<?php

declare(strict_types=1);

namespace Weline\Compare\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class EavManagerAttributeFieldContractTest extends TestCase
{
    public function testThemeManagerExposesSearchableAndCompareMode(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Theme/view/statics/ui/pages/weline-eav-manager.js',
        );
        self::assertStringContainsString('frontend_is_searchable', $source);
        self::assertStringContainsString('compare_mode', $source);
        self::assertStringContainsString('compareModeField', $source);
    }

    public function testNativeManagerExposesSearchableAndCompareMode(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Eav/view/statics/js/eav-manager-native.js',
        );
        self::assertStringContainsString('frontend_is_searchable', $source);
        self::assertStringContainsString('compare_mode', $source);
    }

    public function testLegacyManagerExposesSearchableAndCompareMode(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Eav/view/statics/js/eav-manager/eav-manager.js',
        );
        self::assertStringContainsString('frontend_is_searchable', $source);
        self::assertStringContainsString('compare_mode', $source);
    }

    public function testAttributeFormTemplateExposesCompareMode(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Eav/view/templates/Backend/Attribute/form.phtml',
        );
        self::assertStringContainsString('compare_mode', $source);
        self::assertStringContainsString('frontend_is_searchable', $source);
        self::assertStringContainsString('frontend_is_filterable', $source);
    }
}
