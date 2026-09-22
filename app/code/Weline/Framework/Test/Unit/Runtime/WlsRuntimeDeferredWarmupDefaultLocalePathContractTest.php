<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;

/**
 * Deferred storefront warmup must not spend slots on default-locale prefixes
 * that App::redirectDefaultLocalizationPrefixIfNeeded would 301 away.
 */
final class WlsRuntimeDeferredWarmupDefaultLocalePathContractTest extends TestCase
{
    public function testDeferredWarmupFiltersDefaultLocaleViaCanonicalize(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Runtime/WlsRuntime.php'
        );
        self::assertStringContainsString('filterDeferredStorefrontWarmupPath', $source);
        self::assertStringContainsString('canonicalizeStorefrontLocalizationPath', $source);
        self::assertStringContainsString('resolveDeferredWarmupWebsiteDefaults', $source);
        self::assertStringContainsString('set_cookie_names', $source);
        self::assertStringContainsString('readSharedSnapshotById', $source);
        // Must not use Env `lang` as website-default fallback (re-admits /en_US/).
        self::assertStringContainsString('resolveWebsiteDefaultLanguage', $source);
        self::assertStringContainsString('resolveWebsiteDefaultCurrency', $source);
        self::assertStringContainsString('WELINE_WEBSITE_LANGUAGE', $source);
        self::assertStringNotContainsString(
            "Env::get('lang', null)",
            $this->extractMethodBody($source, 'resolveDeferredWarmupWebsiteDefaults'),
        );
    }

    public function testStorefrontWarmupPinsWebsiteLanguageIntoSyntheticRequest(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Runtime/WlsRuntime.php'
        );
        $body = $this->extractMethodBody($source, 'runStorefrontFpcWarmupAttempt');
        self::assertStringContainsString('WELINE_WEBSITE_LANGUAGE', $body);
        self::assertStringContainsString('WELINE_WEBSITE_CURRENCY', $body);
        self::assertStringContainsString('gzip, deflate', $body);
    }

    private function extractMethodBody(string $source, string $method): string
    {
        $pos = \strpos($source, 'function ' . $method . '(');
        self::assertNotFalse($pos, $method . ' missing');
        $next = \strpos($source, "\n    private function ", $pos + 1);
        if ($next === false) {
            $next = \strpos($source, "\n    public function ", $pos + 1);
        }
        if ($next === false) {
            $next = \strlen($source);
        }

        return \substr($source, $pos, $next - $pos);
    }
}
