<?php

declare(strict_types=1);

namespace Weline\Theme\Console\Theme;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Theme\Service\Cdn\BudgetedPurgeAdapter;
use Weline\Theme\Service\Cdn\CurlCdnPurgeTransport;
use Weline\Theme\Service\Cdn\GlobalRegionImpactResolver;
use Weline\Theme\Service\Cdn\HttpCdnPurgeAdapter;
use Weline\Theme\Service\Cdn\LogPurgeObserver;
use Weline\Theme\Service\Cdn\PurgeBatchPlanner;
use Weline\Theme\Service\Cdn\PurgeBudgetGate;
use Weline\Theme\Service\Cdn\PurgeWarmup;

/**
 * 一次「变更 → 失效 → 预热」的完整编排。
 *
 * 默认 **dry-run**：只打印影响面、分批计划、预热 URL，不发任何请求。
 * 只有显式 `--live` 且站点配置里配好 zone（endpoint/token）才会真的打 CDN；
 * 没配置时按失败处理并留下续跑点，绝不假装成功。
 */
class CdnPurge implements CommandInterface
{
    public const ALIASES = ['theme:cdn-purge'];

    public function __construct(
        private readonly Printing $printing,
    ) {
    }

    public function command(): string
    {
        return self::ALIASES[0];
    }

    public function tip(): string
    {
        return __('按变更文件执行 CDN 渐进失效（默认 dry-run；--live 才真发请求）');
    }

    public function help(): array
    {
        return [
            'usage' => [
                'php bin/w theme:cdn-purge <path…> --sites=a.test,b.test [--live] [--batch-size=100] [--budget=10]',
            ],
            'note' => __('--live 需要站点配置 cdn.purge.zones 里对应 host 的 endpoint/token'),
        ];
    }

    public function execute(array $args = [], array $options = []): void
    {
        $sitesOption = \trim((string)($options['sites'] ?? ($args['sites'] ?? '')));
        $paths = [];
        foreach ($args as $key => $value) {
            if ($key === 'command' || $key === 'sites' || \is_array($value)) {
                continue;
            }
            $candidate = \trim((string)$value);
            if ($candidate === ''
                || \str_starts_with($candidate, '--')
                || !\str_contains($candidate, '/')
                || \in_array($candidate, self::ALIASES, true)
                || \in_array($candidate, ['live', 'dry-run', 'dry'], true)
                || ($sitesOption !== '' && $candidate === $sitesOption)
            ) {
                continue;
            }
            $paths[] = $candidate;
        }

        if ($paths === []) {
            $this->printing->warning(__('请给出至少一个变更文件路径'));

            return;
        }
        $zones = \array_values(\array_filter(\array_map('trim', \explode(',', $sitesOption))));
        if ($zones === []) {
            $this->printing->warning(__('请用 --sites=a.test,b.test 给出目标站点'));

            return;
        }

        // --live 可能进 $options['live']，也可能作为裸 token 出现在 $args 里，两种都认。
        $live = !empty($options['live'])
            || !empty($args['live'])
            || \in_array('live', \array_map(
                static fn($v): string => \strtolower(\trim((string)$v)),
                \array_filter($args, 'is_string'),
            ), true);
        $batchSize = (int)($options['batch-size'] ?? PurgeBatchPlanner::DEFAULT_BATCH_SIZE);
        $budget = (int)($options['budget'] ?? 10);

        /** @var GlobalRegionImpactResolver $resolver */
        $resolver = ObjectManager::getInstance(GlobalRegionImpactResolver::class);
        $impact = $resolver->resolve($paths);
        /** @var PurgeBatchPlanner $planner */
        $planner = ObjectManager::getInstance(PurgeBatchPlanner::class);
        /** @var PurgeWarmup $warmup */
        $warmup = ObjectManager::getInstance(PurgeWarmup::class);

        $scope = $impact['shared']
            ? __('共享失效（全站）')
            : ($impact['exact'] !== [] ? __('精确失效：' . \implode(', ', $impact['exact'])) : __('无需 HTML 失效'));
        $this->printing->note(__('影响面：') . $scope);
        $this->printing->note(__('模式：') . ($live ? __('LIVE（会真的打 CDN）') : __('dry-run（不发请求）')));

        $plan = $planner->plan($impact, $zones, $batchSize);
        if ($plan === []) {
            $this->printing->note(__('无需 purge（静态改动走 ?v=）'));
        } else {
            $rows = [];
            foreach ($plan as $index => $batch) {
                $rows[] = [
                    (string)$index,
                    $batch['zone'],
                    $batch['mode'],
                    (string)\count($batch['targets']),
                    \implode(' ', \array_slice($batch['targets'], 0, 3)),
                ];
            }
            $this->printing->table([__('批次'), __('zone'), __('模式'), __('目标数'), __('示例目标')], $rows);
        }

        $warmUrls = $warmup->urlsFor($impact, $zones);
        if ($warmUrls !== []) {
            $this->printing->note(__('预热 URL：') . \count($warmUrls));
            foreach (\array_slice($warmUrls, 0, 5) as $url) {
                $this->printing->printing($url);
            }
        }

        if (!$live) {
            $this->printing->note(__('dry-run 结束：加 --live 才会真正执行 purge'));

            return;
        }
        if ($plan === []) {
            return;
        }

        /** @var LogPurgeObserver $observer */
        $observer = ObjectManager::getInstance(LogPurgeObserver::class);
        $observer->record([
            'stage' => 'impact',
            'reason' => $impact['shared'] ? 'shared' : 'exact',
            'count' => \count($paths),
        ]);

        $zonesConfig = $this->zoneConfig();
        $adapter = new HttpCdnPurgeAdapter(new CurlCdnPurgeTransport(), $zonesConfig);
        if ($budget > 0) {
            $adapter = new BudgetedPurgeAdapter($adapter, new PurgeBudgetGate($budget, 60));
        }

        $result = $planner->runGuarded($plan, $adapter);
        foreach ($result['log'] as $entry) {
            $observer->record([
                'stage' => 'purge',
                'zone' => $entry['zone'],
                'mode' => $entry['mode'],
                'reason' => $entry['reason'],
                'count' => 1,
                'extra' => ['index' => $entry['index'], 'ok' => $entry['ok']],
            ]);
        }

        $this->printing->note(__('已完成批次：') . (string)$result['done']);
        if ($result['circuitOpen']) {
            $this->printing->warning(__('熔断：连续失败达阈值，已停止；续跑点 = ') . (string)$result['resumeFrom']);
        } elseif ($result['failed'] !== null) {
            $this->printing->warning(__('失败批次：') . (string)$result['failed'] . __('；续跑点 = ') . (string)$result['resumeFrom']);
        }
    }

    /** @return array<string, array{endpoint:string,token:string,field?:string}> */
    private function zoneConfig(): array
    {
        try {
            $config = \Weline\Framework\App\Env::getInstance()->getConfig('cdn/purge/zones', []);
        } catch (\Throwable) {
            $config = [];
        }

        return \is_array($config) ? $config : [];
    }
}
