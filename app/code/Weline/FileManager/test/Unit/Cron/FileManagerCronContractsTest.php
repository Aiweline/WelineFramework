<?php

declare(strict_types=1);

namespace Weline\FileManager\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllFileManagerCronContractsPass().
 */
final class FileManagerCronContractsTest extends TestCase
{
    public function testAllFileManagerCronContractsPass(): void
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
            'FileAssetLocaleAiTranslationCron::testCronCatchesEnqueueFailuresInsteadOfFatalCli' => function () {
            $this->assert_FileAssetLocaleAiTranslationCron_testCronCatchesEnqueueFailuresInsteadOfFatalCli();
        },
        ];
    }

private function assert_FileAssetLocaleAiTranslationCron_testCronCatchesEnqueueFailuresInsteadOfFatalCli(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/FileManager' . '/Cron/FileAssetLocaleAiTranslation.php');
    self::assertStringContainsString('enqueueAutoFill', $source);
    self::assertStringContainsString('catch (\Throwable', $source);
    self::assertStringContainsString('入队异常', $source);
}
}
