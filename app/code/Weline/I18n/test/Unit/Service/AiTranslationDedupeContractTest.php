<?php
declare(strict_types=1);

namespace Weline\I18n\test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class AiTranslationDedupeContractTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\defined('BP')) {
            \define('BP', \dirname(__DIR__, 7) . DIRECTORY_SEPARATOR);
        }
    }

    public function testBatchTranslateUsesLocaleLock(): void
    {
        $source = $this->read('app/code/Weline/I18n/Service/AiTranslationService.php');
        self::assertStringContainsString('AiTranslationBatchLock', $source);
        self::assertStringContainsString('tryAcquire', $source);
        self::assertStringContainsString('已有翻译批次在运行', $source);
    }

    public function testCronEnqueuesInsteadOfDirectTranslate(): void
    {
        $source = $this->read('app/code/Weline/I18n/Cron/AiTranslation.php');
        self::assertStringContainsString('AiTranslationQueueService', $source);
        self::assertStringContainsString('enqueueEnabledLocales', $source);
        self::assertStringNotContainsString('batchTranslateDictionary', $source);
    }

    public function testQueueConsumerPassesScopedWordsAndOwner(): void
    {
        $source = $this->read('app/code/Weline/I18n/Queue/AiTranslateQueue.php');
        self::assertStringContainsString('buildBatchScope', $source);
        self::assertStringContainsString('resolveBatchSize', $source);
        self::assertStringContainsString('google_taxonomy.', $source);
        self::assertStringContainsString('$scope', $source);
    }

    public function testQueueServiceDedupesPendingRunningByBizKey(): void
    {
        $source = $this->read('app/code/Weline/I18n/Service/AiTranslationQueueService.php');
        self::assertStringContainsString('IdempotentQueueAdmission', $source);
        self::assertStringContainsString('IDEMPOTENCY_SCOPE', $source);
        self::assertStringContainsString("admission->admit", $source);
        self::assertStringContainsString('consecutive_failures', $source);
        self::assertStringNotContainsString("w_query('queue', 'create'", $source);
    }

    public function testQueueStopsAfterThreeConsecutiveBatchFailures(): void
    {
        $config = $this->read('app/code/Weline/I18n/Service/AiTranslationConfig.php');
        $queue = $this->read('app/code/Weline/I18n/Queue/AiTranslateQueue.php');

        self::assertStringContainsString('MAX_CONSECUTIVE_BATCH_FAILURES = 3', $config);
        self::assertStringContainsString('advanceConsecutiveFailures', $queue);
        self::assertStringContainsString('isBatchFailure', $queue);
        self::assertStringContainsString('resultIndicatesBusy', $queue);
        self::assertStringContainsString('$stopRound', $queue);
        self::assertStringContainsString('已停止自动续跑', $queue);
        self::assertStringContainsString('不立刻续队', $queue);
        self::assertStringNotContainsString('throw new \\RuntimeException', $queue);
    }

    private function read(string $relativePath): string
    {
        $path = BP . DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\');
        self::assertFileExists($path);

        return (string)file_get_contents($path);
    }
}
