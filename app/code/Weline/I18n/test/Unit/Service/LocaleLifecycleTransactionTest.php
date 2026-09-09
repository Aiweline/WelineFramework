<?php
declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocaleLifecycleTransactionTest extends TestCase
{
    public static function lifecycleMethods(): array
    {
        return array_map(static fn(string $method): array => [$method], ['activateCountry', 'deactivateCountry', 'uninstallCountry', 'activateLocale', 'deactivateLocale', 'uninstallLocale']);
    }

    #[DataProvider('lifecycleMethods')]
    public function testLifecycleWritesPublishChangedWithoutBroadClear(string $method): void
    {
        $result = $this->runFixture($method);
        self::assertNull($result['error']);
        self::assertNotEmpty($result['calls']);
        self::assertSame(1, $result['committed']);
        self::assertSame(0, $result['clears']);
        self::assertSame(0, $result['broadcasts']);
        self::assertSame(1, $result['url']);
        self::assertSame(1, $result['catalog']);
        if (str_starts_with($method, 'uninstall')) {
            self::assertSame([['path' => '/tmp/weline-test-language-pack/en_US', 'in_transaction' => false]], $result['deletes']);
        }
    }

    public function testOuterRollbackKeepsLocaleAndLanguagePack(): void
    {
        $result = $this->runFixture('rollback-uninstallLocale');
        self::assertSame('outer_rollback', $result['error']);
        self::assertSame(['is_active' => 1, 'is_install' => 1], $result['locale']);
        self::assertSame([], $result['deletes']);
        self::assertSame(0, $result['url']);
        self::assertSame(0, $result['catalog']);
        self::assertSame(0, $result['change_rows']);
    }

    public function testChangedFailureRollsBackWholeCountryMutation(): void
    {
        $result = $this->runFixture('deactivateCountry-failure');
        self::assertSame('changed_failure', $result['error']);
        self::assertSame(['is_active' => 1, 'is_install' => 1], $result['country']);
        self::assertSame(['is_active' => 1, 'is_install' => 1], $result['locale']);
        self::assertSame(1, $result['locals']['is_active']);
        self::assertSame(0, $result['change_rows']);
        self::assertSame(0, $result['url']);
    }

    public function testLocalsSavePublishesOnlyAfterCommit(): void
    {
        $result = $this->runFixture('locals-save');
        self::assertNull($result['error']);
        self::assertSame('updated', $result['locals']['name']);
        self::assertSame(1, $result['committed']);
        self::assertSame(0, $result['clears']);
        self::assertSame(1, $result['url']);
    }

    public function testLocalsSaveOuterRollbackPreservesDataAndDefersEffects(): void
    {
        $result = $this->runFixture('locals-rollback');
        self::assertSame('outer_rollback', $result['error']);
        self::assertSame('English', $result['locals']['name']);
        self::assertSame(0, $result['committed']);
        self::assertSame(0, $result['clears']);
        self::assertSame(0, $result['url']);
    }

    public function testLocalsSqlFailureDoesNotPublishChanged(): void
    {
        $result = $this->runFixture('locals-sql-failure');
        self::assertNotNull($result['error']);
        self::assertSame([], $result['calls']);
        self::assertSame('English', $result['locals']['name']);
        self::assertSame(0, $result['committed']);
        self::assertSame(0, $result['url']);
    }

    private function runFixture(string $scenario): array
    {
        $fixture = dirname(__DIR__, 2) . '/fixtures/locale-lifecycle-transaction.php';
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' ' . escapeshellarg($scenario) . ' 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
        $result = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($result['transaction_open']);
        return $result;
    }
}
