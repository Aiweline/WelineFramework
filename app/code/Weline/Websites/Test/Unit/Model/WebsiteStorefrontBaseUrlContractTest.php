<?php
declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Model\Website;

final class WebsiteStorefrontBaseUrlContractTest extends TestCase
{
    public function testWebsiteExposesStorefrontBaseUrlResolverIncludingDefaultSite(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/Model/Website.php');
        self::assertStringContainsString('function resolveStorefrontBaseUrl(int $websiteId)', $source);
        self::assertStringContainsString('CanonicalStorefrontUrl::fromStoreUrl', $source);
        self::assertStringContainsString('ID_DEFAULT', $source);
    }

    public function testDefaultWebsiteResolvesAbsoluteStorefrontBase(): void
    {
        $base = Website::resolveStorefrontBaseUrl(Website::ID_DEFAULT);
        self::assertNotNull($base);
        self::assertMatchesRegularExpression('#^https?://#i', (string)$base);
        self::assertStringNotContainsString('localhost', (string)$base);
    }
}
