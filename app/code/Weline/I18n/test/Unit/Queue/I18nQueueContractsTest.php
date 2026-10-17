<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Queue;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllI18nQueueContractsPass().
 */
final class I18nQueueContractsTest extends TestCase
{
    public function testAllI18nQueueContractsPass(): void
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
            'DeadWorkerRecovery::testAiAndLocalModelQueuesRecoverDeadWorkers' => function () {
            $this->assert_DeadWorkerRecovery_testAiAndLocalModelQueuesRecoverDeadWorkers();
        },
        ];
    }

private function assert_DeadWorkerRecovery_testAiAndLocalModelQueuesRecoverDeadWorkers(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Queue';
    $ai = (string) file_get_contents($root . '/AiTranslateQueue.php');
    $local = (string) file_get_contents($root . '/LocalModelTranslationQueue.php');
    foreach ([$ai, $local] as $source) {
        self::assertStringContainsString('DeadWorkerRecoveryPatchQueueInterface', $source);
        self::assertStringContainsString('MAX_DEAD_WORKER_RECOVERIES = 5', $source);
        self::assertStringContainsString('function shouldRecoverDeadWorker', $source);
        self::assertStringContainsString('function deadWorkerRecoveryPatch', $source);
        self::assertStringContainsString('function deadWorkerRecoveryMessage', $source);
        self::assertStringContainsString('_dead_worker_retries', $source);
    }
}
}
