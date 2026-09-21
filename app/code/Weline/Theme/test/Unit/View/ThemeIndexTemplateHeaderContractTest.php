<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 主题列表页由布局页头承载标题/面包屑，内容区不得再画重复页头。
 */
final class ThemeIndexTemplateHeaderContractTest extends TestCase
{
    public function testIndexTemplateDoesNotDuplicatePageHeading(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/backend/index.phtml';
        self::assertFileExists($path);
        $html = (string)file_get_contents($path);

        self::assertStringNotContainsString('w-backend-page__heading', $html);
        self::assertStringNotContainsString('w-backend-page__title', $html);
        self::assertDoesNotMatchRegularExpression('/<main\\b/', $html);
        self::assertStringNotContainsString("Weline_Component::message.phtml", $html);
        self::assertStringContainsString('data-testid="theme-manage"', $html);
        self::assertStringContainsString('data-theme-manage', $html);
        self::assertStringContainsString('weline-theme-manage__hint', $html);
        self::assertStringContainsString('选择前台与后台各自激活的主题。', $html);
        self::assertStringContainsString('weline-theme-tab-frontend', $html);
        self::assertStringContainsString('weline-theme-tab-backend', $html);
    }
}
