<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;

/**
 * System upgrade solidify must not rebake every installed theme —
 * only website-bound (+ backend + registered Default) current formal versions.
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
        self::assertMatchesRegularExpression(
            '/rebakeAfterInjectionCollect\(\s*\$themeId[\s\S]*?\$this->progress\(\.\.\.\),\s*false/m',
            $src,
        );
        self::assertStringNotContainsString('rebakeAfterInjectionCollect(null,', $src);
    }
}
