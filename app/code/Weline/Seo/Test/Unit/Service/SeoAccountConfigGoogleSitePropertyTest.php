<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\SeoAccountConfig;

final class SeoAccountConfigGoogleSitePropertyTest extends TestCase
{
    /**
     * @dataProvider provideSiteProperties
     */
    public function testNormalizeGoogleSiteProperty(string $input, string $expected): void
    {
        self::assertSame($expected, SeoAccountConfig::normalizeGoogleSiteProperty($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideSiteProperties(): array
    {
        return [
            'https_www' => ['https://www.tivki.com', 'sc-domain:tivki.com'],
            'https_www_slash' => ['https://www.tivki.com/', 'sc-domain:tivki.com'],
            'http_apex' => ['http://tivki.com', 'sc-domain:tivki.com'],
            'sc_domain' => ['sc-domain:tivki.com', 'sc-domain:tivki.com'],
            'sc_domain_www' => ['sc-domain:www.tivki.com', 'sc-domain:tivki.com'],
            'bare_host' => ['tivki.com', 'sc-domain:tivki.com'],
            'empty' => ['', ''],
        ];
    }
}
