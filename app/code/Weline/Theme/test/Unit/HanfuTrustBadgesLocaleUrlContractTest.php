<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Hanfu trust-badges overlay must localize guide links (locale/currency prefix).
 */
final class HanfuTrustBadgesLocaleUrlContractTest extends TestCase
{
    private function overlayPath(): string
    {
        return dirname(__DIR__, 5) . '/design/Weline/hanfu/frontend/widgets/content/trust-badges/default.phtml';
    }

    public function testOverlayResolvesLinksThroughUrlHelperNotRawSiteRootHref(): void
    {
        $src = (string)file_get_contents($this->overlayPath());
        self::assertStringContainsString('FooterDefaultLinksHelper::splitUrlForTaglib', $src);
        self::assertStringContainsString('Url::class', $src);
        self::assertStringContainsString('$resolveBadgeHref', $src);
        self::assertStringContainsString("'/guide/shipping'", $src);
        self::assertStringContainsString("'/guide/returns'", $src);
        self::assertStringContainsString("'/guide/payment'", $src);
        // Final href must use resolved $badgeHref — raw $esc($badgeLink) bypasses locale prefix.
        self::assertStringContainsString("href=\"' . \$badgeHref . '\"", $src);
        self::assertStringNotContainsString("href=\"' . \$esc(\$badgeLink) . '\"", $src);
    }
}
