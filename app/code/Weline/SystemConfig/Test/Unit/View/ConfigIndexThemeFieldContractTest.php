<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ConfigIndexThemeFieldContractTest extends TestCase
{
    public function testConfigCenterUsesThemeFieldStructureForFiltersAndValues(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/backend/config/index.phtml';
        self::assertFileExists($path);
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('w-system-config__filters-disclosure', $content);
        self::assertStringContainsString('data-w-system-config-inherit-toggle', $content);
        self::assertStringContainsString('$adapterScopeParams', $content);
        self::assertStringContainsString('array_merge($routeParams, $queryParams)', $content);
        self::assertStringContainsString('emitFlashToast', $content);
        self::assertStringContainsString('adapterBlocked', $content);
        self::assertStringContainsString('w-system-config__filters-sticky', $content);
        self::assertStringContainsString('w-system-config__group-disclosure', $content);
        self::assertStringContainsString('data-w-disclosure-trigger', $content);
        self::assertStringContainsString('w-system-config__field-current', $content);
        self::assertStringNotContainsString('<th><lang>Scope</lang></th>', $content);
        self::assertStringContainsString('w-system-config__filters', $content);
        self::assertStringContainsString('Weline_Component::form/search-bar.phtml', $content);
        self::assertStringContainsString('$client_mode = true', $content);
        self::assertStringContainsString('wsc-search-empty', $content);
        self::assertStringContainsString('weline-system-config.js', $content);
        self::assertStringContainsString('class="w-field__control"', $content);
        self::assertStringNotContainsString('w-system-config__filter-submit', $content);
        self::assertStringNotContainsString('w-search-inline__control', $content);
        self::assertStringContainsString('data-testid="system-config-version-entry"', $content);
        self::assertStringContainsString('data-testid="system-config-version-panel"', $content);
        self::assertStringContainsString('data-wsc-version-toggle', $content);
        self::assertStringContainsString('id="wsc-version-panel"', $content);
        self::assertStringContainsString('<lang>版本管理</lang>', $content);
        self::assertStringNotContainsString('<lang>最近版本</lang>', $content);
        $entryPos = strpos($content, 'data-testid="system-config-version-entry"');
        $panelPos = strpos($content, 'id="wsc-version-panel"');
        $heroPos = strpos($content, 'w-system-config__hero');
        $filtersPos = strpos($content, 'w-system-config__filters-sticky');
        self::assertNotFalse($entryPos);
        self::assertNotFalse($panelPos);
        self::assertNotFalse($heroPos);
        self::assertNotFalse($filtersPos);
        self::assertGreaterThan($heroPos, $entryPos);
        self::assertGreaterThan($entryPos, $panelPos);
        self::assertLessThan($filtersPos, $panelPos, '版本管理面板必须在顶部 hero 与筛选区之间，不得追加在底部');
        self::assertStringNotContainsString("w-system-config__version-panel", $content);

    }
}
