<?php

declare(strict_types=1);

namespace Weline\Meta\Test\Unit\Service;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllMetaServiceContractsPass().
 */
final class MetaServiceContractsTest extends TestCase
{
    public function testAllMetaServiceContractsPass(): void
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
            'MetaLocalStaleGc::testCronAndServiceExistWithLimits' => function () {
            $this->assert_MetaLocalStaleGc_testCronAndServiceExistWithLimits();
        },
            'MetaLocalTranslationService::testBatchWalksMetaLocalRowsWithScopeVersionAndLaneMeta' => function () {
            $this->assert_MetaLocalTranslationService_testBatchWalksMetaLocalRowsWithScopeVersionAndLaneMeta();
        },
        ];
    }

private function assert_MetaLocalStaleGc_testCronAndServiceExistWithLimits(): void
{
    $cron = '/Users/weline/Project/Official/框架/app/code/Weline/Meta' . '/Cron/MetaLocalStaleGc.php';
    $service = '/Users/weline/Project/Official/框架/app/code/Weline/Meta' . '/Service/MetaLocalStaleGcService.php';
    self::assertFileExists($cron);
    self::assertFileExists($service);
    $cronSrc = (string) file_get_contents($cron);
    self::assertStringContainsString("return 'meta_local_stale_gc'", $cronSrc);
    self::assertStringContainsString('MetaLocalStaleGcService', $cronSrc);
    $svc = (string) file_get_contents($service);
    self::assertStringContainsString('DEFAULT_MAX_DELETE', $svc);
    self::assertStringContainsString('deleted_orphan_version', $svc);
    self::assertStringContainsString('deleted_stale_preview', $svc);
    self::assertStringContainsString('deleted_orphan_meta', $svc);
    self::assertStringNotContainsString('@meta::', $svc);
}

private function assert_MetaLocalTranslationService_testBatchWalksMetaLocalRowsWithScopeVersionAndLaneMeta(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Meta' . '/Service/MetaLocalTranslationService.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('schema_fields_SCOPE', $src);
    self::assertStringContainsString('schema_fields_VERSION_ID', $src);
    self::assertStringContainsString('schema_fields_CONFIG_KEY', $src);
    self::assertStringContainsString("'meta'", $src);
    self::assertStringNotContainsString('word_prefix', $src);
    self::assertStringNotContainsString('@meta::', $src);
    self::assertStringNotContainsString("['name', 'description']", $src);
}
}
