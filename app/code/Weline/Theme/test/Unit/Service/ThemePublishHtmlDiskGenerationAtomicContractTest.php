<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Contract: Theme publish/promote → html-disk purge+rebake is atomic with
 * StorefrontGenerationIdentity (themePublishedVersionId), not deploy_version.
 * Post-promote assert must use real HTTP access — not CLI collectAcceptable probes.
 */
final class ThemePublishHtmlDiskGenerationAtomicContractTest extends TestCase
{
    public function testClearAllThemeRelatedCachesEndsWithStagingWarmAfterSharedPurge(): void
    {
        $src = $this->read('app/code/Weline/Theme/Service/ThemeRuntimeCacheCleaner.php');

        self::assertStringContainsString('clearNonGlobalCaches($themeId, $reason, false)', $src);
        self::assertStringContainsString('assertPromotedHtmlDiskMatchesStorefrontGeneration', $src);
        self::assertStringContainsString('StorefrontWarmupHttpClient', $src);
        self::assertStringContainsString('advance_scope_published_storefront_generation', $src);
        self::assertStringContainsString('advancePublishedStorefrontGenerationsForTheme', $src);

        $allPos = \strpos($src, 'function clearAllThemeRelatedCaches(');
        self::assertNotFalse($allPos);
        $chunk = \substr($src, $allPos, 5000);
        $advancePos = \strpos($chunk, 'advance_scope_published_storefront_generation');
        $sharedPos = \strpos($chunk, 'wls_shared_fpc_full');
        $cdnPos = \strpos($chunk, 'cdn_full_page_purge');
        $warmPos = \strpos($chunk, "runStep(\$result, 'fpc_staging_warm_promote'");
        self::assertNotFalse($advancePos);
        self::assertNotFalse($sharedPos);
        self::assertNotFalse($cdnPos);
        self::assertNotFalse($warmPos);
        self::assertLessThan($sharedPos, $advancePos, 'scope published advance must run BEFORE shared purge');
        self::assertGreaterThan($sharedPos, $warmPos, 'staging warm must run AFTER shared purge');
        self::assertGreaterThan($cdnPos, $warmPos, 'staging warm must be LAST after CDN purge');
    }

    public function testStagingWarmRepublishesHomepageViaPierceIntoOpenStaging(): void
    {
        $src = $this->read('app/code/Weline/Theme/Service/ThemeRuntimeCacheCleaner.php');
        self::assertStringContainsString('republishHomepageIntoOpenStaging', $src);
        // Staging seed must refuse FPC HIT bodies (needBody warm uses bypass in HttpClient).
        self::assertStringContainsString("str_contains(\$fpc, 'HIT')", $src);
        self::assertStringContainsString('StorefrontWarmupHttpClient', $src);
        $chunk = \substr(
            $src,
            (int)\strpos($src, 'function rebuildFpcViaStagingWarmPromote'),
            1800,
        );
        self::assertStringContainsString('republishHomepageIntoOpenStaging()', $chunk);
        self::assertStringContainsString('header-chrome-amazon', $src);
        self::assertStringContainsString('data-weline-layout-pack', $src);
    }

    public function testRebuildAssertsViaHttpAccessNotCliAcceptableSet(): void
    {
        $src = $this->read('app/code/Weline/Theme/Service/ThemeRuntimeCacheCleaner.php');
        $assertPos = \strpos($src, 'function assertPromotedHtmlDiskMatchesStorefrontGeneration');
        self::assertNotFalse($assertPos);
        $assertChunk = \substr($src, $assertPos, 3200);
        self::assertStringContainsString('themePublishedVersionId', $assertChunk);
        self::assertStringContainsString('requestTarget', $assertChunk);
        self::assertStringContainsString("'pierce' => false", $assertChunk);
        self::assertStringContainsString("'bypass' => false", $assertChunk);
        self::assertStringContainsString('live=', $assertChunk);
        self::assertStringContainsString('origin=', $assertChunk);
        self::assertStringNotContainsString('deploy_version', $assertChunk);
        // Must not CLI-probe acceptable published ids for promote assert.
        self::assertStringNotContainsString('collectAcceptableStorefrontGenerationIds', $assertChunk);
        self::assertStringNotContainsString('currentVersionId()', $assertChunk);
        self::assertStringNotContainsString('RecursiveDirectoryIterator', $assertChunk);
        // Must not require baked === CLI currentVersionId alone (Global vs Store false positive).
        self::assertStringNotContainsString('strcasecmp($baked, $expected)', $assertChunk);
    }

    public function testQueryBinRejectsEmptyClientStampWhenServerNonEmpty(): void
    {
        $src = $this->read('app/code/Weline/Frontend/Controller/Api/QueryBin.php');
        self::assertStringContainsString('assertClientThemeGenerationCurrent', $src);
        self::assertStringContainsString('StorefrontGenerationIdentity::currentVersionId', $src);
        // Empty client vs non-empty server → strcasecmp unequal → 409 (no empty-skip).
        self::assertStringContainsString('deploy_reload_required', $src);
        self::assertDoesNotMatchRegularExpression(
            '/if\s*\(\s*\$clientThemePublishedVersionId\s*===\s*[\'"][\'"]\s*\)\s*\{\s*return/',
            $src,
        );
    }

    private function read(string $relative): string
    {
        $root = \dirname(__DIR__, 7); // …/Theme/test/Unit/Service → repository root
        $path = $root . \DIRECTORY_SEPARATOR . \str_replace('/', \DIRECTORY_SEPARATOR, $relative);
        self::assertFileExists($path);
        $contents = \file_get_contents($path);
        self::assertIsString($contents);
        self::assertNotSame('', $contents);

        return $contents;
    }
}
