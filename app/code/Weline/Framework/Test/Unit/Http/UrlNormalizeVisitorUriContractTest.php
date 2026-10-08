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

    public function testAppInstallsStorefrontScopeFromOriginUriSoMountsSurvive(): void
    {
        $root = dirname(__DIR__, 3);
        $app = (string)file_get_contents($root . '/App.php');
        self::assertStringContainsString('$routingFullRequestUri', $app);
        self::assertStringContainsString('$originFullRequestUri', $app);
        self::assertStringContainsString('$scopeInstallUri', $app);
        self::assertStringContainsString(
            '$this->installStorefrontNavigationScope(',
            $app
        );
        // DetectWebsite re-resolves Website from the install URL — origin keeps
        // /~site/{code}; peeled routing "/" would bind the project-Host default.
        self::assertMatchesRegularExpression(
            '/\\$scopeInstallUri = \\$originFullRequestUri !== \'\' \\? \\$originFullRequestUri : \\$routingFullRequestUri;[\\s\\S]*?installStorefrontNavigationScope\\(\\s*\\$scopeInstallUri/',
            $app
        );
        self::assertStringContainsString('WELINE_FULL_REQUEST_URI', $app);
        self::assertStringContainsString('DetectWebsite::installNavigationScope re-resolves', $app);
    }
}
