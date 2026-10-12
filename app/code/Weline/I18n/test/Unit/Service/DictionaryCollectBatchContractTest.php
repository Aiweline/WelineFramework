<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class DictionaryCollectBatchContractTest extends TestCase
{
    public function testBatchServiceAllocatesAboveCommittedAndDbMax(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/DictionaryCollectBatchService.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("KEY = 'dictionary_collect_batch'", $src);
        self::assertStringContainsString('function allocateNextBatch(', $src);
        self::assertStringContainsString('function commitBatch(', $src);
        self::assertStringContainsString('function getCommittedBatch(', $src);
        self::assertStringContainsString('maxBatchInDictionary', $src);
        self::assertStringContainsString('schema_fields_COLLECT_BATCH', $src);
    }

    public function testPersistMarksCollectBatchInChunks(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/DictionaryCollectService.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('?int $collectBatch = null', $src);
        self::assertStringContainsString('schema_fields_COLLECT_BATCH', $src);
        self::assertStringContainsString("array_chunk(\$existingToMark, 200)", $src);
        self::assertStringContainsString("'touched'", $src);
    }
}
