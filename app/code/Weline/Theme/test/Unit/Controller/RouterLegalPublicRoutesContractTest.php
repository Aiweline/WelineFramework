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
        self::assertStringContainsString(
            "'ads-preferences' => ['layout_type' => 'policy', 'layout_option' => 'ads-preferences'",
            $source
        );
        self::assertStringContainsString(
            "'policy/ads-preferences' => ['layout_type' => 'policy', 'layout_option' => 'ads-preferences'",
            $source
        );
    }
}
