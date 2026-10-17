<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Script;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllProductScriptContractsPass().
 */
final class ProductScriptContractsTest extends TestCase
{
    public function testAllProductScriptContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'HanfuR2CommerceMediaRemediation::testCommerceRemediationRegistersEveryAssetWithBilingualProvenance' => function () {
            $this->assert_HanfuR2CommerceMediaRemediation_testCommerceRemediationRegistersEveryAssetWithBilingualProvenance();
        },
            'HanfuR2CommerceMediaRemediation::testCleanupIsExactAndGatedByVerification' => function () {
            $this->assert_HanfuR2CommerceMediaRemediation_testCleanupIsExactAndGatedByVerification();
        },
            'HanfuR2CommerceMediaRemediation::testDefaultHomepageAndCategoryWidgetsUseHanfuVisuals' => function () {
            $this->assert_HanfuR2CommerceMediaRemediation_testDefaultHomepageAndCategoryWidgetsUseHanfuVisuals();
        },
            'HanfuR2CommerceMediaRemediation::testCategorySeedPointsToReviewedR2WebpPaths' => function () {
            $this->assert_HanfuR2CommerceMediaRemediation_testCategorySeedPointsToReviewedR2WebpPaths();
        },
            'HanfuTestCatalogCleanupScript::testHelpDescribesGuardedModesAndExitCodesAsJson' => function () {
            $this->assert_HanfuTestCatalogCleanupScript_testHelpDescribesGuardedModesAndExitCodesAsJson();
        },
            'HanfuTestCatalogCleanupScript::testScriptRejectsNonZeroWebsiteBeforeBootstrappingApplication' => function () {
            $this->assert_HanfuTestCatalogCleanupScript_testScriptRejectsNonZeroWebsiteBeforeBootstrappingApplication();
        },
            'HanfuTestCatalogCleanupScript::testScriptRejectsConflictingModesWithoutPartialSuccess' => function () {
            $this->assert_HanfuTestCatalogCleanupScript_testScriptRejectsConflictingModesWithoutPartialSuccess();
        },
            'HanfuTestCatalogCleanupScript::testScriptRejectsInvalidRunIdBeforeBootstrappingApplication' => function () {
            $this->assert_HanfuTestCatalogCleanupScript_testScriptRejectsInvalidRunIdBeforeBootstrappingApplication();
        },
        ];
    }

private function assert_HanfuR2CommerceMediaRemediation_testCommerceRemediationRegistersEveryAssetWithBilingualProvenance(): void
{
    $script = (string) file_get_contents('/Users/weline/Project/Official/框架/app' . '/design/Weline/hanfu/scripts/remediate-hanfu-commerce-media-r2.php');
    foreach (['FileAssetLibraryInterface', 'ProductCategoryAttributeService', 'MediaRepository', "'zh_Hans_CN'", "'en_US'", "'display_name'", "'default_alt'", "'description'", "'default_caption'", "'translation_state'", "'translation_origin'", "'source'", "'license'", "'purpose'", "'relations'", "'review'", 'count($assets) !== 87', 'count($categories) !== 28', 'syncProductScope', 'asset://', '--dry-run', '--apply', '--verify', '--cleanup'] as $required) {
        self::assertStringContainsString($required, $script);
    }
}

private function assert_HanfuR2CommerceMediaRemediation_testCleanupIsExactAndGatedByVerification(): void
{
    $script = (string) file_get_contents('/Users/weline/Project/Official/框架/app' . '/design/Weline/hanfu/scripts/remediate-hanfu-commerce-media-r2.php');
    $verifyPosition = strpos($script, '$verification = hanfuR2VerifyState(');
    $cleanupPosition = strpos($script, "if (\$mode === '--cleanup')");
    self::assertIsInt($verifyPosition);
    self::assertIsInt($cleanupPosition);
    self::assertLessThan($cleanupPosition, $verifyPosition);
    self::assertStringContainsString('count($cleanupKeys) !== 64', $script);
    self::assertStringContainsString('$library->deleteObject(', $script);
    self::assertStringNotContainsString('deleteDirectory(', $script);
    self::assertStringNotContainsString('rm -rf', $script);
}

private function assert_HanfuR2CommerceMediaRemediation_testDefaultHomepageAndCategoryWidgetsUseHanfuVisuals(): void
{
    $themeRoot = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/theme/frontend/widgets';
    $hero = (string) file_get_contents($themeRoot . '/banner/hero-slider/default.phtml');
    $category = (string) file_get_contents($themeRoot . '/category/category-grid/default.phtml');
    self::assertStringContainsString('/pub/media/catalog/hanfu/r2/homepage/', $hero);
    self::assertStringContainsString("'slug' => 'taoyuan-qingmeng'", $hero);
    self::assertStringContainsString("\$slug . '-mobile.webp'", $hero);
    self::assertStringContainsString('<picture>', $hero);
    self::assertStringContainsString('object-position: 75% center', $hero);
    self::assertStringNotContainsString('2026春季新品上市', $hero);
    self::assertStringNotContainsString('全场满300减50', $hero);
    self::assertStringNotContainsString('品质生活', $hero);
    self::assertStringContainsString('/pub/media/catalog/hanfu/r2/categories/icons/', $category);
    self::assertStringContainsString("'url' => '/category/' . \$code", $category);
    foreach (['women', 'men', 'kids', 'accessories', 'sets'] as $code) {
        self::assertStringContainsString("'code' => '" . $code . "'", $category);
    }
    self::assertStringNotContainsString('ThemeDemoCatalog', $category);
    self::assertStringNotContainsString('电子产品', $category);
    self::assertStringContainsString('$showCount && (int)($category[\'count\'] ?? 0) > 0', $category);
}

private function assert_HanfuR2CommerceMediaRemediation_testCategorySeedPointsToReviewedR2WebpPaths(): void
{
    $seed = (string) file_get_contents('/Users/weline/Project/Official/框架/app' . '/design/Weline/hanfu/data/seed-categories.php');
    self::assertStringContainsString("const MEDIA_BASE = '/pub/media/catalog/hanfu/r2/categories';", $seed);
    self::assertStringContainsString("'/icons/' . \$code . '.webp'", $seed);
    self::assertStringContainsString("'/banners/' . \$code . '.webp'", $seed);
    self::assertStringNotContainsString("'/icons/' . \$code . '.svg'", $seed);
}

private function assert_HanfuTestCatalogCleanupScript_testHelpDescribesGuardedModesAndExitCodesAsJson(): void
{
    $process = $this->runScript_HanfuTestCatalogCleanupScript(['--help']);
    self::assertSame(0, $process['exit_code'], $process['stderr']);
    self::assertSame('', $process['stderr']);
    self::assertJson($process['stdout']);
    self::assertStringContainsString('"exit_codes":{"0":"success","1":"runtime_error","2":"usage_error"}', $process['stdout']);
    /** @var array<string,mixed> $payload */
    $payload = json_decode($process['stdout'], true, 512, JSON_THROW_ON_ERROR);
    self::assertSame('hanfu-test-catalog-cleanup-cli.v1', $payload['contract'] ?? null);
    self::assertSame(['--dry-run', '--apply=<digest>', '--verify=<digest>'], $payload['modes'] ?? null);
    self::assertSame(['0' => 'success', '1' => 'runtime_error', '2' => 'usage_error'], $payload['exit_codes'] ?? null);
}

private function assert_HanfuTestCatalogCleanupScript_testScriptRejectsNonZeroWebsiteBeforeBootstrappingApplication(): void
{
    $process = $this->runScript_HanfuTestCatalogCleanupScript(['--website=1', '--dry-run', '--run-id=cleanup-test']);
    self::assertSame(2, $process['exit_code']);
    self::assertSame('', $process['stdout']);
    self::assertStringContainsString('website_id_zero_required', $process['stderr']);
    $this->assertNoCredentialLeak_HanfuTestCatalogCleanupScript($process['stderr']);
}

private function assert_HanfuTestCatalogCleanupScript_testScriptRejectsConflictingModesWithoutPartialSuccess(): void
{
    $process = $this->runScript_HanfuTestCatalogCleanupScript(['--website=0', '--dry-run', '--apply=' . str_repeat('a', 64), '--run-id=cleanup-test']);
    self::assertSame(2, $process['exit_code']);
    self::assertSame('', $process['stdout']);
    self::assertStringContainsString('hanfu_cleanup_mode_conflict', $process['stderr']);
    $this->assertNoCredentialLeak_HanfuTestCatalogCleanupScript($process['stderr']);
}

private function assert_HanfuTestCatalogCleanupScript_testScriptRejectsInvalidRunIdBeforeBootstrappingApplication(): void
{
    $process = $this->runScript_HanfuTestCatalogCleanupScript(['--website=0', '--dry-run', '--run-id=NO']);
    self::assertSame(2, $process['exit_code']);
    self::assertSame('', $process['stdout']);
    self::assertStringContainsString('hanfu_cleanup_run_id_invalid', $process['stderr']);
    $this->assertNoCredentialLeak_HanfuTestCatalogCleanupScript($process['stderr']);
}

/**
 * @param list<string> $arguments
 * @return array{exit_code:int,stdout:string,stderr:string}
 */
private function runScript_HanfuTestCatalogCleanupScript(array $arguments): array
{
    $script = '/Users/weline/Project/Official/框架/app' . '/design/Weline/hanfu/scripts/cleanup-hanfu-test-catalog.php';
    $command = [PHP_BINARY, $script, ...$arguments];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];
    $process = proc_open($command, $descriptors, $pipes, '/Users/weline/Project/Official/框架');
    self::assertIsResource($process);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    return ['exit_code' => $exitCode, 'stdout' => trim((string) $stdout), 'stderr' => trim((string) $stderr)];
}

private function assertNoCredentialLeak_HanfuTestCatalogCleanupScript(string $output): void
{
    self::assertDoesNotMatchRegularExpression('/(?:password|passwd|secret|access[_-]?token|authorization|cookie|session(?:_?id)?)\s*[:=]/i', $output);
}
}
