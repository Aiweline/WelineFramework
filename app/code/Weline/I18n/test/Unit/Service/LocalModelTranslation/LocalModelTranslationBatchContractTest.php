<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service\LocalModelTranslation;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Service\LocalModelTranslation\LocalModelTranslationService;

final class LocalModelTranslationBatchContractTest extends TestCase
{
    public function testProcessBatchGroupsByLocaleInsteadOfOneStringPerCall(): void
    {
        $path = dirname(__DIR__, 4) . '/Service/LocalModelTranslation/LocalModelTranslationService.php';
        $source = (string)file_get_contents($path);

        self::assertSame(20, LocalModelTranslationService::AI_TEXT_CHUNK_SIZE);
        self::assertStringContainsString('AI_TEXT_CHUNK_SIZE', $source);
        self::assertStringContainsString('indexesByText', $source);
        self::assertStringContainsString('array_chunk(array_keys($indexesByText)', $source);
        // Must not regress to one-string translateBatch inside the per-item locale loop.
        self::assertStringNotContainsString("translateBatch(\n                        [\$sourceText],", $source);
        self::assertStringContainsString('translateBatch(', $source);
        self::assertStringContainsString("'local-model'", $source);
        self::assertStringContainsString('$chunk', $source);
    }
}
