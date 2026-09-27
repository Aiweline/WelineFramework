<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Adapter;

use PHPUnit\Framework\TestCase;

final class GoogleSitemapAdapterGetStatsSitePropertyContractTest extends TestCase
{
    public function testGetStatsPrefersAccountSiteUrlOverWebsiteFallback(): void
    {
        $root = dirname(__DIR__, 3);
        $src = (string)file_get_contents($root . '/Adapter/GoogleSitemapAdapter.php');
        self::assertStringContainsString('Prefer account GSC property', $src);
        self::assertStringContainsString("config['site_url']", $src);
        self::assertStringContainsString('normalizeGoogleSiteProperty($property)', $src);
        self::assertStringContainsString('website.url is fallback only', $src);
    }
}
