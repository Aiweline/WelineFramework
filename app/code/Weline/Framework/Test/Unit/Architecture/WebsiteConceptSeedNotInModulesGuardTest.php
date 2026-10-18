<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Abstract guard for website_concept_seed_not_in_modules:
 * modules must not host website/theme/brand openers OR site-specific default copy/i18n
 * (not a named-site denylist).
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

    /**
     * SiteCopy: high-confidence brand/atelier policy phrases must not ship as module
     * default i18n/CSV or production templates. Abstract signals — not a named-site denylist.
     * Same strings under app/design/ are allowed (design overlay home).
     */
    public function testAppCodeModuleDefaultsHaveNoSiteBoundBrandCopy(): void
    {
        $root = \dirname(__DIR__, 7);
        $codeRoot = $root . '/app/code';
        self::assertDirectoryExists($codeRoot);

        // High-confidence brand/atelier identity phrases (examples of SiteCopy signals).
        // Keep abstract: do not encode a site-code denylist; these patterns name a bound brand atelier.
        $signals = [
            '长安汉服 · Hanfu Atelier',
            'Chang\'an Hanfu · Hanfu Atelier',
            '长安汉服水墨中国风独立站',
            'Hanfu Atelier',
        ];

        $violations = [];
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($codeRoot));
        foreach ($rii as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $ext = \strtolower($file->getExtension());
            if (!\in_array($ext, ['csv', 'phtml', 'php', 'html'], true)) {
                continue;
            }
            $path = \str_replace('\\', '/', $file->getPathname());
            if (\str_contains($path, '/Test/') || \str_contains($path, '/test/') || \str_contains($path, '/doc/')) {
                continue;
            }
            // Prototype / sample HTML under Smtp is still module-shipped default chrome — flag it.
            if (\str_contains($path, '/locale-packs/')) {
                continue;
            }
            $rel = \substr($path, \strlen($root) + 1);
            // Only production i18n + shipped view surfaces (not design-time prototypes).
            $inI18n = \str_contains($path, '/i18n/') && \str_ends_with($path, '.csv');
            $inView = \str_contains($path, '/view/')
                && !\str_contains($path, '/view/prototypes/')
                && !\str_contains($path, '/_prototype/');
            $isBrandSeed = \str_contains($path, 'WebsiteBrandIdentitySeedService.php')
                || \str_contains($path, 'HanfuSubjectSeedService.php');
            if (!$inI18n && !$inView && !$isBrandSeed) {
                continue;
            }

            $source = (string)@\file_get_contents($path);
            if ($source === '') {
                continue;
            }
            // Brand seed services may keep historical strings as remediation match needles;
            // only the public SEED_* defaults must stay brand-neutral.
            if ($isBrandSeed) {
                if (\preg_match_all('/public\s+const\s+SEED_[A-Z0-9_]+\s*=\s*([\'"])(.*?)\1/s', $source, $m) > 0) {
                    foreach ($m[2] as $seedVal) {
                        foreach ($signals as $needle) {
                            if (\str_contains($seedVal, $needle)) {
                                $violations[] = $rel . ' SEED_* default contains site-bound brand copy — move to app/design/{Vendor}/{theme}/';
                                break 2;
                            }
                        }
                    }
                }
                continue;
            }
            foreach ($signals as $needle) {
                if (\str_contains($source, $needle)) {
                    $violations[] = $rel . ' contains site-bound brand copy signal — move to app/design/{Vendor}/{theme}/';
                    break;
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "website_concept_seed_not_in_modules SiteCopy (ABSTRACT): per-site/theme/brand default copy "
            . "MUST NOT live in module i18n/view — HOME is app/design/{Vendor}/{theme}/ or layout/widget Meta i18n. "
            . "Violations:\n" . \implode("\n", $violations)
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
        self::assertStringContainsString('user-visible copy', $catalog);
        self::assertStringContainsString('i18n/*.csv', $catalog);
        // Rule summary must not be framed as a grocery/hanfu/daocharms-only denylist.
        self::assertDoesNotMatchRegularExpression(
            "/'id' => 'website_concept_seed_not_in_modules',[\\s\\S]{0,800}seed-grocery-\\*/",
            $catalog
        );
    }

    public function testHcSlFormulaCoversSiteCopyAtom(): void
    {
        $root = \dirname(__DIR__, 7);
        $dsl = (string)\file_get_contents(
            $root . '/app/code/Weline/Ai/Mcp/src/HardConstraintsSymbolDsl.php'
        );
        self::assertStringContainsString("'SiteCopy'", $dsl);
        self::assertMatchesRegularExpression(
            "/'website_concept_seed_not_in_modules'\\s*=>\\s*\\[[\\s\\S]*?SiteSeed∨SiteCopy→/",
            $dsl
        );
        self::assertStringContainsString('站种子与专属文案·i18n禁写入app/code', $dsl);
    }
}
