<?php
declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class DictionaryWriteTransactionTest extends TestCase
{
    public function testUpsertAndChangedCommitTogether(): void
    {
        $result = $this->runFixture('upsert');
        self::assertNull($result['error']);
        self::assertTrue($result['result']);
        self::assertSame('after', $result['rows'][0]['translate']);
        self::assertSame(1, $result['change_rows']);
        self::assertSame(1, $result['committed']);
    }

    public function testDeleteAndChangedCommitTogether(): void
    {
        $result = $this->runFixture('delete');
        self::assertNull($result['error']);
        self::assertTrue($result['result']);
        self::assertSame([], $result['rows']);
        self::assertSame(1, $result['change_rows']);
        self::assertSame(1, $result['committed']);
    }

    public function testMissingDeleteDoesNotPublishChanged(): void
    {
        $result = $this->runFixture('delete-missing');
        self::assertNull($result['error']);
        self::assertFalse($result['result']);
        self::assertSame([], $result['calls']);
        self::assertSame(0, $result['committed']);
    }

    public function testSqlFailureDoesNotPublishChanged(): void
    {
        $result = $this->runFixture('sql-failure');
        self::assertNotNull($result['error']);
        self::assertSame([], $result['rows']);
        self::assertSame([], $result['calls']);
        self::assertSame(0, $result['committed']);
    }

    public function testChangedFailureRollsBackDictionaryWrite(): void
    {
        $result = $this->runFixture('changed-failure');
        self::assertSame('changed_failure', $result['error']);
        self::assertSame([], $result['rows']);
        self::assertSame(0, $result['change_rows']);
        self::assertSame(0, $result['committed']);
    }

    public function testOuterTransactionRollbackDoesNotPublishInvalidation(): void
    {
        $result = $this->runFixture('outer-rollback');
        self::assertSame('outer_rollback', $result['error']);
        self::assertCount(1, $result['calls']);
        self::assertSame([], $result['rows']);
        self::assertSame(0, $result['change_rows']);
        self::assertSame(0, $result['committed']);
    }

    public function testAiInsertWritesMetadataAndChangedInOneTransaction(): void
    {
        $result = $this->runFixture('ai-insert');
        self::assertNull($result['error']);
        self::assertSame('after', $result['rows'][0]['translate']);
        self::assertSame(1, $result['rows'][0]['is_ai']);
        self::assertSame('Weline_Product', $result['rows'][0]['source_module']);
        self::assertSame(1, $result['change_rows']);
        self::assertSame(1, $result['committed']);
    }

    public function testNonAiUpdatePreservesExistingAiMetadata(): void
    {
        $result = $this->runFixture('ai-update');
        self::assertNull($result['error']);
        self::assertSame('after', $result['rows'][0]['translate']);
        self::assertSame(1, $result['rows'][0]['is_ai']);
        self::assertSame('Weline_Product', $result['rows'][0]['source_module']);
        self::assertSame(1, $result['change_rows']);
    }

    private function runFixture(string $scenario): array
    {
        $fixture = dirname(__DIR__, 2) . '/fixtures/dictionary-write-transaction.php';
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' ' . escapeshellarg($scenario) . ' 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
        $result = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($result['transaction_open']);
        return $result;
    }
}
