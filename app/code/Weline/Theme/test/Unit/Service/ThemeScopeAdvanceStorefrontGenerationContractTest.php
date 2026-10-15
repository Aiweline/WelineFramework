<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Contract: storefront generation advance copies published chrome without stealing drafts.
 */
final class ThemeScopeAdvanceStorefrontGenerationContractTest extends TestCase
{
    public function testAdvanceApiExistsAndAvoidsCreateRevisionFromSideEffects(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/ThemeScopeVersionService.php'
        );
        self::assertStringContainsString('function advancePublishedStorefrontGeneration(', $src);
        self::assertStringContainsString('function advancePublishedStorefrontGenerationsForTheme(', $src);
        self::assertStringContainsString('theme_upgrade_storefront_generation', $src);

        $fnPos = \strpos($src, 'function advancePublishedStorefrontGeneration(');
        self::assertNotFalse($fnPos);
        $nextFn = \strpos($src, "\n    public function ", $fnPos + 10);
        $chunk = $nextFn !== false
            ? \substr($src, $fnPos, $nextFn - $fnPos)
            : \substr($src, $fnPos, 3500);

        // Must not steal editor draft via createRevisionFrom / unsetCurrent / is_current draft.
        self::assertStringNotContainsString('createRevisionFrom(', $chunk);
        self::assertStringNotContainsString('unsetCurrent(', $chunk);
        self::assertStringContainsString('setIsCurrent(false)', $chunk);
        self::assertStringContainsString('LIFECYCLE_SEALED', $chunk);
        self::assertStringContainsString('markPublished($revision)', $chunk);
        self::assertStringContainsString('TYPE_PUBLISH', $chunk);
    }

    public function testAdvanceForThemeProbesAllFrontendPublishedOwners(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/ThemeScopeVersionService.php'
        );
        $fnPos = \strpos($src, 'function advancePublishedStorefrontGenerationsForTheme(');
        self::assertNotFalse($fnPos);
        $chunk = \substr($src, $fnPos, 4500);
        self::assertStringContainsString('listFrontendPublishedOwnerProbes', $chunk);
        self::assertStringContainsString('ownSolidifyTargetsForTheme', $src);
        self::assertStringContainsString('ThemePublishedVersionRuntimeResolver', $src);
        self::assertStringContainsString('resolveGenerationIdentity', $src);
        // Owner-key dedupe prevents Channel/Store probes from double-advancing one publish.
        self::assertStringContainsString('$seenOwners', $chunk);
    }
}
