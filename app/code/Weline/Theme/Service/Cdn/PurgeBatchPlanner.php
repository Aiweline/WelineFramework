<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Cdn;

/**
 * 把影响面规划成「按站点/zone 分桶 + 分批」的 purge 计划，并按批执行、失败留续跑点。
 *
 * 这一层不触网：真正的清理由 {@see CdnPurgeAdapterInterface} 注入，测试里换 fake 适配器
 * 就能验证分批、失败中断、续跑、幂等与 draft 不推。
 *
 * 约定：
 * - 一个批次只针对一个 zone，避免一次调用跨账户；
 * - `shared` 影响面下每个 zone 只发一条「站点前缀」目标；
 * - `exact` 影响面下发页面路径；
 * - `draft` 批次不推进（预览态不进 CDN）。
 */
final class PurgeBatchPlanner
{
    public const DEFAULT_BATCH_SIZE = 100;

    /**
     * @param array{shared:bool,exact:list<string>,static:bool,prefixes:list<string>} $impact
     * @param list<string> $zones 站点 zone（每站一条）
     *
     * @return list<array{zone:string,targets:list<string>,mode:string,draft:bool}>
     */
    public function plan(array $impact, array $zones, int $batchSize = self::DEFAULT_BATCH_SIZE, bool $draft = false): array
    {
        $batchSize = \max(1, $batchSize);
        $targets = $this->targetsFor($impact);
        if ($targets === []) {
            return [];
        }

        $plan = [];
        foreach ($zones as $zone) {
            $zone = \trim((string)$zone);
            if ($zone === '') {
                continue;
            }
            foreach (\array_chunk($targets, $batchSize) as $chunk) {
                $plan[] = [
                    'zone' => $zone,
                    'targets' => \array_values($chunk),
                    'mode' => ($impact['shared'] ?? false) ? 'shared' : 'exact',
                    'draft' => $draft,
                ];
            }
        }

        return $plan;
    }

    /**
     * 执行计划；遇到失败立即停下并返回续跑点（下一次从这里继续，已成功的不会重发）。
     *
     * @param list<array{zone:string,targets:list<string>,mode:string,draft:bool}> $plan
     *
     * @return array{done:int,failed:?int,resumeFrom:?int,skippedDraft:int,log:list<array{index:int,zone:string,mode:string,ok:bool,reason:string}>}
     */
    public function run(array $plan, CdnPurgeAdapterInterface $adapter, int $resumeFrom = 0): array
    {
        $result = [
            'done' => 0,
            'failed' => null,
            'resumeFrom' => null,
            'skippedDraft' => 0,
            'log' => [],
        ];

        $count = \count($plan);
        for ($index = \max(0, $resumeFrom); $index < $count; $index++) {
            $batch = $plan[$index];
            if (!empty($batch['draft'])) {
                // 预览/草稿不进 CDN；记一条日志便于对账，但不算失败。
                $result['skippedDraft']++;
                $result['log'][] = [
                    'index' => $index,
                    'zone' => (string)$batch['zone'],
                    'mode' => (string)$batch['mode'],
                    'ok' => true,
                    'reason' => 'draft_skipped',
                ];
                continue;
            }

            $ok = $adapter->purge((string)$batch['zone'], (array)$batch['targets']);
            $result['log'][] = [
                'index' => $index,
                'zone' => (string)$batch['zone'],
                'mode' => (string)$batch['mode'],
                'ok' => $ok,
                'reason' => $ok ? 'purged' : 'purge_failed',
            ];

            if (!$ok) {
                $result['failed'] = $index;
                $result['resumeFrom'] = $index;
                break;
            }
            $result['done']++;
        }

        return $result;
    }

    /**
     * 带重试与熔断的执行：单批失败先重试，连续失败超过阈值就熔断停下（保留续跑点）。
     *
     * 与 {@see run()} 的区别只在「失败处理策略」，plan 结构与续跑语义完全一致，
     * 因此部署脚本可以先 run() 再 runGuarded()，或直接用后者。
     *
     * @param list<array{zone:string,targets:list<string>,mode:string,draft:bool}> $plan
     *
     * @return array{done:int,failed:?int,resumeFrom:?int,skippedDraft:int,circuitOpen:bool,attempts:array<int,int>,log:list<array{index:int,zone:string,mode:string,ok:bool,reason:string}>}
     */
    public function runGuarded(
        array $plan,
        CdnPurgeAdapterInterface $adapter,
        int $resumeFrom = 0,
        int $maxAttempts = 2,
        int $breakerThreshold = 3,
    ): array {
        $maxAttempts = \max(1, $maxAttempts);
        $breakerThreshold = \max(1, $breakerThreshold);

        $result = [
            'done' => 0,
            'failed' => null,
            'resumeFrom' => null,
            'skippedDraft' => 0,
            'circuitOpen' => false,
            'attempts' => [],
            'log' => [],
        ];

        $consecutiveFailures = 0;
        $count = \count($plan);
        for ($index = \max(0, $resumeFrom); $index < $count; $index++) {
            $batch = $plan[$index];
            if (!empty($batch['draft'])) {
                $result['skippedDraft']++;
                $result['log'][] = [
                    'index' => $index,
                    'zone' => (string)$batch['zone'],
                    'mode' => (string)$batch['mode'],
                    'ok' => true,
                    'reason' => 'draft_skipped',
                ];
                continue;
            }

            $attempt = 0;
            $ok = false;
            while ($attempt < $maxAttempts) {
                $attempt++;
                $ok = $adapter->purge((string)$batch['zone'], (array)$batch['targets']);
                if ($ok) {
                    break;
                }
            }
            $result['attempts'][$index] = $attempt;
            $result['log'][] = [
                'index' => $index,
                'zone' => (string)$batch['zone'],
                'mode' => (string)$batch['mode'],
                'ok' => $ok,
                'reason' => $ok ? 'purged' : 'purge_failed',
            ];

            if ($ok) {
                $consecutiveFailures = 0;
                $result['done']++;
                continue;
            }

            $consecutiveFailures++;
            if ($result['failed'] === null) {
                // 续跑点取第一次失败的批次：中间成功的批次不用重发。
                $result['failed'] = $index;
                $result['resumeFrom'] = $index;
            }
            if ($consecutiveFailures >= $breakerThreshold) {
                $result['circuitOpen'] = true;
                $result['log'][] = [
                    'index' => $index,
                    'zone' => (string)$batch['zone'],
                    'mode' => (string)$batch['mode'],
                    'ok' => false,
                    'reason' => 'circuit_open',
                ];
                // 连续失败到阈值 = 上游不健康，停止继续打。
                break;
            }
        }

        return $result;
    }

    /**
     * @param array{shared:bool,exact:list<string>,static:bool,prefixes:list<string>} $impact
     *
     * @return list<string>
     */
    private function targetsFor(array $impact): array
    {
        if (($impact['shared'] ?? false) === true) {
            // 共享失效：每个 zone 只发一条全站前缀，避免把几千个页面路径塞进一次请求。
            return ['/'];
        }

        $targets = [];
        foreach (($impact['prefixes'] ?? []) as $prefix) {
            $prefix = \trim((string)$prefix);
            if ($prefix !== '') {
                $targets[] = $prefix;
            }
        }
        foreach (($impact['exact'] ?? []) as $pageType) {
            $pageType = \trim((string)$pageType);
            if ($pageType !== '') {
                $targets[] = '/' . \ltrim($pageType, '/');
            }
        }

        return \array_values(\array_unique($targets));
    }
}
