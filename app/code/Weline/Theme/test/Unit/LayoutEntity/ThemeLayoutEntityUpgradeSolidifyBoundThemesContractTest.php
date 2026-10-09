<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;

/**
 * System upgrade solidify must not rebake every installed theme —
 * only website-bound (+ backend + registered Default); default = published + draft.
 */
final class ThemeLayoutEntityUpgradeSolidifyBoundThemesContractTest extends TestCase
{
    public function testSolidifyAllThemesUsesApplicationBoundThemesNotAllInstalled(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityUpgradeSolidifyService.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('ThemeApplicationUsageService', $src);
        self::assertStringContainsString('themesForDefaultUpgrade', $src);
        self::assertStringContainsString('主题布局预固化仅处理站点已绑定主题', $src);
        self::assertStringContainsString('pipeline=global_flat', $src);
        self::assertStringContainsString('versions=current+draft', $src);
        self::assertStringContainsString('finishProgressLine', $src);
        self::assertStringContainsString('rebakeThemesAfterInjectionCollect', $src);
        self::assertStringContainsString('$themeIds', $src);
        self::assertStringNotContainsString('rebakeAfterInjectionCollect(null,', $src);
        // solidifyAllThemes: collect theme ids then one flatten call (not per-theme rebake).
        self::assertSame(1, preg_match(
            '/function solidifyAllThemes\b[\s\S]*?\n    public function solidifyTheme\b/m',
            $src,
            $allThemesBlock,
        ));
        $allThemesBody = (string)($allThemesBlock[0] ?? '');
        self::assertStringContainsString('rebakeThemesAfterInjectionCollect', $allThemesBody);
        self::assertStringNotContainsString('rebakeAfterInjectionCollect(', $allThemesBody);
        // CLI occupancy bar lives on the pipeline; upgrade passes null (no single-theme progress bar).
        self::assertStringContainsString("null,\n            true,", $allThemesBody);
        self::assertStringNotContainsString('$this->progress(', $allThemesBody);
        self::assertStringContainsString("'all_versions'", $src);
        self::assertStringContainsString('bakeOptions', $src);
    }
}
