<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Contract: Theme publish/promote → html-disk purge+rebake is atomic with
 * StorefrontGenerationIdentity (themePublishedVersionId), not deploy_version.
 */
final class ThemePublishHtmlDiskGenerationAtomicContractTest extends TestCase
{
    public function testClearAllThemeRelatedCachesEndsWithStagingWarmAfterSharedPurge(): void
    {
        $src = $this->read('app/code/Weline/Theme/Service/ThemeRuntimeCacheCleaner.php');

        self::assertStringContainsString('clearNonGlobalCaches($themeId, $reason, false)', $src);
        self::assertStringContainsString('assertPromotedHtmlDiskMatchesStorefrontGeneration', $src);
        self::assertStringContainsString('collectAcceptableStorefrontGenerationIds', $src);
        self::assertStringContainsString('StorefrontGenerationIdentity::currentVersionId', $src);

        $allPos = \strpos($src, 'function clearAllThemeRelatedCaches(');
        self::assertNotFalse($allPos);
        $chunk = \substr($src, $allPos, 2200);
        $sharedPos = \strpos($chunk, 'wls_shared_fpc_full');
        $cdnPos = \strpos($chunk, 'cdn_full_page_purge');
        $warmPos = \strpos($chunk, "runStep(\$result, 'fpc_staging_warm_promote'");
        self::assertNotFalse($sharedPos);
        self::assertNotFalse($cdnPos);
        self::assertNotFalse($warmPos);
        self::assertGreaterThan($sharedPos, $warmPos, 'staging warm must run AFTER shared purge');
        self::assertGreaterThan($cdnPos, $warmPos, 'staging warm must be LAST after CDN purge');
    }

    public function testRebuildAssertsBakedIdMatchesGenerationIdentity(): void
    {
        $src = $this->read('app/code/Weline/Theme/Service/ThemeRuntimeCacheCleaner.php');
        self::assertStringContainsString('html-disk promote stamp mismatch', $src);
        self::assertStringContainsString('themePublishedVersionId', $src);
        self::assertStringContainsString('acceptable=', $src);
        self::assertStringContainsString('collectAcceptableStorefrontGenerationIds', $src);
        $assertChunk = \substr(
            $src,
            (int)\strpos($src, 'assertPromotedHtmlDiskMatchesStorefrontGeneration'),
            2200,
        );
        self::assertStringNotContainsString('deploy_version', $assertChunk);
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
        $path = BP . \str_replace('/', \DIRECTORY_SEPARATOR, $relative);
        self::assertFileExists($path);
        $contents = \file_get_contents($path);
        self::assertIsString($contents);
        self::assertNotSame('', $contents);

        return $contents;
    }
}
