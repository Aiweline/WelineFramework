<?php

declare(strict_types=1);

namespace Weline\UrlManager\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;

/**
 * Prefer app/code UrlManager over stale vendor/weline/module-url-manager when PHPUnit autoloads vendor first.
 */
final class RouterRewriteThemeShellSkipTest extends TestCase
{
    public function testObserverSourceSkipsPageBuilderShellRewritesForThemeAliases(): void
    {
        $observerPath = dirname(__DIR__, 3) . '/Observer/RouterRewrite.php';
        self::assertFileExists($observerPath);
        $source = (string)file_get_contents($observerPath);

        self::assertStringContainsString('shouldSkipStalePageBuilderShellRewrite', $source);
        self::assertStringContainsString("pagebuilder/frontend/page", $source);
        self::assertStringContainsString('prefersShellPublicAlias', $source);
        self::assertStringContainsString('Weline\\Blog\\Controller\\Router', $source);
    }
}
