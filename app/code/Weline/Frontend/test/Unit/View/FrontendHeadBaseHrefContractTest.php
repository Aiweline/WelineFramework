<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Storefront head must expose a locale-aware &lt;base href&gt; from live
 * getFrontendUrl('/') so relative widget links inherit currency/lang prefix,
 * and must not compile-bake @url{'/'} (TLS edge vs http website_url freeze).
 */
final class FrontendHeadBaseHrefContractTest extends TestCase
{
    public function testPublicHeadDeclaresRuntimeFrontendUrlBaseHref(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/public/head.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString("getFrontendUrl('/')", $source);
        self::assertStringContainsString('<base href="<?=', $source);
        self::assertDoesNotMatchRegularExpression(
            '/<base\s+href="@url\{\'\/\'\}"/',
            $source,
            'document base must not compile-bake @url root'
        );
        self::assertLessThan(
            (int)strpos($source, '<meta charset="UTF-8">'),
            (int)strpos($source, '<base href="<?='),
            'base must precede charset so relative resolution is established early'
        );
    }
}
