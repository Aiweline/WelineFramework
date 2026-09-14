<?php
declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class I18nLocaleNamespaceChangeTest extends TestCase
{
    #[DataProvider('trustedWrites')]
    public function testPersistedLocaleWritesInvalidateOnlyThatLocaleAndAggregate(string $scenario, string $locale, int $version): void
    {
        $result = $this->runFixture($scenario);
        self::assertNull($result['error']);
        self::assertSame(['@clock' => $version, 'global/i18n/content' => $version, 'global/i18n/' . $locale => $version], $result['versions']);
        self::assertSame(['global/i18n/content', 'global/i18n/' . $locale], $result['changes'][0]['impact']['namespaces']);
        self::assertCount(1, $result['broadcasts']);
        self::assertFalse($result['broadcasts'][0]['transaction_open']);
    }

    public static function trustedWrites(): array
    {
        return [['upsert', 'en_US', 1], ['ai', 'en_US', 1], ['delete', 'fr_FR', 2], ['file', 'fr_FR', 1]];
    }

    public function testMultipleLocalesCommitOneCompleteDelta(): void
    {
        $result = $this->runFixture('mixed');
        self::assertNull($result['error']);
        self::assertSame([], $result['during']);
        self::assertSame([['clock' => 1, 'changes' => ['global/i18n/content' => 1, 'global/i18n/en_US' => 1, 'global/i18n/fr_FR' => 1], 'transaction_open' => false]], $result['broadcasts']);
        self::assertCount(3, $result['rows']);
    }

    public function testOuterRollbackPublishesNothingAndRollsBackDictionaryAndVersions(): void
    {
        $result = $this->runFixture('rollback');
        self::assertSame('owner_rollback', $result['error']);
        self::assertSame([], $result['during']);
        self::assertSame([], $result['broadcasts']);
        self::assertSame([], $result['versions']);
        self::assertSame([], $result['rows']);
    }

    #[DataProvider('globalWrites')]
    public function testUnknownLegacyAndCatalogWritesKeepGlobalInvalidation(string $scenario): void
    {
        $result = $this->runFixture($scenario);
        self::assertNull($result['error']);
        self::assertSame(['@clock' => 1, 'global/i18n' => 1], $result['versions']);
        self::assertSame(['global/i18n'], $result['changes'][0]['impact']['namespaces']);
        self::assertSame('', $result['changes'][0]['after']['translate'] ?? '');
        self::assertNotContains('translate', $result['changes'][0]['after']['payload_keys']);
    }

    public static function globalWrites(): array { return [['legacy'], ['unknown'], ['catalog'], ['clear']]; }

    public function testMissingDeleteDoesNotPublishAnInvalidation(): void
    {
        $result = $this->runFixture('delete-missing');
        self::assertNull($result['error']);
        self::assertSame([], $result['changes']);
        self::assertSame([], $result['broadcasts']);
        self::assertSame([], $result['versions']);
    }

    private function runFixture(string $scenario): array
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/fixtures/i18n-locale-namespace.php', $scenario], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors . $output);
        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
}
