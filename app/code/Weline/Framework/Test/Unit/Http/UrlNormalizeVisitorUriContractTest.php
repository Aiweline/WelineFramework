<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Url;

/**
 * normalizeVisitorUri is the shared dispatch point for site probe / start-page.
 */
final class UrlNormalizeVisitorUriContractTest extends TestCase
{
    public function testNormalizeVisitorUriHelperIsPublicAndDocumentedForSiteProbe(): void
    {
        $root = dirname(__DIR__, 3);
        $source = (string)file_get_contents($root . '/Http/Url.php');

        self::assertStringContainsString('public static function normalizeVisitorUri(string $uri): array', $source);
        self::assertStringContainsString('public static function applyVisitorUriNormalizeToUrl(string $requestUrl): string', $source);
        self::assertStringContainsString('Weline_Framework_Url::normalize_visitor_uri', $source);
        self::assertStringContainsString('self::normalizeVisitorUri((string)$uri)', $source);
    }

    public function testNormalizeVisitorUriPassthroughWithoutMountObserverMutation(): void
    {
        $normalized = Url::normalizeVisitorUri('/~site/daocharms/about');
        self::assertSame('/~site/daocharms/about', $normalized['routing_uri']);
        self::assertSame('/~site/daocharms/about', $normalized['origin_uri']);
    }

    public function testAppInstallsStorefrontScopeFromRoutingUriNotOrigin(): void
    {
        $root = dirname(__DIR__, 3);
        $app = (string)file_get_contents($root . '/App.php');
        self::assertStringContainsString('$routingFullRequestUri', $app);
        self::assertStringContainsString(
            '$this->installStorefrontNavigationScope(',
            $app
        );
        self::assertMatchesRegularExpression(
            '/\\$routingFullRequestUri =[\\s\\S]*?installStorefrontNavigationScope\\(\\s*\\$routingFullRequestUri/',
            $app
        );
        // Origin full URI is kept for visitor identity / FULL_REQUEST_URI.
        self::assertStringContainsString('$originFullRequestUri', $app);
        self::assertStringContainsString('WELINE_FULL_REQUEST_URI', $app);
    }
}
