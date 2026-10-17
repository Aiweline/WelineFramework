<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllI18nCronContractsPass().
 */
final class I18nCronContractsTest extends TestCase
{
    public function testAllI18nCronContractsPass(): void
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
            'DictionaryCollectCron::testCronReusesFrameworkI18nCollectCommand' => function () {
            $this->assert_DictionaryCollectCron_testCronReusesFrameworkI18nCollectCommand();
        },
        ];
    }

private function assert_DictionaryCollectCron_testCronReusesFrameworkI18nCollectCommand(): void
{
    $cron = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Cron/DictionaryCollect.php');
    self::assertStringContainsString('I18nCollectCommand', $cron);
    self::assertStringContainsString('Weline\Framework\Console\Console\I18n\Collect', $cron);
    self::assertStringContainsString('collectCommand->execute', $cron);
    self::assertStringContainsString('exitCode === 75', $cron);
    self::assertStringContainsString('已有收集进行中', $cron);
    self::assertStringContainsString("execute_name(): string", $cron);
    self::assertStringContainsString("'i18n_dictionary_collect'", $cron);
    self::assertStringContainsString("'10 * * * *'", $cron);
    self::assertStringNotContainsString("__('", $cron);
    $collect = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Framework/Console/Console/I18n/Collect.php');
    self::assertStringContainsString("ALIASES = ['i18n:collect']", $collect);
    self::assertStringContainsString('DictionaryCompiler', $collect);
    $after = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Observer/DictionaryCompileAfterObserver.php');
    self::assertStringContainsString('persistCollectedWords', $after);
}
}
