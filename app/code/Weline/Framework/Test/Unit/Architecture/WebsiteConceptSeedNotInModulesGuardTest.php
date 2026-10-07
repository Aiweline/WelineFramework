<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Abstract guard for website_concept_seed_not_in_modules:
 * modules must not host website/theme/brand openers (not a named-site denylist).
 */
final class WebsiteConceptSeedNotInModulesGuardTest extends TestCase
{
    public function testAppCodeHasNoWebsiteOrThemeBoundOpeners(): void
    {
        $root = \dirname(__DIR__, 7);
        $codeRoot = $root . '/app/code';
        self::assertDirectoryExists($codeRoot);

        $violations = [];
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($codeRoot));
        foreach ($rii as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = \str_replace('\\', '/', $file->getPathname());
            if (\str_contains($path, '/Test/') || \str_contains($path, '/test/') || \str_contains($path, '/doc/')) {
                continue;
            }
            $rel = \substr($path, \strlen($root) + 1);
            $base = $file->getFilename();

            // Site Sample kits under modules are website-concept openers.
            if (\preg_match('#/Sample/[A-Za-z0-9_-]+/#', $path) === 1
                && (\str_contains($path, '/Product/Sample/') || \str_contains($path, '/Blog/Sample/'))
            ) {
                $violations[] = $rel . ' (module Sample kit — move to app/design/{Vendor}/{theme}/)';
                continue;
            }

            $looksLikeOpener = \preg_match(
                '/^(seed-|import-|mount-|restore-|flatten-|enrich-|remediate-|cleanup-|scale-|patch-|apply-).+/i',
                $base
            ) === 1
                || \preg_match('/CatalogSeeder\.php$/i', $base) === 1;

            if (!$looksLikeOpener) {
                continue;
            }

            $source = (string)@\file_get_contents($path);
            if ($source === '') {
                continue;
            }

            // Abstract: bound to a concrete website code / theme / brand opener gate.
            $websiteBound = \preg_match(
                '/website\s*code\s*=|WEBSITE_CODE\s*=|only accepts code\s*=|Refuse:.*website code|website_id\s*=\s*0\s*.*hanfu|for website code=/i',
                $source
            ) === 1;
            $themeBound = \preg_match(
                '/design theme|website-concept|neighborhood-grocery|storefront catalog seed refuses website_id=0/i',
                $source
            ) === 1
                && \preg_match('/TaxDefaultSeed|seed-defaults|Setup\\\\Install/i', $source) !== 1;

            if ($websiteBound || $themeBound) {
                // Global domain seeds (Tax etc.) may mention website_id as a parameter without a site code gate.
                if (\preg_match('/TaxDefaultSeed|seed-defaults|SeedWeightBracket|SeedLaneOrigin/i', $source) === 1
                    && \preg_match('/only accepts code=|WEBSITE_CODE\s*=/i', $source) !== 1
                ) {
                    continue;
                }
                $violations[] = $rel . ' (website/theme-bound opener in module)';
            }
        }

        self::assertSame(
            [],
            $violations,
            "website_concept_seed_not_in_modules (ABSTRACT): website/theme/brand openers MUST live under "
            . "app/design/{Vendor}/{theme}/ — not app/code modules. Violations:\n"
            . \implode("\n", $violations)
        );
    }

    public function testHardRuleTextIsAbstractNotNamedSiteDenylist(): void
    {
        $root = \dirname(__DIR__, 7);
        $catalog = (string)\file_get_contents(
            $root . '/app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php'
        );
        self::assertStringContainsString('website_concept_seed_not_in_modules', $catalog);
        self::assertStringContainsString('ABSTRACT', $catalog);
        self::assertStringContainsString('NOT a denylist of named sites', $catalog);
        self::assertStringContainsString('EVERY current and future website/theme', $catalog);
        // Rule summary must not be framed as a grocery/hanfu/daocharms-only denylist.
        self::assertDoesNotMatchRegularExpression(
            "/'id' => 'website_concept_seed_not_in_modules',[\\s\\S]{0,800}seed-grocery-\\*/",
            $catalog
        );
    }
}
