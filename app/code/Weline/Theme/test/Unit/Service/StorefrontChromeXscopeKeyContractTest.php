<?php

declare(strict_types=1);

namespace Weline\Theme\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\StorefrontThemeCacheCoordinator;

/**
 * T1 + path A gate for P-theme-xscope-chrome.
 *
 * T1 (layout-agnostic shell): SharedChrome non-carrier inherit + guest-neutral
 * islands + route-independent header/footer comments prove homepage vs search/plp
 * chrome HTML is not intentionally layout-partitioned. Path A then neutralizes
 * layout dims and uses path-local source digests so homepage warmer keys match
 * non-home HTTP. Fail/串味 would skip A → T2-B warmer layout families (not active).
 */
final class StorefrontChromeXscopeKeyContractTest extends TestCase
{
    public function testT1ShellLayoutAgnosticEvidencePinned(): void
    {
        $shared = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/SharedChromeService.php'
        );
        self::assertStringContainsString(
            'non-carrier layouts always inherit global chrome',
            $shared,
        );
        self::assertStringContainsString(
            "'overall_mode' => \$isCarrier ? self::MODE_LOCAL : self::MODE_INHERIT",
            $shared,
        );

        $partials = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Block/Partials.php'
        );
        self::assertStringContainsString('Header/footer remain route-independent chrome', $partials);
        self::assertStringContainsString('Reuse chrome across routes', $partials);
        self::assertStringContainsString('hydrateChromeCurrencyIslands', $partials);
        self::assertStringContainsString('hydrateChromeSearchFacadeIslands', $partials);
        // Ban shell-strip optimization language.
        self::assertStringNotContainsString('stripChromeShell', $partials);
        self::assertStringNotContainsString('remove header', $partials);
    }

    public function testPathANeutralizesLayoutDimsAndBumpsSchema(): void
    {
        $partials = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Block/Partials.php'
        );
        self::assertStringContainsString(
            "'schema' => 'chrome-partial-v22-layout-agnostic-shell'",
            $partials,
        );
        self::assertStringContainsString('xscope A', $partials);
        self::assertStringContainsString("? 'shared'", $partials);
        self::assertStringContainsString(
            "(\$area === 'frontend' && (\$type === 'header' || \$type === 'footer'))",
            $partials,
        );
        // Must not keep raw theme layoutType as the frontend header/footer key dim.
        self::assertStringNotContainsString(
            "'layout_type' => (string)(\$themeData['layoutType'] ?? ''),\n"
            . "                'layout_option' => (string)(\$themeData['layoutOption'] ?? ''),",
            $partials,
        );
    }

    public function testPathAUsesPathLocalSourceDigestForHeaderFooter(): void
    {
        $partials = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Block/Partials.php'
        );
        self::assertStringContainsString('function resolveStorefrontChromeSourceFingerprint', $partials);
        self::assertStringContainsString('path-local digest', $partials);
        self::assertStringContainsString(
            "\$typeLc === 'header' || \$typeLc === 'footer'",
            $partials,
        );
        self::assertStringContainsString("hash('sha256'", $partials);
        self::assertStringContainsString('$origin', $partials);
        self::assertStringContainsString('$bytes', $partials);
        // Head may still use full layout snapshot fingerprint.
        self::assertStringContainsString('$snapshot->fingerprint()', $partials);
    }

    public function testChromePolicyKeepsWebsiteLangWithoutCurrencyVary(): void
    {
        $policy = StorefrontThemeCacheCoordinator::storefrontChromePolicy();
        self::assertSame('website', $policy->scope);
        self::assertSame(['lang'], $policy->vary);
        self::assertNotContains('currency', $policy->vary);

        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontThemeCacheCoordinator.php'
        );
        self::assertStringContainsString('do NOT expand currency vary first', $src);
    }

    public function testWarmerDoesNotExpandLayoutFamiliesWhilePathAActive(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontChromeLocaleCacheWarmer.php'
        );
        self::assertStringContainsString('Path A (T1 pass)', $src);
        self::assertStringContainsString("'homepage'", $src);
        // Path B not implemented as multi-layout warm loop yet.
        self::assertStringNotContainsString("layoutType'] = 'search'", $src);
        self::assertStringNotContainsString("layoutType'] = 'product_list'", $src);
        self::assertStringNotContainsString('stripChrome', $src);
    }
}
