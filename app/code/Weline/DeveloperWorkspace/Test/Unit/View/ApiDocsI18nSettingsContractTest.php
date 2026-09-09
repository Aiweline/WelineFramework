<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * API online-test "语言、货币和环境" block must not reuse the section title as the
 * path/param mode legend, and locale options should prefer display_name.
 */
final class ApiDocsI18nSettingsContractTest extends TestCase
{
    /** @return list<string> */
    private function scriptPaths(): array
    {
        $moduleRoot = dirname(__DIR__, 3);
        $themeRoot = dirname($moduleRoot) . '/Theme';

        return [
            $themeRoot . '/view/statics/ui/pages/weline-developer-api.js',
            $moduleRoot . '/view/statics/js/api-docs.js',
        ];
    }

    public function testModeLegendUsesI18nModeNotSectionTitle(): void
    {
        foreach ($this->scriptPaths() as $path) {
            self::assertFileExists($path, $path);
            $src = (string)file_get_contents($path);
            self::assertStringContainsString("t('i18nMode', '切换方式')", $src, $path);
            self::assertDoesNotMatchRegularExpression(
                "/create\\('legend'[^)]*t\\('i18nSettings'\\)/",
                $src,
                $path
            );
            self::assertStringContainsString('display_name', $src, $path);
        }
    }

    public function testTemplateExposesI18nModeCopy(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Docs/api-manager.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("'i18nMode'", $src);
        self::assertStringContainsString('切换方式', $src);
    }
}
