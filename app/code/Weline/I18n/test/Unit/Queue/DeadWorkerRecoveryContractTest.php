<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Queue;

use PHPUnit\Framework\TestCase;

final class DeadWorkerRecoveryContractTest extends TestCase
{
    public function testAiAndLocalModelQueuesRecoverDeadWorkers(): void
    {
        $root = dirname(__DIR__, 3) . '/Queue';
        $ai = (string)file_get_contents($root . '/AiTranslateQueue.php');
        $local = (string)file_get_contents($root . '/LocalModelTranslationQueue.php');

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
