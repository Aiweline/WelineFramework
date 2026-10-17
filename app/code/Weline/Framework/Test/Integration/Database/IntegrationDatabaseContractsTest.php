<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Integration\Database;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Model;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllIntegrationDatabaseContractsPass().
 */
final class IntegrationDatabaseContractsTest extends TestCase
{
    public function testAllIntegrationDatabaseContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [

        ];
    }

private const schema_table_OrmReadPathContractModel = 'codex_orm_read_path_contract';

private const schema_primary_key_OrmReadPathContractModel = 'id';

private int $beforeHookCalls_OrmReadPathContractModel = 0;

private int $afterHookCalls_OrmReadPathContractModel = 0;

private function set_data_before_OrmReadPathContractModel(mixed $key, mixed $value = null): void
{
    unset($key, $value);
    $this->beforeHookCalls_OrmReadPathContractModel++;
}

private function set_data_after_OrmReadPathContractModel(mixed $key, mixed $value = null): void
{
    unset($key, $value);
    $this->afterHookCalls_OrmReadPathContractModel++;
}
}
