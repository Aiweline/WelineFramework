<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Storefront head must expose locale-aware &lt;base href&gt; so relative widget links
 * (promotion/deals) inherit currency/lang prefix like backend @backend-url base.
 */
final class FrontendHeadBaseHrefContractTest extends TestCase
{
    public function testPublicHeadDeclaresUrlTagBaseHref(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/public/head.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString("<base href=\"@url{'/'}\">", $source);
        self::assertLessThan(
            (int)strpos($source, '<meta charset="UTF-8">'),
            (int)strpos($source, "<base href=\"@url{'/'}\">"),
            'base must precede charset so relative resolution is established early'
        );
    }
}
