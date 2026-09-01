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
        self::assertStringContainsString('collectWorkItems', $queue);
        self::assertStringContainsString('LocalModelTranslationCatalog', $service);
        self::assertStringContainsString('discoverLocalModelClassNames', (string)file_get_contents(
            dirname(__DIR__, 4) . '/Service/LocalModelTranslation/LocalModelTranslationCatalog.php',
        ));
    }
}
