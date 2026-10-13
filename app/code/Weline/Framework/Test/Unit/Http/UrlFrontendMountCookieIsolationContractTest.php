<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;

/**
 * Empty data-website-mount on the default site must not fall back to sticky
 * WELINE_WEBSITE_URL cookie (e.g. leftover /~site/grocery from a prior visit).
 */
final class UrlFrontendMountCookieIsolationContractTest extends TestCase
{
    public function testUrlFrontendResolveWebsiteMountPathHonorsEmptyDomAttribute(): void
    {
        $source = $this->read('View/statics/js/url-frontend.js');
        $at = \strpos($source, 'function resolveWebsiteMountPath');
        self::assertNotFalse($at);
        $body = \substr($source, $at, 1400);
        self::assertStringContainsString("hasAttribute('data-website-mount')", $body);
        self::assertStringContainsString('SSR-authoritative', $body);
        self::assertStringNotContainsString("getCookie('WELINE_WEBSITE_URL')", $body);
        self::assertStringContainsString('No sticky WELINE_WEBSITE_URL', $body);
    }

    public function testI18nResolveWebsiteMountPathHonorsEmptyDomAttribute(): void
    {
        $source = $this->read('View/statics/js/i18n.js');
        $at = \strpos($source, 'function resolveWebsiteMountPath');
        self::assertNotFalse($at);
        $body = \substr($source, $at, 1200);
        self::assertStringContainsString("hasAttribute('data-website-mount')", $body);
        self::assertStringContainsString('SSR-authoritative', $body);
        self::assertStringNotContainsString("readCookieValue('WELINE_WEBSITE_URL')", $body);
        self::assertStringContainsString('No sticky WELINE_WEBSITE_URL', $body);
    }

    private function read(string $relative): string
    {
        $path = \dirname(__DIR__, 3) . '/' . \ltrim($relative, '/');
        $content = \file_get_contents($path);
        self::assertIsString($content, $path);

        return $content;
    }
}
