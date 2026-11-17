<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupMultiSiteRunner;

/** cron 逐站循环：每个站点一片，wall 预算按站点数分摊，汇总不虚报。 */
final class StorefrontWarmupMultiSiteRunnerTest extends TestCase
{
    public function testRunsOneSlicePerScope(): void
    {
        $scopes = [
            ['code' => 'default', 'name' => '默认站', 'host' => 'p05113ef3.test.weline.com', 'ledger_key' => 'default'],
            ['code' => 'grocery', 'name' => 'Grocery', 'host' => 'grocery.test.weline.com', 'ledger_key' => 'grocery'],
            ['code' => 'amayum', 'name' => 'Amayum', 'host' => 'amayum.com', 'ledger_key' => 'amayum'],
        ];
        $calls = [];
        $runner = new StorefrontWarmupMultiSiteRunner(
            static fn(): array => $scopes,
            function (array $scope, int $wallMs) use (&$calls): array {
                $calls[] = [$scope['code'], $wallMs];

                return ['warmed' => 2, 'failed' => 0, 'skipped' => 0, 'reason' => 'slice'];
            },
            30000,
        );

        $report = $runner->run();

        self::assertSame(
            [['default', 10000], ['grocery', 10000], ['amayum', 10000]],
            $calls
        );
        self::assertSame(3, $report['scope_count']);
        self::assertSame(6, $report['warmed']);
        self::assertSame(0, $report['failed']);
        self::assertSame(0, $report['skipped']);
        self::assertSame(['default', 'grocery', 'amayum'], \array_column($report['sites'], 'code'));
    }

    public function testContinuesAfterOneScopeThrows(): void
    {
        $scopes = [
            ['code' => 'default', 'name' => '默认站', 'host' => 'a.test', 'ledger_key' => 'default'],
            ['code' => 'grocery', 'name' => 'Grocery', 'host' => 'b.test', 'ledger_key' => 'grocery'],
        ];
        $runner = new StorefrontWarmupMultiSiteRunner(
            static fn(): array => $scopes,
            static function (array $scope, int $wallMs): array {
                if ($scope['code'] === 'grocery') {
                    throw new \RuntimeException('boom');
                }

                return ['warmed' => 1, 'failed' => 0, 'skipped' => 0, 'reason' => 'slice'];
            },
            20000,
        );

        $report = $runner->run();

        self::assertSame(2, $report['scope_count']);
        self::assertSame(1, $report['warmed']);
        self::assertSame(1, $report['failed']);
        self::assertSame('error:boom', $report['sites'][1]['reason']);
    }

    public function testZeroScopesStillReturnsWellFormedReport(): void
    {
        $runner = new StorefrontWarmupMultiSiteRunner(
            static fn(): array => [],
            static fn(array $scope, int $wallMs): array => ['warmed' => 0],
            55000,
        );

        $report = $runner->run();

        self::assertSame(0, $report['scope_count']);
        self::assertSame([], $report['sites']);
        self::assertSame(0, $report['warmed']);
        self::assertSame(0, $report['per_scope_wall_ms']);
    }
}
