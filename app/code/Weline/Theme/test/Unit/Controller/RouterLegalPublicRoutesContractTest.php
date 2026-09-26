<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\FooterDefaultLinksHelper;

/**
 * Prefer app/code Theme over stale vendor/weline/module-theme when PHPUnit autoloads vendor first.
 *
 * Legal public paths resolve via Theme LayoutResolveObserver (path ↔ layout file 1:1),
 * not a hard-coded Router alias table.
 */
final class RouterLegalPublicRoutesContractTest extends TestCase
{
    public function testFooterLegalLinksHaveDiscoverableThemeLayouts(): void
    {
        $layoutsRoot = dirname(__DIR__, 3) . '/view/theme/frontend/layouts';
        self::assertDirectoryExists($layoutsRoot);

        foreach (FooterDefaultLinksHelper::legalLinks() as $link) {
            $path = trim((string)($link['url'] ?? ''), '/');
            self::assertNotSame('', $path, 'legal link url must not be empty');

            if (!str_contains($path, '/')) {
                $file = $layoutsRoot . '/' . $path . '/default.phtml';
            } else {
                [$layoutType, $layoutOption] = explode('/', $path, 2);
                $file = $layoutsRoot . '/' . $layoutType . '/' . $layoutOption . '.phtml';
            }

            self::assertFileExists(
                $file,
                'missing Theme layout for legal link /' . $path . ' (expected ' . $file . ')'
            );
        }

        self::assertFileExists($layoutsRoot . '/policy/accessibility.phtml');
        self::assertFileExists($layoutsRoot . '/policy/shipping.phtml');
        self::assertFileExists($layoutsRoot . '/error/default.phtml');
        self::assertFileExists($layoutsRoot . '/sitemap/default.phtml');
    }

    public function testPublicLayoutRegistersThemeModuleBeforeTranslatingMetadata(): void
    {
        $policyPath = dirname(__DIR__, 3) . '/Controller/Frontend/Policy.php';
        self::assertFileExists($policyPath);
        $source = (string)file_get_contents($policyPath);

        $scopePosition = strpos($source, "\$this->request->addModule('Weline_Theme');");
        // 公开路由标题的翻译入口是 Theme WidgetI18n::label（源串默认简体中文），
        // 不再走 __($title)，否则断言恒为 false 而失去「注册先于翻译」的守护意义。
        $translationPosition = strpos($source, 'WidgetI18n::label($title)');

        self::assertNotFalse($scopePosition, 'Theme request scope must be registered explicitly.');
        self::assertNotFalse($translationPosition, 'Public route title must remain translatable.');
        self::assertLessThan(
            $translationPosition,
            $scopePosition,
            'Theme request scope must be registered before controller metadata is translated.'
        );

        self::assertStringContainsString("'shipping'", $source);
        self::assertStringContainsString("'accessibility'", $source);
        self::assertStringContainsString("'error' => ['default']", $source);
        self::assertStringContainsString("'sitemap' => ['default']", $source);
    }
}
