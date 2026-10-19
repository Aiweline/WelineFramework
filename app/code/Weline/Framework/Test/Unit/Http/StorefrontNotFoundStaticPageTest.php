<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\State;
use Weline\Framework\Context;
use Weline\Framework\Http\StorefrontNotFoundStaticPage;
use Weline\Framework\Runtime\RequestContext;

final class StorefrontNotFoundStaticPageTest extends TestCase
{
    public function testResolveLangFromQueryAndPathOnly(): void
    {
        $languageMaps = new \ReflectionProperty(State::class, 'allowedLanguageCodeMapsByScope');
        $originalLanguageMaps = $languageMaps->getValue(null);
        $entered = false;
        if (Context::getCurrent() !== null) {
            try {
                RequestContext::cleanup();
            } catch (\Throwable) {
            }
            Context::leave();
        }
        Context::enter(new Context([
            'meta' => ['type' => 'request', 'mode' => 'test'],
            'route' => [
                'language' => 'zh_Hans_CN',
                'currency' => 'CNY',
                'website_id' => 0,
            ],
        ]));
        RequestContext::init();
        $entered = true;
        RequestContext::locale('zh_Hans_CN');
        $languageMaps->setValue(null, [
            '0' => ['zh_hans_cn' => true, 'en_us' => true],
        ]);
        try {
            self::assertSame('en_US', StorefrontNotFoundStaticPage::resolveLang('/', 'lang=en_US'));
            self::assertSame('en_US', StorefrontNotFoundStaticPage::resolveLang('/en_US/products'));
            // Path beats query when both are present.
            self::assertSame(
                'en_US',
                StorefrontNotFoundStaticPage::resolveLang('/en_US/products', 'lang=ja_JP')
            );
            // Language cookies are ignored (path/query/RequestContext only).
            RequestContext::locale('zh_Hans_CN');
            self::assertSame(
                'zh_Hans_CN',
                StorefrontNotFoundStaticPage::resolveLang('/', '', 'foo=bar; WELINE_USER_LANG=ja_JP')
            );
            self::assertSame('zh_Hans_CN', StorefrontNotFoundStaticPage::resolveLang('/products'));
            RequestContext::locale('en_US');
            self::assertSame('en_US', StorefrontNotFoundStaticPage::resolveLang('/products'));
            // Stripped path after Url::detectLanguage peel: RequestContext still
            // holds the visitor-facing path locale (not a parallel PATH_LANG bag).
            RequestContext::locale('zh_Hans_CN');
            self::assertSame('zh_Hans_CN', StorefrontNotFoundStaticPage::resolveLang('/ddsds'));
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
            $languageMaps->setValue(null, $originalLanguageMaps);
            if ($entered) {
                try {
                    RequestContext::cleanup();
                } catch (\Throwable) {
                }
                Context::leave();
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
