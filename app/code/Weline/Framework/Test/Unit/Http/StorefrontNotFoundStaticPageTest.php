<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Env\WelineEnv;
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
            WelineEnv::setServer('WELINE_USER_LANG', 'en_US', 'storefront-not-found-test');
            self::assertSame('en_US', StorefrontNotFoundStaticPage::resolveLang('/products'));
            WelineEnv::removeServer('WELINE_USER_LANG');
            self::assertSame(
                'en_US',
                StorefrontNotFoundStaticPage::resolveLang('/pub/errors/storefront-not-found/en_US.html')
            );
            self::assertSame(
                'en_US',
                StorefrontNotFoundStaticPage::resolveLang('/pub/errors/storefront-not-found/shop/en_US.html')
            );
            self::assertSame(
                '/pub/errors/storefront-not-found/en_US.html',
                StorefrontNotFoundStaticPage::publicHtmlUrl('en_US')
            );
            self::assertSame(
                '/pub/errors/storefront-not-found/shop/en_US.html',
                StorefrontNotFoundStaticPage::publicHtmlUrl('en_US', 'shop')
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

        $path = StorefrontNotFoundStaticPage::staticFilePath('en_US', 'default');
        $flat = StorefrontNotFoundStaticPage::staticFilePath('en_US', '');
        if (!\is_file($path) && !\is_file($flat)) {
            // Full publishAll is heavy (theme×locale); skip when snapshots absent.
            self::markTestSkipped('storefront 404 snapshot not published yet');
        }

        $html = StorefrontNotFoundStaticPage::loadHtml(null, '/en_US/', 'lang=en_US', '', '127.0.0.1');
        self::assertIsString($html);
        self::assertNotSame('', (string)$html);
    }
}
