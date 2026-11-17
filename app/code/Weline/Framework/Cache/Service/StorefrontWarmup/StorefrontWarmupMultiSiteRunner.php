<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Service\StorefrontWarmup;

/**
 * 一次 cron tick 内逐站推进预热：每个站点用独立账本 + 独立 Host 头，
 * 单片 wall 预算按站点数分摊，单片失败不影响其它站点。
 *
 * 生产路径由 defaultFactory() 构造 scoped Orchestrator；单测可注入
 * sliceFactory 直接断言「每站一片 + 预算分摊 + 汇总」。
 */
final class StorefrontWarmupMultiSiteRunner
{
    /** @var callable():list<array{code:string,name:string,host:string,ledger_key:string}> */
    private $scopesProvider;

    /** @var callable(array{code:string,name:string,host:string,ledger_key:string},int):array<string,mixed> */
    private $sliceFactory;

    public function __construct(
        ?callable $scopesProvider = null,
        ?callable $sliceFactory = null,
        private readonly int $designedWallMs = 55000,
    ) {
        $catalog = new StorefrontWarmupScopeCatalog();
        $this->scopesProvider = $scopesProvider ?? static fn(): array => $catalog->all();
        $this->sliceFactory = $sliceFactory ?? self::defaultFactory();
    }

    /** 生产默认：每站 new 一个 scoped Orchestrator（ledger/http 各自独立）。 */
    private static function defaultFactory(): callable
    {
        return static function (array $scope, int $wallMs): array {
            $orchestrator = new StorefrontWarmupOrchestrator(
                ledger: new StorefrontWarmupProgressLedger((string)$scope['ledger_key']),
                http: new StorefrontWarmupHttpClient((string)$scope['host']),
            );

            return $orchestrator->runSlice([
                'source' => 'cron',
                'scope' => (string)$scope['code'],
                'wall_ms' => $wallMs,
            ]);
        };
    }

    /** @return array<string,mixed> */
    public function run(): array
    {
        $scopes = ($this->scopesProvider)();
        if (!\is_array($scopes)) {
            $scopes = [];
        }
        $count = \count($scopes);
        $perScopeWall = StorefrontWarmupOrchestrator::wallBudgetForScopes($this->designedWallMs, $count);

        $warmed = 0;
        $skipped = 0;
        $failed = 0;
        $sites = [];
        foreach ($scopes as $scope) {
            if (!\is_array($scope)) {
                continue;
            }
            $code = (string)($scope['code'] ?? '');
            try {
                $report = ($this->sliceFactory)($scope, $perScopeWall);
                if (!\is_array($report)) {
                    $report = [];
                }
                $w = (int)($report['warmed'] ?? 0);
                $s = (int)($report['skipped'] ?? 0);
                $f = (int)($report['failed'] ?? 0);
                $warmed += $w;
                $skipped += $s;
                $failed += $f;
                $sites[] = [
                    'code' => $code,
                    'name' => (string)($scope['name'] ?? $code),
                    'host' => (string)($scope['host'] ?? ''),
                    'phase' => (string)($report['phase'] ?? ''),
                    'reason' => (string)($report['reason'] ?? ''),
                    'warmed' => $w,
                    'skipped' => $s,
                    'failed' => $f,
                ];
            } catch (\Throwable $e) {
                $failed++;
                $sites[] = [
                    'code' => $code,
                    'name' => (string)($scope['name'] ?? $code),
                    'host' => (string)($scope['host'] ?? ''),
                    'phase' => '',
                    'reason' => 'error:' . $e->getMessage(),
                    'warmed' => 0,
                    'skipped' => 0,
                    'failed' => 1,
                ];
            }
        }

        return [
            'scope_count' => $count,
            'per_scope_wall_ms' => $perScopeWall,
            'warmed' => $warmed,
            'skipped' => $skipped,
            'failed' => $failed,
            'sites' => $sites,
        ];
    }
}
