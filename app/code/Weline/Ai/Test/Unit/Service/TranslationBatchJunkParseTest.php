<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\TranslationService;

/**
 * Guards against incomplete JSON / punctuation fragments being accepted as translations
 * (e.g. Bengali Local field stored as "[" after a truncated batch reply).
 */
final class TranslationBatchJunkParseTest extends TestCase
{
    public function testLoneOpeningBracketIsJunk(): void
    {
        self::assertTrue(TranslationService::isJunkTranslation('['));
        self::assertTrue(TranslationService::isJunkTranslation(']'));
        self::assertTrue(TranslationService::isJunkTranslation('{'));
        self::assertTrue(TranslationService::isJunkTranslation('  [  '));
        self::assertFalse(TranslationService::isJunkTranslation('VIP Level 0 (Basic)'));
        self::assertFalse(TranslationService::isJunkTranslation('ভিআইপি০ প্রাথমিক'));
        self::assertFalse(TranslationService::isJunkTranslation(''));
    }

    public function testParseBatchRejectsLoneBracketWhenExpectingOne(): void
    {
        self::assertSame([], TranslationService::parseBatchTranslationResponse('[', 1));
        self::assertSame([], TranslationService::parseBatchTranslationResponse("[\n", 1));
        self::assertSame([], TranslationService::parseBatchTranslationResponse(']', 1));
    }

    public function testParseBatchRejectsIncompleteJsonArray(): void
    {
        self::assertSame([], TranslationService::parseBatchTranslationResponse('["ভিআইপি০', 1));
        self::assertSame([], TranslationService::parseBatchTranslationResponse('["a","b"', 2));
    }

    public function testParseBatchAcceptsValidJsonArray(): void
    {
        self::assertSame(
            ['VIP Level 0 (Basic)'],
            TranslationService::parseBatchTranslationResponse('["VIP Level 0 (Basic)"]', 1),
        );
        self::assertSame(
            ['আরবি', 'বাংলা'],
            TranslationService::parseBatchTranslationResponse('["আরবি","বাংলা"]', 2),
        );
    }

    public function testParseBatchRejectsArrayWithJunkItem(): void
    {
        self::assertSame([], TranslationService::parseBatchTranslationResponse('["["]', 1));
        self::assertSame([], TranslationService::parseBatchTranslationResponse('["ok","["]', 2));
    }
}
