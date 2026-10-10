<?php

declare(strict_types=1);

namespace Weline\FileManager\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;

final class FileAssetLocaleAiTranslationCronContractTest extends TestCase
{
    public function testCronCatchesEnqueueFailuresInsteadOfFatalCli(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Cron/FileAssetLocaleAiTranslation.php',
        );

        self::assertStringContainsString('enqueueAutoFill', $source);
        self::assertStringContainsString('catch (\Throwable', $source);
        self::assertStringContainsString('入队异常', $source);
    }
}
