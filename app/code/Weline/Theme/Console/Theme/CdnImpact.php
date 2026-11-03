<?php

declare(strict_types=1);

namespace Weline\Theme\Console\Theme;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Theme\Service\Cdn\GlobalRegionImpactResolver;

/**
 * 变更文件 → CDN 失效影响面（只判定，不执行 purge）。
 *
 * 部署脚本/CI 用它决定「这次改动该清全站还是只清某几个页面类型」，
 * 真正 purge 由适配器执行（需线上凭据，本机不跑）。
 */
class CdnImpact implements CommandInterface
{
    public const ALIASES = ['theme:cdn-impact'];

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
        return __('按变更文件判定 CDN 失效影响面（共享 / 精确页面 / 静态）');
    }

    public function help(): array
    {
        return [
            'usage' => ['php bin/w theme:cdn-impact <path> [<path> …]'],
            'note' => __('只做判定，不执行 purge'),
        ];
    }

    public function execute(array $args = [], array $options = []): void
    {
        // --sites 既可能进 $options，也可能作为裸参数出现，两种都认。
        $sitesOption = \trim((string)($options['sites'] ?? ($args['sites'] ?? '')));

        $paths = [];
        foreach ($args as $key => $value) {
            if ($key === 'command' || $key === 'sites' || \is_array($value)) {
                continue;
            }
            $candidate = \trim((string)$value);
            // 命令行自身、--option 形式、以及 --sites 的取值都不能当变更路径。
            if ($candidate === ''
                || \str_starts_with($candidate, '--')
                || \in_array($candidate, self::ALIASES, true)
                || ($sitesOption !== '' && $candidate === $sitesOption)
                || \preg_match('/^[a-z0-9.-]+\.[a-z]{2,}(,[a-z0-9.-]+\.[a-z]{2,})+$/i', $candidate) === 1
            ) {
                continue;
            }
            $paths[] = $candidate;
        }

        if ($paths === []) {
            $this->printing->warning(__('请给出至少一个变更文件路径'));

            return;
        }

        /** @var GlobalRegionImpactResolver $resolver */
        $resolver = ObjectManager::getInstance(GlobalRegionImpactResolver::class);
        $result = $resolver->resolve($paths);

        $scope = $result['shared']
            ? __('共享失效：全站页面')
            : ($result['exact'] !== []
                ? __('精确失效：' . \implode(', ', $result['exact']))
                : __('无需 HTML 失效'));

        $this->printing->note(__('影响面：') . $scope);
        $this->printing->note(__('静态资源走内容哈希（?v=）：') . ($result['static'] ? __('是') : __('否')));

        $rows = [];
        foreach ($result['reasons'] as $path => $reason) {
            $rows[] = [$path, $reason];
        }
        if ($rows !== []) {
            $this->printing->table([__('变更文件'), __('判定')], $rows);
        }

        // --sites=a.test,b.test：只打印 purge 计划（按站点/zone 分桶 + 分批），不触网。
        $sites = $sitesOption;
        if ($sites !== '') {
            $zones = \array_values(\array_filter(\array_map('trim', \explode(',', $sites))));
            $batchSize = (int)($options['batch-size'] ?? \Weline\Theme\Service\Cdn\PurgeBatchPlanner::DEFAULT_BATCH_SIZE);
            /** @var \Weline\Theme\Service\Cdn\PurgeBatchPlanner $planner */
            $planner = ObjectManager::getInstance(\Weline\Theme\Service\Cdn\PurgeBatchPlanner::class);
            $plan = $planner->plan($result, $zones, $batchSize);

            if ($plan === []) {
                $this->printing->note(__('无需 purge（静态改动走 ?v=）'));

                return;
            }
            $planRows = [];
            foreach ($plan as $index => $batch) {
                $planRows[] = [
                    (string)$index,
                    $batch['zone'],
                    $batch['mode'],
                    (string)\count($batch['targets']),
                    \implode(' ', \array_slice($batch['targets'], 0, 3)),
                ];
            }
            $this->printing->table(
                [__('批次'), __('zone'), __('模式'), __('目标数'), __('示例目标')],
                $planRows,
            );
        }
    }
}
