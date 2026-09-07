<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\FooterDefaultLinksHelper;

/**
 * Prefer app/code Theme over stale vendor/weline/module-theme when PHPUnit autoloads vendor first.
 */
final class RouterLegalPublicRoutesContractTest extends TestCase
{
    public function testFooterLegalLinksResolveInDefaultPublicRouteMapSource(): void
    {
        $routerPath = dirname(__DIR__, 3) . '/Controller/Router.php';
        self::assertFileExists($routerPath);
        $source = (string)file_get_contents($routerPath);

        foreach (FooterDefaultLinksHelper::legalLinks() as $link) {
            $path = trim((string)($link['url'] ?? ''), '/');
            self::assertNotSame('', $path, 'legal link url must not be empty');
            self::assertStringContainsString(
                "'" . $path . "' =>",
                $source,
                'missing public route for /' . $path . ' in app/code Router.php'
            );
        }

        self::assertStringContainsString(
            "'cookies' => ['layout_type' => 'policy', 'layout_option' => 'cookie'",
            $source
        );
        self::assertStringNotContainsString(
            "'ads-preferences' =>",
            $source
        );
        self::assertStringNotContainsString(
            "'policy/ads-preferences' =>",
            $source
        );
    }

    public function testPublicLayoutRegistersThemeModuleBeforeTranslatingMetadata(): void
    {
        $policyPath = dirname(__DIR__, 3) . '/Controller/Frontend/Policy.php';
        self::assertFileExists($policyPath);
        $source = (string)file_get_contents($policyPath);

        $scopePosition = strpos($source, "\$this->request->addModule('Weline_Theme');");
        $translationPosition = strpos($source, '__($title)');

        self::assertNotFalse($scopePosition, 'Theme request scope must be registered explicitly.');
        self::assertNotFalse($translationPosition, 'Public route title must remain translatable.');
        self::assertLessThan(
            $translationPosition,
            $scopePosition,
            'Theme request scope must be registered before controller metadata is translated.'
        );
    }
}
