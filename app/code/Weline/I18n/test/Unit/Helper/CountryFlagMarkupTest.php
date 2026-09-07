<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Helper\CountryFlagMarkup;

final class CountryFlagMarkupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!\defined('BP')) {
            \define('BP', dirname(__DIR__, 6) . DIRECTORY_SEPARATOR);
        }
        if (!\defined('DS')) {
            \define('DS', DIRECTORY_SEPARATOR);
        }
    }

    public function testPlaceholderHtmlHasNoSvgBody(): void
    {
        $html = CountryFlagMarkup::placeholderHtml('es');
        self::assertStringContainsString('data-country-flag="es"', $html);
        self::assertStringNotContainsString('<path', $html);
        self::assertStringNotContainsString('<svg', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    public function testPayloadForReadsVendorSvg(): void
    {
        $payload = CountryFlagMarkup::payloadFor('cn');
        self::assertNotNull($payload);
        self::assertSame('cn', $payload['code']);
        self::assertStringContainsString('<svg', $payload['svg']);
        self::assertSame(CountryFlagMarkup::ASSET_VERSION, $payload['version']);
        self::assertSame(64, strlen($payload['sha256']));
    }

    public function testPayloadsForGroupsAndSorts(): void
    {
        $batch = CountryFlagMarkup::payloadsFor(['es', 'cn', 'es', 'xx'], '4x3', 16);
        self::assertArrayHasKey('cn', $batch['flags']);
        self::assertArrayHasKey('es', $batch['flags']);
        self::assertContains('xx', $batch['missing']);
        $keys = array_keys($batch['flags']);
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $keys);
    }

    public function testToDisplayHtmlRewritesHeavyInlineSvgToImg(): void
    {
        $paths = str_repeat('<path d="M0 0h1v1z"/>', 40);
        $svg = '<svg id="flag-icons-es" viewBox="0 0 640 480">' . $paths . '</svg>';
        $html = CountryFlagMarkup::toDisplayHtml($svg, 24, 18);
        self::assertStringStartsWith('<img ', $html);
        self::assertStringContainsString('/flags/4x3/es.svg', $html);
        self::assertStringNotContainsString('<path', $html);
    }

    public function testUnknownHeavySvgWithoutCountryCodeIsRejected(): void
    {
        $paths = str_repeat('<path d="M0 0h1v1z"/>', 40);
        $svg = '<svg viewBox="0 0 10 10">' . $paths . '</svg>';
        self::assertSame('', CountryFlagMarkup::toDisplayHtml($svg));
    }
}
