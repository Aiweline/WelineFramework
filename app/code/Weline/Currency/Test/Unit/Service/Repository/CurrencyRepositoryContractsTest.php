<?php

declare(strict_types=1);

namespace Weline\Currency\Test\Unit\Service\Repository;

use PHPUnit\Framework\TestCase;
use Weline\Currency\Service\Repository\CurrencyCatalog;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCurrencyRepositoryContractsPass().
 */
final class CurrencyRepositoryContractsTest extends TestCase
{
    public function testAllCurrencyRepositoryContractsPass(): void
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
            'CurrencyCatalogSingleAuthority::testCatalogueProjectsTheDataAuthorityAndOwnsNoQuery' => function () {
            $this->assert_CurrencyCatalogSingleAuthority_testCatalogueProjectsTheDataAuthorityAndOwnsNoQuery();
        },
        ];
    }

private function assert_CurrencyCatalogSingleAuthority_testCatalogueProjectsTheDataAuthorityAndOwnsNoQuery(): void
{
    $source = (string) \file_get_contents(__DIR__ . '/../../../../Service/Repository/CurrencyCatalog.php');

    // 单一权威：启用币种只允许 CurrencyData 一条读路径（进程袋 → w_cache → DB）。
    // 目录类只做 DTO 投影，再写 status 查询就会重新造出“两份成员/两种名称”的漂移。
    self::assertStringContainsString('CurrencyData::getCurrencies()', $source);
    self::assertStringNotContainsString('->select()', $source, 'CurrencyCatalog 不得再自带币种查询');
    self::assertStringNotContainsString('fetchArray', $source, 'CurrencyCatalog 不得再自带币种查询');

    // 失效契约保留：Currency 模型 hook 与 ProcessCacheResetter 都依赖它。
    $clear = new \ReflectionMethod(CurrencyCatalog::class, 'clearProcessCache');
    self::assertTrue($clear->isPublic());
    self::assertTrue($clear->isStatic());
    self::assertStringContainsString('CurrencyData::clearProcessCache()', $source);

    CurrencyCatalog::clearProcessCache();
}
}
