<?php

declare(strict_types=1);

namespace Weline\FileManager\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class FileAssetLocaleTranslationContractTest extends TestCase
{
    public function testTranslationSurfaceContracts(): void
    {
        $root = dirname(__DIR__, 3);
        $interfacePath = $root . '/Api/FileAssetLocaleTranslationInterface.php';
        self::assertFileExists($interfacePath, 'root=' . $root . ' dir=' . __DIR__);
        $interface = file_get_contents($interfacePath);
        $service = file_get_contents($root . '/Service/FileAssetLocaleTranslationService.php');
        $config = file_get_contents($root . '/Service/FileAssetLocaleTranslationConfig.php');
        $cron = file_get_contents($root . '/Cron/FileAssetLocaleAiTranslation.php');
        $queue = file_get_contents($root . '/Queue/FileAssetLocaleAiTranslationQueue.php');
        $module = file_get_contents($root . '/etc/module.php');

        self::assertIsString($interface);
        self::assertIsString($service);
        self::assertIsString($config);
        self::assertIsString($cron);
        self::assertIsString($queue);
        self::assertIsString($module);

        self::assertStringContainsString('function listInstalledLocaleCodes', $interface);
        self::assertStringContainsString('function translateMissing', $interface);
        self::assertStringContainsString('function listLocales', $interface);
        self::assertStringContainsString('function isAutoTranslationEnabled', $interface);
        self::assertStringContainsString('function enqueueAutoFill', $interface);

        self::assertStringContainsString("ORIGIN_MACHINE", $service);
        self::assertStringContainsString("STATE_DRAFT", $service);
        self::assertStringContainsString("Weline_I18n::machine_translate", $service);
        self::assertStringContainsString('localeHasContent', $service);
        self::assertStringContainsString('if (!$this->config->isEnabled())', $service);

        self::assertStringContainsString("KEY = 'ai_auto_translation'", $config);
        self::assertStringContainsString('isAutoTranslationEnabled()', $cron);
        self::assertStringContainsString('enqueueAutoFill', $cron);
        self::assertStringContainsString('processPendingBatch', $queue);
        self::assertStringContainsString('enqueueContinuation', $queue);
        $queueService = (string)file_get_contents($root . '/Service/FileAssetLocaleTranslationQueueService.php');
        self::assertStringContainsString('function enqueueContinuation', $queueService);
        self::assertStringContainsString('function findActiveFamilyQueueId', $queueService);
        self::assertStringContainsString('IdempotentQueueAdmission', $queueService);
        self::assertStringContainsString('IDEMPOTENCY_SCOPE', $queueService);
        self::assertStringContainsString("admission->admit", $queueService);
        self::assertStringContainsString(':offset:%', $queueService);
        self::assertStringContainsString("'offset' => \$offset", $queueService);
        self::assertStringContainsString('hasGapFillWork', $service);
        self::assertStringContainsString('assetNeedsGapFill', $service);
        self::assertStringContainsString('next_offset', $service);
        self::assertStringContainsString('assetNeedsGapFill($asset)', $service);
        self::assertStringContainsString('aborted_busy', $service);
        self::assertStringContainsString('AI_TRANSLATION_BUSY', $service);
        self::assertStringContainsString("'continuation' => !\$abortedBusy && !\$exhausted", $service);
        self::assertStringContainsString('next_offset', $queue);
        self::assertStringContainsString('aborted_busy', $queue);
        self::assertStringContainsString("empty(\$result['aborted_busy'])", $queue);
        self::assertStringContainsString('不立刻续队', $queue);

        self::assertStringContainsString('FileAssetLocaleTranslationInterface', $module);
        self::assertStringContainsString('FileAssetLocaleTranslationService', $module);
    }
}
