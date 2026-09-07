<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service\LocalModelTranslation;

use PHPUnit\Framework\TestCase;

final class LocalModelTranslationTriggerContractTest extends TestCase
{
    public function testAiTranslationConfigurationStartsLocalModelWork(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Controller/Backend/AiTranslation.php',
        );

        self::assertStringContainsString('LocalModelTranslationQueueService', $source);
        self::assertStringContainsString('localModelQueueService->enqueue', $source);
        self::assertStringContainsString("'config_save'", $source);
        self::assertStringContainsString("'manual'", $source);
    }

    public function testLocalModelQueueSkipsWhenNoTargetLocaleIsEnabled(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Service/LocalModelTranslation/LocalModelTranslationQueueService.php',
        );

        self::assertStringContainsString('getEnabledLocaleCodes()', $source);
        self::assertStringContainsString("return 0;", $source);
    }
}
