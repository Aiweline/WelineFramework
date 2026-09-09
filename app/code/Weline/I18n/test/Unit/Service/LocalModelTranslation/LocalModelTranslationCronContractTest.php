<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service\LocalModelTranslation;

use PHPUnit\Framework\TestCase;

final class LocalModelTranslationCronContractTest extends TestCase
{
    public function testAiTranslationCronEnqueuesLocalModelTranslationQueue(): void
    {
        $cron = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Cron/AiTranslation.php',
        );
        $queue = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Queue/LocalModelTranslationQueue.php',
        );
        $service = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Service/LocalModelTranslation/LocalModelTranslationService.php',
        );

        self::assertStringContainsString('LocalModelTranslationQueueService', $cron);
        self::assertStringContainsString('localModelQueueService->enqueue', $cron);
        self::assertStringContainsString("*/5 * * * *", $cron);
        self::assertStringContainsString('collectWorkItems', $queue);
        self::assertStringContainsString('collectWorkItems($offset, $batchSize + 1)', $queue);
        self::assertStringContainsString('LocalModelTranslationCatalog', $service);
        self::assertStringContainsString('function collectWorkItems(int $offset = 0, int $limit = 0)', $service);
        self::assertStringContainsString('fieldTargetsAlreadyFilled', $service);
        self::assertStringContainsString('getEnabledLocaleCodes', $service);
        self::assertStringContainsString('resolveTargetLocales', $service);
        self::assertStringContainsString('aborted_busy', $service);
        self::assertStringContainsString('AI_TRANSLATION_BUSY', $service);
        self::assertStringContainsString('aborted_busy', $queue);
        self::assertStringContainsString('enqueueContinuation', $queue);
        self::assertStringContainsString('!$abortedBusy', $queue);
        self::assertStringContainsString('不立刻续队', $queue);
        self::assertStringContainsString('DEFAULT_BATCH_SIZE', (string)file_get_contents(
            dirname(__DIR__, 4) . '/Service/LocalModelTranslation/LocalModelTranslationQueueService.php',
        ));
        self::assertStringContainsString('discoverLocalModelClassNames', (string)file_get_contents(
            dirname(__DIR__, 4) . '/Service/LocalModelTranslation/LocalModelTranslationCatalog.php',
        ));
        $queueService = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Service/LocalModelTranslation/LocalModelTranslationQueueService.php',
        );
        self::assertStringContainsString('findActiveFamilyQueueId', $queueService);
        self::assertStringContainsString('function enqueueContinuation', $queueService);
        self::assertStringContainsString('collectWorkItems(0, 1)', $queueService);
    }
}
