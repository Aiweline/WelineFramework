<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Phrase\GlobalDictionaryModuleBagCache;
use Weline\Framework\Phrase\MsWriteBudget;
use Weline\Framework\Phrase\Parser;

/**
 * ACC-W3-MS-CHUNK / UC-W3-01：禁整 locale 进 MS；chunk 帧长 ≤ SessionProtocol。
 */
final class AccW3MsChunkContractTest extends TestCase
{
    public function testMsWriteBudgetFrameMatchesSessionProtocol(): void
    {
        self::assertSame(12_582_912, MsWriteBudget::FRAME_BUDGET_BYTES);
        $sessionProtocol = 'Weline\\Server\\Session\\Server\\SessionProtocol';
        self::assertTrue(\class_exists($sessionProtocol));
        self::assertSame(
            (int)$sessionProtocol::maxFrameBytes(),
            MsWriteBudget::maxFrameBytes(),
            'Phrase MS frame budget must equal SessionProtocol::maxFrameBytes()'
        );
    }

    public function testForbidsWholeLocaleBagKeysAndGeneratedLanguagePaths(): void
    {
        $localeKey = GlobalDictionaryModuleBagCache::nullSourceSharedKey('hi_IN');
        self::assertTrue(MsWriteBudget::isForbiddenBagKey($localeKey));
        self::assertTrue(MsWriteBudget::isForbiddenBagKey('generated/language/hi_IN.php'));
        self::assertTrue(
            GlobalDictionaryModuleBagCache::wouldWriteForbiddenBagToSharedMemory(
                $localeKey,
                ['a' => 'b']
            )
        );
        self::assertFalse(
            MsWriteBudget::isForbiddenBagWrite(
                'global_dictionary_word|v1|abc',
                ['found' => true, 'translation' => 'नमस्ते']
            )
        );
    }

    public function testForbidsLargeStringMapBagValues(): void
    {
        $bag = [];
        for ($i = 0; $i < 80; $i++) {
            $bag['k' . $i] = 'v' . $i;
        }
        self::assertTrue(MsWriteBudget::isForbiddenBagValue($bag));
        self::assertSame(
            ['global_dictionary_word|v1|ok' => ['found' => true, 'translation' => 'ok']],
            MsWriteBudget::filterAllowedWordRecords([
                'global_dictionary_module_words|hi_IN|v1|x' => $bag,
                'global_dictionary_word|v1|ok' => ['found' => true, 'translation' => 'ok'],
            ])
        );
    }

    public function testPartitionKeepsWordBatchesWithinSessionProtocolFrame(): void
    {
        $records = [];
        for ($i = 0; $i < 500; $i++) {
            $records['global_dictionary_word|v1|' . \sha1((string)$i)] = [
                'found' => true,
                'translation' => 't' . $i,
            ];
        }
        $batches = MsWriteBudget::partitionWordRecords($records);
        self::assertNotEmpty($batches);
        $maxFrame = MsWriteBudget::maxFrameBytes();
        foreach ($batches as $batch) {
            self::assertLessThanOrEqual(MsWriteBudget::WORD_BATCH_MAX_ITEMS, \count($batch));
            self::assertLessThanOrEqual(
                $maxFrame,
                MsWriteBudget::estimateMsetFrameBytes($batch),
                'each phrase MS chunk must fit SessionProtocol max frame'
            );
        }
    }

    public function testParserPrefetchUsesMsWriteBudgetPartitionNotBareSetMultiple(): void
    {
        $src = (string)\file_get_contents(
            (string)(new \ReflectionClass(Parser::class))->getFileName()
        );
        self::assertStringContainsString('MsWriteBudget::partitionWordRecords', $src);
        self::assertStringContainsString('MsWriteBudget::WORD_BATCH_MAX_ITEMS', $src);
        self::assertStringContainsString('整袋不再读写 Shared Memory', $src);
    }

    public function testBagCachePutPathNeverCallsPoolSet(): void
    {
        $src = (string)\file_get_contents(
            (string)(new \ReflectionClass(GlobalDictionaryModuleBagCache::class))->getFileName()
        );
        self::assertStringContainsString('processBags', $src);
        self::assertStringNotContainsString('$pool->set(', $src);
        self::assertStringNotContainsString('$pool->setMultiple(', $src);
        self::assertStringContainsString('wouldWriteForbiddenBagToSharedMemory', $src);
    }
}
