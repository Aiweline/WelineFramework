<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Greenfield 另案 debt-virtual-layout-drop phase-1:
 * theme_virtual_layout* asset APIs fail-closed after DROP; SystemConfig selection stays.
 */
final class GreenfieldVirtualLayoutAssetFailClosedContractTest extends TestCase
{
    public function testVirtualLayoutAssetApisAreFailClosedAfterDrop(): void
    {
        $servicePath = dirname(__DIR__, 2) . '/Service/ThemeVirtualLayoutService.php';
        $migrationPath = dirname(__DIR__, 2) . '/Setup/Db/Migration/drop_legacy_theme_layout_20260831-v2.2.0.php';
        $service = (string)file_get_contents($servicePath);
        $migration = (string)file_get_contents($migrationPath);

        self::assertStringContainsString('ThemeVirtualLayout::schema_table', $migration);
        self::assertStringContainsString('ThemeVirtualLayoutVersion::schema_table', $migration);
        self::assertStringContainsString('function isVirtualLayoutAssetAvailable', $service);
        self::assertStringContainsString('theme_virtual_layout_missing', $service);
        self::assertStringContainsString("virtualLayoutAssetMissingPayload('save_source_version')", $service);
        self::assertStringContainsString("virtualLayoutAssetMissingPayload('copy_virtual_layout_identity')", $service);
        self::assertStringContainsString("virtualLayoutAssetMissingPayload('rollback_published_version')", $service);
        self::assertStringContainsString('Selection APIs use SystemConfig and stay available', $service);

        $controllerPath = dirname(__DIR__, 2) . '/Controller/Backend/VirtualTheme.php';
        $controller = (string)file_get_contents($controllerPath);
        self::assertStringContainsString('function virtualLayoutAssetUnavailableResponse', $controller);
        self::assertStringContainsString("'virtual_layout_asset_available'", $controller);
        self::assertStringContainsString('isVirtualLayoutAssetAvailable()', $controller);

        foreach ([
            dirname(__DIR__, 2) . '/view/statics/js/theme-editor.js',
            dirname(__DIR__, 2) . '/view/statics/ui/pages/weline-theme-editor.js',
        ] as $jsPath) {
            $js = (string)file_get_contents($jsPath);
            self::assertStringContainsString('function ensureVirtualLayoutAssetAvailable', $js, $jsPath);
            self::assertStringContainsString('virtual_layout_asset_available', $js, $jsPath);
        }

        foreach (['saveLayoutSelection', 'deleteLayoutSelection', 'resolveLayoutSelection'] as $method) {
            self::assertStringNotContainsString(
                'virtualLayoutAssetTableExists',
                $this->extractPublicMethodBody($service, $method),
                $method . ' must keep SystemConfig selection path ungated'
            );
        }
    }

    private function extractPublicMethodBody(string $source, string $method): string
    {
        $pattern = '/public function ' . preg_quote($method, '/')
            . '\([\s\S]*?(?=\n    public function |\n    private function |\n}\s*$)/';
        self::assertSame(1, preg_match($pattern, $source, $matches), 'method not found: ' . $method);

        return $matches[0];
    }
}
