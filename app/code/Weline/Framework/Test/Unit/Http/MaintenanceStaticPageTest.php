<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\MaintenanceStaticPage;

final class MaintenanceStaticPageTest extends TestCase
{
    public function testResolveLangFromQueryAndPathOnly(): void
    {
        self::assertSame('en_US', MaintenanceStaticPage::resolveLang('/', 'lang=en_US'));
        self::assertSame('en_US', MaintenanceStaticPage::resolveLang('/en_US/products'));
        // Path beats query when both are present.
        self::assertSame(
            'en_US',
            MaintenanceStaticPage::resolveLang('/en_US/products', 'lang=ja_JP')
        );
        // Language cookies are ignored (path/query only).
        self::assertSame(
            'zh_Hans_CN',
            MaintenanceStaticPage::resolveLang('/', '', 'foo=bar; WELINE_USER_LANG=ja_JP')
        );
        self::assertSame('zh_Hans_CN', MaintenanceStaticPage::resolveLang('/products'));
        self::assertSame(
            'en_US',
            MaintenanceStaticPage::resolveLang('/pub/errors/maintenance/en_US.html')
        );
        self::assertSame(
            '/pub/errors/maintenance/en_US.html',
            MaintenanceStaticPage::publicHtmlUrl('en_US')
        );
    }

    public function testLoadHtmlPrefersRequestedLocaleFile(): void
    {
        if (!\defined('BP')) {
            self::markTestSkipped('BP not defined');
        }

        $generator = new \Weline\Maintenance\Service\MaintenanceStaticGenerator();
        $generator->publishAll(60);

        $html = MaintenanceStaticPage::loadHtml(null, '/en_US/', 'lang=en_US');
        self::assertIsString($html);
        self::assertStringContainsString('System Upgrade in Progress', (string)$html);
    }
}
