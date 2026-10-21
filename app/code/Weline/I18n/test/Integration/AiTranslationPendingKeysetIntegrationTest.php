<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Integration;

use PHPUnit\Framework\TestCase;

/**
 * The queue's pending-word read must stay a bounded keyset walk over the
 * (locale_code, word) index, not an OFFSET anti-join that re-reads the locale
 * dictionary from the first word on every page.
 *
 * The fixture runs through app/bootstrap.php because PHPUnit's bootstrap
 * forces SANDBOX (sqlite), which cannot execute the PostgreSQL Han regex.
 *
 * @group integration
 */
final class AiTranslationPendingKeysetIntegrationTest extends TestCase
{
    public function testPendingWordsMatchDirectAntiJoin(): void
    {
        $fixture = dirname(__DIR__, 1) . '/fixtures/ai-pending-keyset.php';
        self::assertFileExists($fixture);

        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' 2>&1', $output, $status);
        $raw = implode("\n", $output);
        $result = json_decode($raw, true);
        self::assertIsArray($result, $raw);

        if (!empty($result['skipped'])) {
            self::markTestSkipped((string)($result['reason'] ?? 'pgsql fixture skipped'));
        }

        self::assertSame(0, $status, $raw);
        self::assertTrue($result['ok'] ?? false, $raw);
        self::assertTrue(
            $result['index_present'] ?? false,
            'idx_locale_word is missing; run php bin/w setup:upgrade --model -m Weline_I18n',
        );
        self::assertStringContainsString('locale_code', (string)($result['index_def'] ?? ''));
        self::assertStringContainsString('word', (string)($result['index_def'] ?? ''));
        self::assertGreaterThan(0, $result['words'] ?? 0);
        self::assertSame($result['words'], $result['unique'] ?? null, 'keyset pages must not overlap');
        self::assertSame($result['words'], $result['direct'] ?? null);
        self::assertSame(0, $result['missing'] ?? null);
        self::assertSame(0, $result['extra'] ?? null);
        self::assertSame(0, $result['already_translated'] ?? null);
        self::assertSame($result['count'], $result['direct_count'] ?? null);
    }
}
