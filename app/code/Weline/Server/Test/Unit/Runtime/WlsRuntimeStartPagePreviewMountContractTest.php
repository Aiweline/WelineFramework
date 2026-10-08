<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;

/**
 * Start-page root detection must peel visitor URI mounts via
 * Weline_Framework_Url::normalize_visitor_uri before treating the request as non-root.
 */
final class WlsRuntimeStartPagePreviewMountContractTest extends TestCase
{
    public function testStartPageNormalizesVisitorUriBeforeRootDetection(): void
    {
        $root = dirname(__DIR__, 3);
        $runtime = (string)file_get_contents($root . '/Runtime/WlsRuntime.php');

        self::assertStringContainsString('normalizeVisitorUriForStartPage', $runtime);
        self::assertStringContainsString('Url::normalizeVisitorUri', $runtime);
        self::assertStringContainsString('buildStartPageRequestUrlForSiteProbe', $runtime);
        self::assertMatchesRegularExpression(
            '/private function applyFrontendRootStartPageRoute\\(Request \\$request\\): void\\s*\\{[\\s\\S]*?normalizeVisitorUriForStartPage\\(\\$currentUri\\)/',
            $runtime
        );
        // Website probe must run AFTER peel, using routing REQUEST_URI.
        self::assertMatchesRegularExpression(
            '/normalizeVisitorUriForStartPage\\(\\$currentUri\\);[\\s\\S]*?ensureWebsiteContextForStartPage\\(\\$request\\)/',
            $runtime
        );
        self::assertMatchesRegularExpression(
            '/\\$routingUri = \\$normalized\\[\'routing_uri\'\\];[\\s\\S]*?isRootRequestUri\\(\\$routingUri\\)/',
            $runtime
        );
        // Server must not hardcode Theme preview path segment.
        self::assertStringNotContainsString("'/~preview'", $runtime);
        self::assertStringNotContainsString('"/~preview"', $runtime);
    }
}
