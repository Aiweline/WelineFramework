<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\StorefrontNotFoundStaticPage;

final class StorefrontNotFoundStaticPageTest extends TestCase
{
    public function testResolveLangFromQueryAndPathOnly(): void
    {
        $previousMirror = $_SERVER['WELINE_USER_LANG'] ?? null;
        unset($_SERVER['WELINE_USER_LANG']);
        try {
            self::assertSame('en_US', StorefrontNotFoundStaticPage::resolveLang('/', 'lang=en_US'));
            self::assertSame('en_US', StorefrontNotFoundStaticPage::resolveLang('/en_US/products'));
            // Path beats query when both are present.
            self::assertSame(
                'en_US',
                StorefrontNotFoundStaticPage::resolveLang('/en_US/products', 'lang=ja_JP')
            );
            // Language cookies are ignored (path/query/request-server mirror only).
            self::assertSame(
                'zh_Hans_CN',
                StorefrontNotFoundStaticPage::resolveLang('/', '', 'foo=bar; WELINE_USER_LANG=ja_JP')
            );
            self::assertSame('zh_Hans_CN', StorefrontNotFoundStaticPage::resolveLang('/products'));
            $_SERVER['WELINE_USER_LANG'] = 'en_US';
            self::assertSame('en_US', StorefrontNotFoundStaticPage::resolveLang('/products'));
            unset($_SERVER['WELINE_USER_LANG']);
            self::assertSame(
                'en_US',
                StorefrontNotFoundStaticPage::resolveLang('/pub/errors/storefront-not-found/en_US.html')
            );
            self::assertSame(
                '/pub/errors/storefront-not-found/en_US.html',
                StorefrontNotFoundStaticPage::publicHtmlUrl('en_US')
            );
        } finally {
            if ($previousMirror === null) {
                unset($_SERVER['WELINE_USER_LANG']);
            } else {
                $_SERVER['WELINE_USER_LANG'] = $previousMirror;
            }
        }
    }

    public function testLoadHtmlReadsPublishedSnapshot(): void
    {
        if (!\defined('BP')) {
            self::markTestSkipped('BP not defined');
        }

        if (!\class_exists(\Weline\Theme\Service\StorefrontNotFoundStaticGenerator::class)) {
            self::markTestSkipped('Theme module unavailable');
        }

        $generator = \Weline\Framework\Manager\ObjectManager::getInstance(
            \Weline\Theme\Service\StorefrontNotFoundStaticGenerator::class
        );
        $generator->publishAll();

        $html = StorefrontNotFoundStaticPage::loadHtml(null, '/en_US/', 'lang=en_US');
        self::assertIsString($html);
        self::assertStringContainsString('data-testid="storefront-not-found-page"', (string)$html);
    }
}
