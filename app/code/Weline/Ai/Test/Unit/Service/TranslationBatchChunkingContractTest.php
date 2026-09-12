<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\TranslationService;

/**
 * Large batches must be split before a single model call; truncated JSON must not
 * wipe an entire 20/100-item round with "actual 0".
 */
final class TranslationBatchChunkingContractTest extends TestCase
{
    public function testModelChunkConstantAndBatchTranslateUsesChunking(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/TranslationService.php';
        $source = (string)file_get_contents($path);

        self::assertSame(20, TranslationService::MODEL_CHUNK_SIZE);
        self::assertStringContainsString('MODEL_CHUNK_SIZE', $source);
        self::assertStringContainsString('array_chunk', $source);
        self::assertStringContainsString('splitAndRetryBatchTranslate', $source);
        // Multi-item parse failure → binary split retry (not N serial translate()).
        self::assertMatchesRegularExpression(
            '/if \(count\(\$texts\) > 1\) \{\s*return \$this->splitAndRetryBatchTranslate/s',
            $source,
        );
    }

    public function testSalvageCompletePrefixFromTruncatedJsonArray(): void
    {
        $truncated = "```json\n[\n  \"حفظ\",\n  \"إلغاء\",\n  \"بحث\",\n  \"تفعيل\",\n  \"اللغ";
        $parsed = TranslationService::salvageCompleteJsonArrayItems($truncated);
        self::assertSame(['حفظ', 'إلغاء', 'بحث', 'تفعيل'], $parsed);
        // Exact parse still fail-closed for expectedCount mismatch.
        self::assertSame([], TranslationService::parseBatchTranslationResponse($truncated, 20));
    }
}
