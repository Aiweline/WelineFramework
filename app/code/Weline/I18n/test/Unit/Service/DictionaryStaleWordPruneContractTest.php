<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class DictionaryStaleWordPruneContractTest extends TestCase
{
    public function testPruneDeletesPhraseNotOnCurrentBatchAndSkipsMeta(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/DictionaryStaleWordPruneService.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("META_PREFIX = '@meta::'", $src);
        self::assertStringContainsString('function pruneNotCurrentBatch(', $src);
        self::assertStringContainsString('alive_too_small', $src);
        self::assertStringContainsString('invalid_current_batch', $src);
        self::assertStringContainsString('{$batchCol} <> ?', $src);
        self::assertStringContainsString('DELETE FROM', $src);
        self::assertStringNotContainsString('DISTINCT word', $src);
        self::assertStringNotContainsString('pruneFromCollectedWords', $src);
    }

    public function testPublisherStripsMetaFromFinalExport(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/AiTranslationPublisher.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("str_starts_with(\$word, '@meta::')", $src);
        self::assertStringContainsString("str_starts_with(\$existingWord, '@meta::')", $src);
        self::assertStringContainsString('unset($words[$existingWord])', $src);
    }
}
