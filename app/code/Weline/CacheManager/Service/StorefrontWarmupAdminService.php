<?php

declare(strict_types=1);

namespace Weline\CacheManager\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\Namespace\NamespaceGenerationRepository;
use Weline\Framework\Cache\Service\StorefrontWarmup\HostLoadSampler;
use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupBusyGate;
use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupCapacityPlanner;
use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupProgressLedger;
use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupScopeCatalog;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeControlBroadcasterInterface;

/**
 * 店面自动预热（FPC）后台看板数据与负载算法参数。
 *
 * 进度唯一事实来源是 Framework 的 StorefrontWarmupProgressLedger
 * （var/cache/storefront_warmup_progress.json），这里只读，不推进、不重置账本。
 * 参数写 app/etc/env.php 的 framework.cache.storefront_warmup_*，
 * 预热由 CLI cron（bin/w cron:task:run）读取，下一次 tick 生效。
 */
final class StorefrontWarmupAdminService
{
    public const ENV_BUSY_START_PCT = 'framework.cache.storefront_warmup_busy_start_pct';
    public const ENV_BUSY_ABORT_PCT = 'framework.cache.storefront_warmup_busy_abort_pct';
    public const ENV_CONCURRENCY = 'framework.cache.storefront_warmup_concurrency';
    public const ENV_SHARD = 'framework.cache.storefront_warmup_shard';
    public const ENV_WALL_MS = 'framework.cache.storefront_warmup_wall_ms';

    /** cron 每 5 分钟推进一片，超过该秒数没有更新即视为停滞。 */
    private const STALE_SECONDS = 900;

    private const LOCK_FILE = 'var/cache/storefront_warmup_orchestrator.lock';

    public function __construct(
        private readonly StorefrontWarmupProgressLedger $ledger = new StorefrontWarmupProgressLedger(),
        private readonly StorefrontWarmupBusyGate $busyGate = new StorefrontWarmupBusyGate(),
        private readonly StorefrontWarmupCapacityPlanner $planner = new StorefrontWarmupCapacityPlanner(),
        private readonly HostLoadSampler $hostLoad = new HostLoadSampler(),
        private readonly ?StorefrontWarmupScopeCatalog $scopeCatalog = null,
    ) {
    }

    /**
     * 后台看板：scope 为 all/空时返回「全部站点」聚合视图（总体进度 + 逐站列表），
     * 为某站 code 时返回该站明细视图。页面选择只影响视图，不影响 cron（cron 恒跑全部站点）。
     *
     * @return array<string, mixed>
     */
    public function status(?string $scope = null): array
    {
        $catalog = $this->scopeCatalog ?? new StorefrontWarmupScopeCatalog();
        $normalized = self::normalizeScope((string)$scope, $catalog);
        $sample = $this->busyGate->sample();
        $now = \time();
        $lockFree = $this->probeLockFree();

        if ($normalized !== StorefrontWarmupScopeCatalog::SCOPE_ALL) {
            $scopeRow = $catalog->resolve($normalized);
            $scoped = new self(
                ledger: new StorefrontWarmupProgressLedger((string)($scopeRow['ledger_key'] ?? $normalized)),
                busyGate: $this->busyGate,
                planner: $this->planner,
                hostLoad: $this->hostLoad,
                scopeCatalog: $catalog,
            );
            $detail = $scoped->buildSingleStatus($sample, $lockFree, $now);
            $detail['scope'] = [
                'value' => $normalized,
                'is_all' => false,
                'name' => (string)($scopeRow['name'] ?? $normalized),
                'host' => (string)($scopeRow['host'] ?? ''),
                'options' => self::scopeOptions($catalog->all()),
            ];
            $detail['sites'] = [];

            return $detail;
        }

        $sites = [];
        foreach ($catalog->all() as $row) {
            $scoped = new self(
                ledger: new StorefrontWarmupProgressLedger((string)$row['ledger_key']),
                busyGate: $this->busyGate,
                planner: $this->planner,
                hostLoad: $this->hostLoad,
                scopeCatalog: $catalog,
            );
            $detail = $scoped->buildSingleStatus($sample, $lockFree, $now);
            $sites[] = [
                'code' => (string)$row['code'],
                'name' => (string)$row['name'],
                'host' => (string)$row['host'],
                'phase' => (string)$detail['phase'],
                'phase_label' => (string)$detail['phase_label'],
                'progress' => $detail['progress'],
                'current_group' => $detail['current_group'],
                'last' => $detail['last'],
                'totals' => $detail['totals'],
                'verdict' => $detail['verdict'],
            ];
        }

        $capacity = $this->planner->plan($this->ledger->read(), $sample);
        $base = $this->buildStatus($this->ledger->read(), $sample, $capacity, $lockFree, $now);
        $aggregate = self::aggregateSites($sites);
        $aggregate['generated_at'] = \gmdate('c', $now);
        $aggregate['scope'] = [
            'value' => StorefrontWarmupScopeCatalog::SCOPE_ALL,
            'is_all' => true,
            'name' => (string)__('全部站点'),
            'host' => '',
            'options' => self::scopeOptions($catalog->all()),
        ];
        $aggregate['phases'] = $base['phases'];
        $aggregate['load'] = $base['load'];
        $aggregate['cron_gate'] = $base['cron_gate'];
        $aggregate['settings'] = $this->settings();
        $aggregate['current_group'] = ['label' => '', 'item' => '', 'position' => ''];
        $aggregate['last'] = ['url' => '', 'status' => '', 'reason' => '', 'updated_at' => '', 'age_seconds' => null];
        $aggregate['generation'] = $base['generation'];

        return $aggregate;
    }

    /** 单站视图：复用既有 buildStatus，挂到该站账本。 */
    private function buildSingleStatus(array $sample, bool $lockFree, int $now): array
    {
        $state = $this->ledger->read();
        $capacity = $this->planner->plan($state, $sample);

        return $this->buildStatus($state, $sample, $capacity, $lockFree, $now);
    }

    /**
     * 纯函数：把逐站状态折成「全部站点」聚合；done/total 逐站求和，不重复计。
     *
     * @param list<array<string,mixed>> $sites
     * @return array<string,mixed>
     */
    public static function aggregateSites(array $sites): array
    {
        $done = 0;
        $total = 0;
        $remaining = 0;
        $ok = 0;
        $skip = 0;
        $fail = 0;
        $worst = 'never';
        $rank = ['never' => 0, 'done' => 1, 'queued' => 2, 'running' => 3, 'needs_rewarm' => 4, 'stalled' => 5];
        foreach ($sites as $site) {
            if (!\is_array($site)) {
                continue;
            }
            $progress = \is_array($site['progress'] ?? null) ? $site['progress'] : [];
            $totals = \is_array($site['totals'] ?? null) ? $site['totals'] : [];
            $done += (int)($progress['done'] ?? 0);
            $total += (int)($progress['total'] ?? 0);
            $remaining += (int)($progress['remaining'] ?? 0);
            $ok += (int)($totals['ok'] ?? 0);
            $skip += (int)($totals['skip'] ?? 0);
            $fail += (int)($totals['fail'] ?? 0);
            $state = (string)(($site['verdict']['state'] ?? 'never'));
            if (($rank[$state] ?? 0) > ($rank[$worst] ?? 0)) {
                $worst = $state;
            }
        }

        return [
            'progress' => [
                'done' => $done,
                'total' => $total,
                'percent' => $total > 0 ? \round($done * 100 / $total, 1) : 0.0,
                'remaining' => $remaining,
            ],
            'totals' => ['ok' => $ok, 'skip' => $skip, 'fail' => $fail],
            'verdict' => [
                'state' => $worst,
                'label' => (string)__('全部站点'),
                'detail' => (string)__('共 %{1} 个站点，cron 每 5 分钟逐站推进一片', [\count($sites)]),
            ],
            'sites' => $sites,
        ];
    }

    /**
     * @param list<array{code:string,name:string,host:string,ledger_key:string}> $scopes
     * @return list<array{value:string,label:string}>
     */
    public static function scopeOptions(array $scopes): array
    {
        $options = [[
            'value' => StorefrontWarmupScopeCatalog::SCOPE_ALL,
            'label' => (string)__('全部站点'),
        ]];
        foreach ($scopes as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $code = (string)($row['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $options[] = [
                'value' => $code,
                'label' => (string)($row['name'] ?? $code),
            ];
        }

        return $options;
    }

    /** 未知 / 空 scope 一律回退到 all，避免 500 或读错账本。 */
    public static function normalizeScope(string $scope, StorefrontWarmupScopeCatalog $catalog): string
    {
        $scope = \strtolower(\trim($scope));
        if ($scope === '' || $scope === StorefrontWarmupScopeCatalog::SCOPE_ALL) {
            return StorefrontWarmupScopeCatalog::SCOPE_ALL;
        }

        return $catalog->resolve($scope) !== null
            ? $scope
            : StorefrontWarmupScopeCatalog::SCOPE_ALL;
    }

    /**
     * 纯函数：把账本 + 负载样本 + 容量计划折成页面视图，便于单测。
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $sample
     * @param array<string, mixed> $capacity
     * @return array<string, mixed>
     */
    public function buildStatus(array $state, array $sample, array $capacity, bool $lockFree, int $now, ?array $cronGate = null): array
    {
        $phase = (string)($state['phase'] ?? StorefrontWarmupProgressLedger::PHASE_A_HOMEPAGE);
        $phases = $this->phaseRows($state, $phase);
        $totalDone = 0;
        $totalAll = 0;
        foreach ($phases as $row) {
            $totalDone += (int)$row['done'];
            $totalAll += (int)$row['total'];
        }
        $percent = $totalAll > 0 ? \round($totalDone * 100 / $totalAll, 1) : ($phase === StorefrontWarmupProgressLedger::PHASE_DONE ? 100.0 : 0.0);
        $updatedAt = \trim((string)($state['updated_at'] ?? ''));
        $updatedTs = $updatedAt !== '' ? (int)\strtotime($updatedAt) : 0;
        $age = $updatedTs > 0 ? \max(0, $now - $updatedTs) : null;
        $needsRewarm = (bool)($state['needs_rewarm'] ?? false);
        $currentClock = $this->currentAuthorityClock();
        $ratio = (float)($sample['ratio'] ?? 0.0);
        $band = (string)($capacity['band'] ?? StorefrontWarmupCapacityPlanner::BAND_FULL);
        $verdict = $this->verdict($phase, $needsRewarm, $lockFree, $age, $state);
        $cronGate = $cronGate ?? $this->cronGate();
        // 高负载让出 / cron 整轮被跳过时把原因写进判定详情：否则「需要预热」看起来像卡住不动。
        if (!\in_array($verdict['state'], ['running', 'done'], true)) {
            if ($cronGate['available'] && $cronGate['blocked']) {
                $verdict['detail'] = (string)__('%{1}；计划任务门禁：负载 %{2}% ≥ 上限 %{3}%，整轮 cron 已被跳过，预热不会推进', [
                    $verdict['detail'],
                    $cronGate['percent'],
                    $cronGate['max_percent'],
                ]);
            } elseif ($band === StorefrontWarmupCapacityPlanner::BAND_YIELD) {
                $verdict['detail'] = (string)__('%{1}；当前负载 %{2}% ≥ 中止阈值 %{3}%，本片只会让出，预热不会推进', [
                    $verdict['detail'],
                    \round($ratio * 100, 1),
                    \round((float)($sample['abort_threshold'] ?? 0.7) * 100, 1),
                ]);
            }
        }

        return [
            'generated_at' => \gmdate('c', $now),
            'phase' => $phase,
            'phase_label' => $this->phaseLabel($phase),
            'phases' => $phases,
            'progress' => [
                'done' => $totalDone,
                'total' => $totalAll,
                'percent' => $percent,
                'remaining' => (int)($capacity['remaining'] ?? 0),
            ],
            'current_group' => $this->currentGroup($state, $phase),
            'last' => [
                'url' => (string)($state['last_url'] ?? ''),
                'status' => (string)($state['last_status'] ?? ''),
                'reason' => (string)($state['last_reason'] ?? ''),
                'updated_at' => $updatedAt,
                'age_seconds' => $age,
            ],
            'totals' => [
                'ok' => (int)($state['ok'] ?? 0),
                'skip' => (int)($state['skip'] ?? 0),
                'fail' => (int)($state['fail'] ?? 0),
            ],
            'verdict' => $verdict,
            'generation' => [
                'fingerprint' => (string)($state['storefront_generation_fingerprint'] ?? ''),
                'short' => $this->shortHash((string)($state['storefront_generation_fingerprint'] ?? '')),
                'authority_clock' => (int)($state['generation_authority_clock'] ?? 0),
                'authority_clock_current' => $currentClock,
                'clock_advance' => \max(0, $currentClock - (int)($state['generation_authority_clock'] ?? 0)),
                'needs_rewarm' => $needsRewarm,
                'reset_reason' => $needsRewarm ? (string)($state['last_reason'] ?? '') : '',
            ],
            'load' => [
                'available' => (bool)($sample['available'] ?? ($sample['source'] ?? '') !== 'unavailable'),
                'source' => (string)($sample['source'] ?? ''),
                'source_label' => $this->sourceLabel((string)($sample['source'] ?? '')),
                'cores' => (int)($sample['cores'] ?? 0),
                'load1' => \round((float)($sample['load1'] ?? 0.0), 3),
                'ratio' => \round($ratio, 4),
                'ratio_percent' => \round($ratio * 100, 1),
                'start_percent' => \round((float)($sample['start_threshold'] ?? 0.4) * 100, 1),
                'abort_percent' => \round((float)($sample['abort_threshold'] ?? 0.7) * 100, 1),
                'idle_percent' => \round(\max(0.0, 1.0 - $ratio) * 100, 1),
                'band' => $band,
                'band_label' => $this->bandLabel($band),
                'designed_concurrency' => (int)($capacity['designed_concurrency'] ?? 0),
                'designed_shard' => (int)($capacity['designed_shard'] ?? 0),
                'designed_wall_ms' => (int)($capacity['designed_wall_ms'] ?? 0),
                'effective_concurrency' => (int)($capacity['effective_concurrency'] ?? 0),
                'effective_shard' => (int)($capacity['effective_shard'] ?? 0),
                'effective_wall_ms' => (int)($capacity['effective_wall_ms'] ?? 0),
                'scale_factor' => \round((float)($capacity['scale_factor'] ?? 1.0), 3),
            ],
            'cron_gate' => $cronGate,
            'settings' => $this->settings(),
        ];
    }

    /**
     * @return array<string, array{value:int,default:int,min:int,max:int}>
     */
    public function settings(): array
    {
        $cores = \max(1, $this->hostLoad->coreCount());
        $defaultConcurrency = \max(2, \min($cores, 12));
        $defaultShard = \min(256, $defaultConcurrency * 16);

        return [
            'busy_start_pct' => $this->setting(Env::get(self::ENV_BUSY_START_PCT, 40), 40, 1, 99),
            'busy_abort_pct' => $this->setting(Env::get(self::ENV_BUSY_ABORT_PCT, 70), 70, 1, 99),
            'concurrency' => $this->setting(Env::get(self::ENV_CONCURRENCY, $defaultConcurrency), $defaultConcurrency, 1, 12),
            'shard' => $this->setting(Env::get(self::ENV_SHARD, $defaultShard), $defaultShard, 1, 256),
            'wall_ms' => $this->setting(Env::get(self::ENV_WALL_MS, 55000), 55000, 5000, 180000),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool,message:string,status?:array<string,mixed>,errors?:list<string>}
     */
    public function save(array $input): array
    {
        $validated = $this->validate($input);
        if ($validated['errors'] !== []) {
            return [
                'success' => false,
                'message' => (string)__('参数未通过校验，未写入任何配置'),
                'errors' => $validated['errors'],
            ];
        }

        $written = 0;
        foreach ($validated['values'] as $envKey => $value) {
            if (Env::getInstance()->setConfig($envKey, $value)) {
                $written++;
            }
        }
        if ($written !== \count($validated['values'])) {
            return [
                'success' => false,
                'message' => (string)__('env 配置写入失败，请检查 app/etc/env.php 权限'),
            ];
        }

        $sync = $this->broadcastConfigReload();

        return [
            'success' => true,
            'message' => $sync
                ? (string)__('预热负载参数已保存并同步所有 Worker，下一次 cron tick 生效')
                : (string)__('预热负载参数已保存，下一次 cron tick 生效（Worker 同步未确认）'),
            'worker_sync' => $sync,
            'status' => $this->status(),
        ];
    }

    /**
     * env.php 是各 WLS Worker 启动时记忆的：只写盘不广播，刷新页面会被别的
     * Worker 用旧阈值回显（实测：env 已 90，页面仍显示 70）。
     * cacheClear 控制命令内部会 reloadPersistentConfigFromDisk() + 清 ObjectManager，
     * 是现有的 env 生效通道（FullPageCacheAdminService 同做法）。
     */
    private function broadcastConfigReload(): bool
    {
        try {
            $broadcaster = ObjectManager::getInstance(RuntimeControlBroadcasterInterface::class);
            if (!$broadcaster instanceof RuntimeControlBroadcasterInterface) {
                return false;
            }
            if (\method_exists($broadcaster, 'cacheClearAndWait')) {
                $broadcaster->cacheClearAndWait(null, 5.0);

                return true;
            }
            $broadcaster->cacheClear(null);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{values:array<string,int>,errors:list<string>}
     */
    public function validate(array $input): array
    {
        $specs = [
            'busy_start_pct' => [self::ENV_BUSY_START_PCT, 1, 99, (string)__('负载起控阈值')],
            'busy_abort_pct' => [self::ENV_BUSY_ABORT_PCT, 1, 99, (string)__('负载中止阈值')],
            'concurrency' => [self::ENV_CONCURRENCY, 1, 12, (string)__('并发数')],
            'shard' => [self::ENV_SHARD, 1, 256, (string)__('每片批量')],
            'wall_ms' => [self::ENV_WALL_MS, 5000, 180000, (string)__('单片时长上限')],
        ];

        $errors = [];
        $values = [];
        foreach ($specs as $field => [$envKey, $min, $max, $label]) {
            $raw = $input[$field] ?? null;
            if ($raw === null || $raw === '') {
                continue;
            }
            if (!\is_numeric($raw) || (string)(int)$raw !== \trim((string)$raw)) {
                $errors[] = (string)__('%{1}必须是整数', $label);
                continue;
            }
            $value = (int)$raw;
            if ($value < $min || $value > $max) {
                $errors[] = (string)__('%{1}必须在 %{2}–%{3} 之间', [$label, $min, $max]);
                continue;
            }
            $values[$envKey] = $value;
        }

        $start = $values[self::ENV_BUSY_START_PCT] ?? null;
        $abort = $values[self::ENV_BUSY_ABORT_PCT] ?? null;
        if ($start !== null && $abort !== null && $abort < $start) {
            $errors[] = (string)__('负载中止阈值必须不小于起控阈值');
        }

        return $errors === []
            ? ['values' => $values, 'errors' => []]
            : ['values' => [], 'errors' => $errors];
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, array{key:string,label:string,done:int,total:int,percent:float,current:bool}>
     */
    private function phaseRows(array $state, string $phase): array
    {
        $order = [
            StorefrontWarmupProgressLedger::PHASE_A_HOMEPAGE,
            StorefrontWarmupProgressLedger::PHASE_B_SITEMAP_DOCS,
            StorefrontWarmupProgressLedger::PHASE_C_EXTRAS,
            StorefrontWarmupProgressLedger::PHASE_D_LOCS,
        ];
        $currentIndex = \array_search($phase, $order, true);
        $rows = [];
        foreach ($order as $index => $key) {
            [$list, $cursor] = match ($key) {
                StorefrontWarmupProgressLedger::PHASE_A_HOMEPAGE => [
                    (array)($state['priority_homes'] ?? []),
                    (int)($state['priority_home_cursor'] ?? 0),
                ],
                StorefrontWarmupProgressLedger::PHASE_B_SITEMAP_DOCS => [
                    (array)($state['sitemap_docs'] ?? []),
                    (int)($state['sitemap_doc_cursor'] ?? 0),
                ],
                StorefrontWarmupProgressLedger::PHASE_C_EXTRAS => [
                    (array)($state['extras'] ?? []),
                    (int)($state['extras_cursor'] ?? 0),
                ],
                default => [
                    (array)($state['locs'] ?? []),
                    (int)($state['loc_cursor'] ?? 0),
                ],
            };
            $total = \count($list);
            if ($key === StorefrontWarmupProgressLedger::PHASE_A_HOMEPAGE && $total === 0) {
                // 相位 A 至少包含一次首页探测，即便优先首页队列为空。
                $total = 1;
            }
            $done = $cursor;
            if ($currentIndex === false || $index < $currentIndex) {
                $done = $total;
            }
            $done = \max(0, \min($done, $total));

            $rows[$key] = [
                'key' => $key,
                'label' => $this->phaseLabel($key),
                'done' => $done,
                'total' => $total,
                'percent' => $total > 0 ? \round($done * 100 / $total, 1) : 0.0,
                'current' => $key === $phase,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $state
     * @return array{label:string,item:string,position:string}
     */
    private function currentGroup(array $state, string $phase): array
    {
        $map = [
            StorefrontWarmupProgressLedger::PHASE_A_HOMEPAGE => ['priority_homes', 'priority_home_cursor', (string)__('优先首页（语种根）')],
            StorefrontWarmupProgressLedger::PHASE_B_SITEMAP_DOCS => ['sitemap_docs', 'sitemap_doc_cursor', (string)__('Sitemap 文档')],
            StorefrontWarmupProgressLedger::PHASE_C_EXTRAS => ['extras', 'extras_cursor', (string)__('补源 URL')],
            StorefrontWarmupProgressLedger::PHASE_D_LOCS => ['locs', 'loc_cursor', (string)__('Sitemap 地址')],
        ];
        if (!isset($map[$phase])) {
            return [
                'label' => (string)__('已完成，等待下一世代'),
                'item' => '',
                'position' => '',
            ];
        }

        [$listKey, $cursorKey, $label] = $map[$phase];
        $list = \is_array($state[$listKey] ?? null) ? $state[$listKey] : [];
        $cursor = \max(0, (int)($state[$cursorKey] ?? 0));
        $total = \count($list);
        $item = $total > 0 ? (string)($list[$cursor] ?? '') : '';

        return [
            'label' => $label,
            'item' => $item,
            'position' => $total > 0
                ? (string)__('第 %{1}/%{2} 个', [\min($cursor + 1, $total), $total])
                : (string)__('队列为空'),
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return array{state:string,label:string,detail:string}
     */
    private function verdict(string $phase, bool $needsRewarm, bool $lockFree, ?int $age, array $state): array
    {
        $hasProgress = $phase !== StorefrontWarmupProgressLedger::PHASE_A_HOMEPAGE
            || (int)($state['priority_home_cursor'] ?? 0) > 0
            || (string)($state['updated_at'] ?? '') !== '';
        if (!$hasProgress) {
            return [
                'state' => 'never',
                'label' => (string)__('尚未运行'),
                'detail' => (string)__('账本还是空状态，等 cron 第一次推进'),
            ];
        }
        if (!$lockFree) {
            return [
                'state' => 'running',
                'label' => (string)__('正在预热'),
                'detail' => (string)__('编排锁已被占用，当前片正在执行'),
            ];
        }
        if ($needsRewarm) {
            return [
                'state' => 'needs_rewarm',
                'label' => (string)__('需要预热'),
                'detail' => (string)__('店面世代已变化，当前世代还没开始预热，等 cron 从相位 A 开始'),
            ];
        }
        if ($phase === StorefrontWarmupProgressLedger::PHASE_DONE) {
            return [
                'state' => 'done',
                'label' => (string)__('已预热完成'),
                'detail' => (string)__('当前世代已预热完成，无待推进队列'),
            ];
        }
        if ($age !== null && $age > self::STALE_SECONDS) {
            return [
                'state' => 'stalled',
                'label' => (string)__('预热停滞'),
                'detail' => (string)__('账本超过 %{1} 分钟没有更新，检查 cron 是否在跑', \intdiv(self::STALE_SECONDS, 60)),
            ];
        }

        return [
            'state' => 'queued',
            'label' => (string)__('待推进'),
            'detail' => (string)__('cron 每 5 分钟推进一片，排队中'),
        ];
    }

    private function phaseLabel(string $phase): string
    {
        return match ($phase) {
            StorefrontWarmupProgressLedger::PHASE_A_HOMEPAGE => (string)__('相位 A · 首页与语种根'),
            StorefrontWarmupProgressLedger::PHASE_B_SITEMAP_DOCS => (string)__('相位 B · Sitemap 文档'),
            StorefrontWarmupProgressLedger::PHASE_C_EXTRAS => (string)__('相位 C · 补源 URL'),
            StorefrontWarmupProgressLedger::PHASE_D_LOCS => (string)__('相位 D · Sitemap 地址'),
            default => (string)__('预热完成'),
        };
    }

    private function bandLabel(string $band): string
    {
        return match ($band) {
            StorefrontWarmupCapacityPlanner::BAND_SCALE => (string)__('缩容推进'),
            StorefrontWarmupCapacityPlanner::BAND_YIELD => (string)__('让出（本片不预热）'),
            default => (string)__('全速推进'),
        };
    }

    private function sourceLabel(string $source): string
    {
        return match ($source) {
            'proc_stat' => (string)__('/proc/stat CPU 采样'),
            'loadavg' => (string)__('loadavg ÷ 核数'),
            default => (string)__('采样不可用（按空闲放行）'),
        };
    }

    private function shortHash(string $hash): string
    {
        if ($hash === '') {
            return '';
        }

        return \strlen($hash) > 16 ? \substr($hash, 0, 8) . '…' . \substr($hash, -4) : $hash;
    }

    /**
     * @return array{value:int,default:int,min:int,max:int}
     */
    private function setting(mixed $raw, int $default, int $min, int $max): array
    {
        $value = \is_numeric($raw) ? (int)$raw : $default;

        return [
            'value' => \max($min, \min($max, $value)),
            'default' => $default,
            'min' => $min,
            'max' => $max,
        ];
    }

    /**
     * 计划任务门禁（Weline_Cron 可选）：负载超过上限时 crontab 触发的整轮调度直接被跳过，
     * 预热任务连子进程都不会起——这是「进度一直不动」的常见原因，页面必须显示。
     *
     * @return array{available:bool,max_percent:int,percent:float,blocked:bool}
     */
    private function cronGate(): array
    {
        $class = 'Weline\\Cron\\Service\\CronHostLoadGate';
        if (!\class_exists($class)) {
            return ['available' => false, 'max_percent' => 0, 'percent' => 0.0, 'blocked' => false];
        }
        try {
            $gate = ObjectManager::getInstance($class);
            $max = (int)$gate->maxPercent();
            $measured = $gate->measure();
            $percent = \round((float)($measured['percent'] ?? 0.0), 1);

            return [
                'available' => (bool)($measured['available'] ?? false),
                'max_percent' => $max,
                'percent' => $percent,
                'blocked' => (bool)($measured['available'] ?? false) && $percent >= $max,
            ];
        } catch (\Throwable) {
            return ['available' => false, 'max_percent' => 0, 'percent' => 0.0, 'blocked' => false];
        }
    }

    /**
     * 当前缓存命名空间权威时钟（DB 单调递增）。它比账本记录值大，
     * 说明这段时间里有站点/主题发布推进过世代——预热会因此从相位 A 重来。
     */
    private function currentAuthorityClock(): int
    {
        try {
            return (int)ObjectManager::getInstance(NamespaceGenerationRepository::class)->authorityClock();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * 编排锁持有时返回 false。用共享锁探测：拿得到共享锁说明没有片在跑，
     * 并且立即释放，避免把 cron 的排他锁挡住。
     */
    private function probeLockFree(): bool
    {
        $root = \defined('BP') ? (string)BP : (\getcwd() ?: '.');
        $path = \rtrim($root, '/\\') . '/' . self::LOCK_FILE;
        if (!\is_file($path)) {
            return true;
        }
        $handle = @\fopen($path, 'rb');
        if ($handle === false) {
            return true;
        }
        $free = @\flock($handle, \LOCK_SH | \LOCK_NB);
        if ($free) {
            @\flock($handle, \LOCK_UN);
        }
        @\fclose($handle);

        return (bool)$free;
    }
}
