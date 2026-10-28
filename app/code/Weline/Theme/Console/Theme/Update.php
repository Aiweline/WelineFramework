<?php

declare(strict_types=1);

namespace Weline\Theme\Console\Theme;

use Weline\Framework\Console\ConsoleException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeUpdateBatch;
use Weline\Theme\Model\ThemeUpdatePointer;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeHeadCssDuplicateInspector;
use Weline\Theme\Service\ThemeUpdate\ThemeUpdateAutoService;
use Weline\Theme\Service\ThemeUpdate\ThemeUpdateBatchService;
use Weline\Theme\Service\ThemeUpdate\ThemeUpdateBuildService;
use Weline\Theme\Service\ThemeUpdate\ThemeUpdateFlight;
use Weline\Theme\Service\ThemeUpdate\ThemeUpdateFlightRunner;
use Weline\Theme\Service\ThemeUpdate\ThemeUpdatePointerPlanner;

/**
 * theme:update —— 主题更新代次的查看 / 切换 / 回滚 / 回收。
 *
 * 用法：
 *   php bin/w theme:update          -t hanfu            # 增量：无脏标记直接跳过；有脏标记只重建受影响布局
 *   php bin/w theme:update -f       -t hanfu            # 强制全量重建代次
 *   php bin/w theme:update status   -t hanfu [--website 0] [--area frontend]
 *   php bin/w theme:update list     -t hanfu
 *   php bin/w theme:update promote  <batch_id> [--dry-run]
 *   php bin/w theme:update rollback -t hanfu [--dry-run]
 *   php bin/w theme:update gc       -t hanfu [--keep 2] [--dry-run]
 *
 * 不带子命令 = 增量收敛（ThemeUpdateAutoService）：无发布变更直接跳过，绝不无条件 purge 重固。
 * `-f/--force` 才强制整体重建；需要只构建不切换用 `build` 子命令。
 * status/list 只查看。切换/回滚只改指针行（CAS by epoch）并写 Env，不 purge、不需要维护模式。
 *
 * 2026-10-28：老命令 `theme:upgrade`（无条件 purge 全部布局固化物 + 全量 FPC/CDN 清，实测 3.5~17 分钟）
 * 及其命令体已退役，本命令不再转发 `theme:upgrade`。
 */
class Update extends AbstractConsole
{
    public function execute(array $args = [], array $data = [])
    {
        // Cli 传入的 $args 形如 {"0":"theme:update","1":"status","2":"-t","3":"hanfu","t":"hanfu"}
        // 数字键 = 原始位置参数（含命令名），其余键 = 已解析的短选项。
        $raw = [];
        foreach ($args as $key => $value) {
            if (\is_int($key)) {
                $raw[] = (string)$value;
            }
        }
        // CLI 支持前缀匹配（`theme:up` 也会命中本命令），首段可能是别名而非规范名，
        // 因此按「实际输入的命令名」判断，不能只认字面量 theme:update。
        if ($raw !== [] && !\str_starts_with((string)$raw[0], '-')) {
            $invoked = \is_string($args['command'] ?? null) ? (string)$args['command'] : '';
            if ($raw[0] === 'theme:update' || ($invoked !== '' && \strcasecmp((string)$raw[0], $invoked) === 0)) {
                \array_shift($raw);
            }
        }
        $action = self::resolveAction($raw);
        if ($raw !== [] && !\str_starts_with((string)$raw[0], '-')) {
            \array_shift($raw);
        }
        $options = $this->parseOptions($raw);
        foreach ($args as $key => $value) {
            if (!\is_int($key) && (\is_string($value) || \is_numeric($value))) {
                $options[$key] = $value;
            }
        }
        $argv = $raw;
        $dryRun = !empty($options['dry-run']);
        $area = (string)($options['area'] ?? ThemeUpdateBatchService::AREA_FRONTEND);
        $websiteId = (int)($options['website'] ?? 0);
        $themeName = (string)($options['theme'] ?? '');
        $keep = (int)($options['keep'] ?? ThemeUpdatePointerPlanner::DEFAULT_KEEP);

        /** @var ThemeUpdateBatchService $service */
        $service = ObjectManager::getInstance(ThemeUpdateBatchService::class);

        switch ($action) {
            case 'apply':
                $this->applyUpdate($themeName, $options, $dryRun);
                return;

            case 'status':
            case 'list':
                $this->renderStatus($service, $this->requireThemeId($themeName), $themeName, $websiteId, $area);
                return;

            case 'promote':
                $batchId = (string)($argv[0] ?? '');
                if ($batchId === '') {
                    throw new ConsoleException(__('用法：theme:update promote <batch_id> [--dry-run]'));
                }
                if ($dryRun) {
                    $this->printing->note(__('dry-run：将把指针切换到 %{1}（未执行）', [$batchId]));
                    return;
                }
                $state = $service->promote($batchId);
                $this->printing->success(__(
                    '已切换：current=%{1} previous=%{2} epoch=%{3}',
                    [$state['current'], $state['previous'] === '' ? '-' : $state['previous'], (string)$state['epoch']]
                ));
                $this->failOnPostSwitchErrors($state);
                return;

            case 'rollback':
                $themeId = $this->requireThemeId($themeName);
                if ($dryRun) {
                    $this->printing->note(__('dry-run：将把主题 %{1} 回滚到上一个代次（未执行）', [(string)$themeId]));
                    return;
                }
                $state = $service->rollback($themeId, $websiteId, $area);
                $this->printing->success(__(
                    '已回滚：current=%{1} previous=%{2} epoch=%{3}',
                    [$state['current'], $state['previous'] === '' ? '-' : $state['previous'], (string)$state['epoch']]
                ));
                $this->failOnPostSwitchErrors($state);
                return;

            case 'gc':
                $themeId = $this->requireThemeId($themeName);
                $batches = $service->listBatches($themeId, $websiteId, $area, 200);
                $pointer = $this->currentPointer($themeId, $websiteId, $area);
                $plan = ThemeUpdatePointerPlanner::planGc(
                    $batches,
                    (string)($pointer['current'] ?? ''),
                    (string)($pointer['previous'] ?? ''),
                    $keep
                );
                if ($plan === []) {
                    $this->printing->note(__('没有可回收的历史代次（keep=%{1}）', [(string)$keep]));
                    return;
                }
                if ($dryRun) {
                    $this->printing->note(__('dry-run：将删除 %{1}', [\implode(', ', $plan)]));
                    return;
                }
                $purgeArtifacts = !isset($options['no-purge-artifacts']);
                $deleted = $service->gc($themeId, $websiteId, $area, $keep, $purgeArtifacts);
                $this->printing->success(__('已回收 %{1} 个代次：%{2}', [
                    (string)\count($deleted),
                    \implode(', ', $deleted),
                ]));
                return;

            case 'auto':
                $themeId = $this->requireThemeId($themeName);
                $force = !empty($options['force']);
                /** @var \Weline\Theme\Service\ThemeUpdate\ThemeUpdateAutoService $auto */
                $auto = ObjectManager::getInstance(\Weline\Theme\Service\ThemeUpdate\ThemeUpdateAutoService::class);
                $result = $auto->run($themeId, $force, $dryRun);
                if (($result['skipped'] ?? '') === 'coalesced') {
                    $this->printing->note(__('已有更新在进行，本次已并入最后一次，不会并行再做一份。'));
                    return;
                }
                $mode = (string)($result['mode'] ?? '');
                if ($mode === 'dev') {
                    if ($dryRun) {
                        $this->printing->note(__('dry-run：dev 模式将走「开发环境自动固化编译」（与 theme_layout_origin_watch 同一实现）'));
                        return;
                    }
                    $report = (array)($result['report'] ?? []);
                    $this->printing->success(__('dev 模式已就地固化/编译：scanned=%{1} changed=%{2} solidified=%{3} static=%{4} cache_cleared=%{5}', [
                        (string)($report['themes_scanned'] ?? 0),
                        (string)($report['changed'] ?? 0),
                        (string)($report['solidified'] ?? 0),
                        (string)($report['static_published'] ?? 0),
                        (string)($report['cache_cleared'] ?? 0),
                    ]));
                    $this->assertHeadCssNoDuplicateLeaf($themeId);
                    return;
                }
                if (!empty($result['dry_run'])) {
                    $this->printing->note(__('dry-run：将重建并切换主题 #%{1} 的更新代次（dirty=%{2} force=%{3}）', [
                        (string)$themeId,
                        ($result['skipped'] ?? '') === 'no_dirty' ? '0' : '1',
                        $force ? '1' : '0',
                    ]));
                    return;
                }
                if (($result['skipped'] ?? '') === 'backoff') {
                    $this->printing->warning(__('主题 #%{1} 上次构建失败，退避中（重试时间 %{2}），本次跳过。', [
                        (string)$themeId,
                        (string)($result['retry_at'] ?? ''),
                    ]));
                    return;
                }
                if (($result['skipped'] ?? '') === 'setup_upgrade_running') {
                    $this->printing->note(__('系统更新（setup:upgrade）正在运行，本次跳过；脏标记保留，升级结束后再跑 theme:update。'));
                    return;
                }
                if (($result['skipped'] ?? '') === 'no_dirty') {
                    $this->printing->note(__('主题 #%{1} 无发布变更（无脏标记），跳过。', [(string)$themeId]));
                    return;
                }
                if (($result['skipped'] ?? '') === 'verify_failed') {
                    throw new ConsoleException(__('构建校验未通过，未切换：') . (string)($result['batch_id'] ?? ''));
                }
                $state = (array)($result['state'] ?? []);
                $this->printing->success(__('已切到代次 %{1}（epoch=%{2}）', [
                    (string)($state['current'] ?? ''),
                    (string)($state['epoch'] ?? 0),
                ]));
                $this->failOnPostSwitchErrors($state);
                return;

            case 'build':
                $this->buildBatch($this->requireThemeId($themeName), $this->buildOptionsFrom($options));

                return;

            default:
                throw new ConsoleException(__('未知子命令：') . $action);
        }
    }

    public function tip(): string
    {
        return __('构建并切换主题更新代次；status 只查看（不 purge、不需要维护模式）');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'theme:update',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
                '-t, --theme <name>' => '主题名（如 hanfu）；默认构建、status/list/rollback/gc 都要带',
                '--website <id>' => '站点ID，默认 0（全站默认）',
                '--area <area>' => '区域：frontend（默认）/ backend',
                '--keep <n>' => 'GC 时额外保留的历史代次数量，默认 ' . ThemeUpdatePointerPlanner::DEFAULT_KEEP,
                '--no-purge-artifacts' => '手动 gc 时跳过「清理引用已删代次的编译产物/缓存」（快，但可能残留）',
                '--dry-run' => '只打印将要做什么，不落库',
                '-f, --force' => '忽略脏标记，强制完整重建并切换（默认增量：无脏标记跳过，有脏标记只重建受影响布局）',
                '-l, --layout <type>' => 'build 时只固化指定布局（如 homepage）',
                '--skip-static' => 'build 时跳过静态批次目录',
                '--skip-entities' => 'build 时跳过模板产物固化',
            ],
            [
                '增量收敛（默认）' => 'php bin/w theme:update -t hanfu',
                '强制完整重建' => 'php bin/w theme:update -f -t hanfu',
                '查看当前代次' => 'php bin/w theme:update status -t hanfu',
                '列代次' => 'php bin/w theme:update list -t hanfu',
                '切到指定代次' => 'php bin/w theme:update promote tu-20261022-015300-ab12cd',
                '回滚' => 'php bin/w theme:update rollback -t hanfu',
                '发布后自动更新（脏标记）' => 'php bin/w theme:update auto -t hanfu',
                '回收旧代次' => 'php bin/w theme:update gc -t hanfu --keep 2',
            ]
        );
    }

    /**
     * 不带子命令（只有 -t/--theme 等选项）时执行构建并切换。
     * 显式 status/list/promote/rollback/gc/auto/build 保持原意。
     *
     * @param list<string> $raw 已去掉命令名的位置参数
     */
    public static function resolveAction(array $raw): string
    {
        if ($raw !== [] && !\str_starts_with((string)$raw[0], '-')) {
            $action = \strtolower((string)$raw[0]);

            return $action === '' ? 'apply' : $action;
        }

        return 'apply';
    }

    /**
     * 默认入口 = 增量收敛（不再无条件 purge 重固）。
     *
     * - 无 `-f`/`-l`/`-o`/`--all` 且该主题已产出过代次：走 ThemeUpdateAutoService，
     *   无脏标记直接跳过；有脏标记只重建受影响布局（layout_targets）。
     * - 首次产出代次（无任何历史代次）或显式强制/定向：走完整 build + promote，仅这一次。
     *
     * @param array<string,mixed> $options
     */
    private function applyUpdate(string $themeName, array $options, bool $dryRun): void
    {
        $themeId = $this->requireThemeId($themeName);
        // 短选项 -f 在 CLI 里以空串值落地，必须用 isset 判定（!empty 会漏掉 -f）。
        $force = \array_key_exists('force', $options) || \array_key_exists('f', $options);
        $buildOptions = $this->buildOptionsFrom($options);
        $explicitTargets = $buildOptions['layouts'] !== [] || !empty($buildOptions['all_versions']);
        // dev 模式没有代次可切：一律走就地固化/编译（与 theme_layout_origin_watch 同一实现），
        // 只有显式 -l/--all 这种"定向构建代次"才走 batch build。
        $devMode = $this->deployMode() === 'dev';
        $fullBuild = !$devMode && ($force || $explicitTargets || !$this->hasAnyBatch($themeId));
        if ($devMode && $explicitTargets) {
            $fullBuild = true;
        }

        if ($dryRun) {
            $this->printing->note(match (true) {
                $devMode => __('dry-run：dev 模式将就地固化/编译主题 %{1}（无变更则零工作）', [$themeName]),
                $fullBuild => __('dry-run：将完整构建并切换主题 %{1}', [$themeName]),
                default => __('dry-run：主题 %{1} 走增量收敛（无脏标记则跳过；强制重建用 -f）', [$themeName]),
            });

            return;
        }

        if ($fullBuild) {
            $batchId = $this->buildBatch($themeId, $buildOptions);
            if ($batchId === '') {
                return;
            }
            /** @var ThemeUpdateBatchService $service */
            $service = ObjectManager::getInstance(ThemeUpdateBatchService::class);
            $state = $service->promote($batchId);
            $this->printing->success(__('已切到代次 %{1}（epoch=%{2}）', [
                (string)($state['current'] ?? $batchId),
                (string)($state['epoch'] ?? 0),
            ]));
            $this->failOnPostSwitchErrors($state);

            return;
        }

        /** @var ThemeUpdateAutoService $auto */
        $auto = ObjectManager::getInstance(ThemeUpdateAutoService::class);
        $result = $auto->run($themeId, false, false);
        $mode = (string)($result['mode'] ?? '');
        // dev 模式无代次概念：就地固化/编译（与 theme_layout_origin_watch 同一实现），
        // 必须单独收口，否则会掉进下面的「已切到代次」分支打出空代次。
        if ($mode === 'dev') {
            $report = (array)($result['report'] ?? []);
            $this->printing->success(__('dev 模式已就地固化/编译：scanned=%{1} changed=%{2} solidified=%{3} static=%{4} cache_cleared=%{5}', [
                (string)($report['themes_scanned'] ?? 0),
                (string)($report['changed'] ?? 0),
                (string)($report['solidified'] ?? 0),
                (string)($report['static_published'] ?? 0),
                (string)($report['cache_cleared'] ?? 0),
            ]));
            $this->assertHeadCssNoDuplicateLeaf($themeId);

            return;
        }
        $skipped = (string)($result['skipped'] ?? '');
        if ($skipped === 'no_dirty') {
            $this->printing->note(__('主题 #%{1} 无发布变更（无脏标记），已跳过，未做任何重固；需要强制重建请加 -f/--force。', [$themeId]));

            return;
        }
        if ($skipped === 'coalesced') {
            $this->printing->note(__('已有更新在进行，本次已并入最后一次，不会并行再做一份。'));

            return;
        }
        if ($skipped === 'backoff') {
            $this->printing->warning(__('主题 #%{1} 上次构建失败，退避中（重试时间 %{2}），本次跳过。', [
                $themeId,
                (string)($result['retry_at'] ?? ''),
            ]));

            return;
        }
        if ($skipped === 'verify_failed') {
            throw new ConsoleException(__('构建校验未通过，未切换：') . (string)($result['batch_id'] ?? ''));
        }
        $state = (array)($result['state'] ?? []);
        $batchId = (string)($result['batch_id'] ?? '');
        if ($state === [] && $batchId === '') {
            // 未知返回：不谎报成功，按「无变更」收口并给出可复现命令。
            $this->printing->warning(__('主题 #%{1} 本次没有产出新代次（返回 mode=%{2}）；如需强制重建：php bin/w theme:update -f -t %{3}', [
                $themeId,
                $mode !== '' ? $mode : 'unknown',
                $themeName,
            ]));

            return;
        }
        $this->printing->success(__('已切到代次 %{1}（epoch=%{2}）', [
            (string)($state['current'] ?? $batchId),
            (string)($state['epoch'] ?? 0),
        ]));
        $this->failOnPostSwitchErrors($state);
    }

    /**
     * 从命令行选项收出 build 选项（apply 与 build 子命令共用）。
     *
     * @param array<string,mixed> $options
     * @return array{layouts:list<string>,skip_static:bool,skip_entities:bool,all_versions:bool}
     */
    private function buildOptionsFrom(array $options): array
    {
        $layouts = $options['layout'] ?? ($options['l'] ?? '');
        $layoutList = \is_array($layouts)
            ? \array_values($layouts)
            : \array_values(\array_filter(\explode(',', (string)$layouts)));

        return [
            'layouts' => \array_values(\array_filter(\array_map('strval', $layoutList), static fn(string $v): bool => $v !== '')),
            'skip_static' => !empty($options['skip-static']),
            'skip_entities' => !empty($options['skip-entities']),
            'all_versions' => !empty($options['all']),
        ];
    }

    /** 当前部署模式：dev 时代次机制不适用（就地固化/编译）。 */
    private function deployMode(): string
    {
        try {
            return (string)(\Weline\Framework\App\Env::system('deploy') ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    /** 该主题是否已有任何更新代次（首次产出才允许走完整构建）。 */
    private function hasAnyBatch(int $themeId): bool
    {
        /** @var ThemeUpdateBatchService $service */
        $service = ObjectManager::getInstance(ThemeUpdateBatchService::class);

        return $service->listBatches($themeId, 0, ThemeUpdateBatchService::AREA_FRONTEND, 1) !== [];
    }

    /**
     * 构建新代次（不切换）。
     *
     * @param array{layouts?:list<string>,skip_static?:bool,skip_entities?:bool,all_versions?:bool} $buildOptions
     * @return string batch_id；被合并/放弃时返回 ''
     */
    private function buildBatch(int $themeId, array $buildOptions): string
    {
        $this->printing->note(__('开始构建新代次（不会影响线上）…'));
        /** @var ThemeUpdateBuildService $builder */
        $builder = ObjectManager::getInstance(ThemeUpdateBuildService::class);
        /** @var ThemeUpdateFlightRunner $flights */
        $flights = ObjectManager::getInstance(ThemeUpdateFlightRunner::class);
        $scope = ThemeUpdateFlight::scopeForTheme($themeId);
        $result = $flights->execute(
            $scope,
            function (string $batchId) use ($builder, $themeId, $buildOptions): array {
                $buildOptions['batch_id'] = $batchId;

                return $builder->build($themeId, $buildOptions);
            }
        );
        if (($result['skipped'] ?? '') === 'coalesced') {
            $this->printing->note(__('已有更新在进行，本次已并入最后一次，不会并行再做一份。'));

            return '';
        }
        $manifest = (array)($result['manifest'] ?? []);
        $ok = (bool)($manifest['verify']['ok'] ?? false);
        $batchId = (string)($result['batch_id'] ?? '');
        $this->printing->note(__('代次：%{1}', [$batchId]));
        foreach ((array)($manifest['checks'] ?? []) as $check) {
            $this->printing->note(\sprintf(
                '  [%s] %s %s',
                ($check['ok'] ?? false) ? 'ok' : 'FAIL',
                (string)($check['name'] ?? ''),
                \json_encode(\array_diff_key((array)$check, ['name' => 1, 'ok' => 1]), JSON_UNESCAPED_UNICODE)
            ));
        }
        if (!$ok) {
            throw new ConsoleException(__('构建校验未通过，代次不会上线：') . $batchId);
        }

        return $batchId;
    }

    /** @return array<string,mixed> */
    private function parseOptions(array $argv): array
    {
        $options = [];
        $count = \count($argv);
        for ($i = 0; $i < $count; $i++) {
            $arg = (string)$argv[$i];
            if (!\str_starts_with($arg, '--')) {
                continue;
            }
            $key = \substr($arg, 2);
            $value = true;
            if (\str_contains($key, '=')) {
                [$key, $value] = \explode('=', $key, 2);
            } elseif (isset($argv[$i + 1]) && !\str_starts_with((string)$argv[$i + 1], '-')) {
                $value = (string)$argv[$i + 1];
                $i++;
            }
            $options[$key] = $value;
        }
        // 兼容 -t hanfu
        for ($i = 0; $i < $count; $i++) {
            if ((string)$argv[$i] === '-t' && isset($argv[$i + 1])) {
                $options['theme'] = (string)$argv[$i + 1];
            }
        }

        return $options;
    }

    /** @param array<string,mixed> $state promote/rollback 结果 */
    private function failOnPostSwitchErrors(array $state): void
    {
        $errors = (array)($state['post_switch_errors'] ?? []);
        if ($errors === []) {
            return;
        }
        foreach ($errors as $error) {
            $this->printing->error(__('切换后清理失败：%{1}', [(string)$error]));
        }
        throw new ConsoleException(__('代次已切换，但缓存/边缘清理失败；旧页面可能仍在缓存中，请按上方原因修复后重跑。'));
    }

    /**
     * DEV 断言：头部 CSS 不得重复投递（PACK_OVERLAY 已合并主题叶）。
     *
     * 只在 DEV 生效：PROD 发版与运行时一律不因它失败（用户 2026-10-28 指定）。
     * duplicate 级直接让命令非零退出；guard_unsafe / late_override 只打印警告。
     */
    private function assertHeadCssNoDuplicateLeaf(int $themeId): void
    {
        if (\defined('PROD') && \PROD) {
            return;
        }
        try {
            /** @var ThemeHeadCssDuplicateInspector $inspector */
            $inspector = ObjectManager::getInstance(ThemeHeadCssDuplicateInspector::class);
            $findings = $inspector->inspectTheme($themeId, 'frontend');
        } catch (\Throwable $e) {
            $this->printing->warning(__('头部 CSS 重复检查跳过：%{1}', [$e->getMessage()]));
            return;
        }
        if ($findings === []) {
            return;
        }
        $hard = $inspector->hardFindings($findings);
        foreach ($findings as $finding) {
            if (($finding['severity'] ?? '') === ThemeHeadCssDuplicateInspector::SEVERITY_DUPLICATE) {
                continue;
            }
            $this->printing->warning($inspector->format([$finding]));
        }
        if ($hard === []) {
            return;
        }
        $this->printing->error(__('头部 CSS 重复投递（这些叶已在 PACK_OVERLAY 里）：'));
        $this->printing->error($inspector->format($hard));
        throw new ConsoleException(__(
            '头部 CSS 重复投递 %{1} 处：删掉 layout/page 里的重复叶，或改用 overlayLeavesForCurrentRequest() 做守卫后重跑。',
            [(string)\count($hard)]
        ));
    }

    private function requireThemeId(string $themeName): int
    {
        if ($themeName === '') {
            throw new ConsoleException(__('请用 -t/--theme 指定主题名，例如 -t hanfu'));
        }
        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->reset()->clearQuery()->where('name', $themeName)->find()->fetch();
        $themeId = (int)$theme->getId();
        if ($themeId < 1) {
            throw new ConsoleException(__('未找到主题：') . $themeName);
        }

        return $themeId;
    }

    private function renderStatus(ThemeUpdateBatchService $service, int $themeId, string $themeName, int $websiteId, string $area): void
    {
        $pointer = $this->currentPointer($themeId, $websiteId, $area);
        if ($pointer === null) {
            $this->printing->warning(__('该主题尚无更新代次。构建并切换请执行：theme:update -t %{1}', [$themeName]));
            return;
        }
        $this->printing->note(__('当前代次：%{1}', [(string)($pointer['current'] ?? '-')]));
        $this->printing->note(__('回滚目标：%{1}', [(string)(($pointer['previous'] ?? '') !== '' ? $pointer['previous'] : '-')]));
        $this->printing->note(__('epoch：%{1}', [(string)($pointer['epoch'] ?? 0)]));

        $batches = $service->listBatches($themeId, $websiteId, $area, 50);
        if ($batches === []) {
            return;
        }
        $this->printing->note(__('代次列表：'));
        foreach ($batches as $batch) {
            $batchId = (string)($batch[ThemeUpdateBatch::schema_fields_BATCH_ID] ?? '');
            $status = (string)($batch[ThemeUpdateBatch::schema_fields_STATUS] ?? '');
            $created = (string)($batch[ThemeUpdateBatch::schema_fields_CREATE_TIME] ?? '');
            $files = (int)($batch[ThemeUpdateBatch::schema_fields_FILE_COUNT] ?? 0);
            $mark = $batchId !== '' && $batchId === (string)($pointer['current'] ?? '') ? ' *' : '';
            $this->printing->note(\sprintf('  %s  %-10s files=%-6d %s%s', $batchId, $status, $files, $created, $mark));
        }
    }

    /** @return array{current:string,previous:string,epoch:int}|null */
    private function currentPointer(int $themeId, int $websiteId, string $area): ?array
    {
        /** @var ThemeUpdatePointer $pointer */
        $pointer = ObjectManager::getInstance(ThemeUpdatePointer::class);
        $pointer->reset()->clearQuery()
            ->where(ThemeUpdatePointer::schema_fields_WEBSITE_ID, $websiteId)
            ->where(ThemeUpdatePointer::schema_fields_THEME_ID, $themeId)
            ->where(ThemeUpdatePointer::schema_fields_AREA, $area)
            ->find()->fetch();

        return $pointer->getCurrentBatchId() === '' ? null : $pointer->toState();
    }
}
