<?php

declare(strict_types=1);

namespace Weline\Theme\Console\Theme;

use Weline\Framework\Console\ConsoleException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeUpdateBatch;
use Weline\Theme\Model\ThemeUpdatePointer;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeUpdate\ThemeUpdateBatchService;
use Weline\Theme\Service\ThemeUpdate\ThemeUpdateBuildService;
use Weline\Theme\Service\ThemeUpdate\ThemeUpdateFlight;
use Weline\Theme\Service\ThemeUpdate\ThemeUpdateFlightRunner;
use Weline\Theme\Service\ThemeUpdate\ThemeUpdatePointerPlanner;

/**
 * theme:update —— 主题更新代次的查看 / 切换 / 回滚 / 回收。
 *
 * 用法：
 *   php bin/w theme:update status   -t hanfu [--website 0] [--area frontend]
 *   php bin/w theme:update list     -t hanfu
 *   php bin/w theme:update promote  <batch_id> [--dry-run]
 *   php bin/w theme:update rollback -t hanfu [--dry-run]
 *   php bin/w theme:update gc       -t hanfu [--keep 2] [--dry-run]
 *
 * 说明：切换/回滚只改指针行（CAS by epoch）并写 Env，不 purge、不需要维护模式。
 * `build` 子命令（构建新代次）在后续任务中接入。
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
        if ($raw !== [] && $raw[0] === 'theme:update') {
            \array_shift($raw);
        }
        $action = 'status';
        if ($raw !== [] && !\str_starts_with((string)$raw[0], '-')) {
            $action = \strtolower((string)\array_shift($raw));
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
            case 'status':
            case 'list':
                $this->renderStatus($service, $this->requireThemeId($themeName), $websiteId, $area);
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
                return;

            case 'build':
                $themeId = $this->requireThemeId($themeName);
                $layouts = $options['layout'] ?? ($options['l'] ?? '');
                $layoutList = \is_array($layouts) ? \array_values($layouts) : \array_values(\array_filter(\explode(',', (string)$layouts)));
                $buildOptions = [
                    'layouts' => $layoutList,
                    'skip_static' => !empty($options['skip-static']),
                    'skip_entities' => !empty($options['skip-entities']),
                    'all_versions' => !empty($options['all']),
                ];
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
                    return;
                }
                $manifest = $result['manifest'];
                $ok = (bool)($manifest['verify']['ok'] ?? false);
                $this->printing->note(__('代次：%{1}', [$result['batch_id']]));
                foreach ((array)($manifest['checks'] ?? []) as $check) {
                    $this->printing->note(\sprintf(
                        '  [%s] %s %s',
                        ($check['ok'] ?? false) ? 'ok' : 'FAIL',
                        (string)($check['name'] ?? ''),
                        \json_encode(\array_diff_key((array)$check, ['name' => 1, 'ok' => 1]), JSON_UNESCAPED_UNICODE)
                    ));
                }
                if (!$ok) {
                    throw new ConsoleException(__('构建校验未通过，代次不会上线：') . $result['batch_id']);
                }
                $this->printing->success(__('构建完成且校验通过，可用 theme:update promote %{1} 切换', [$result['batch_id']]));
                return;

            default:
                throw new ConsoleException(__('未知子命令：') . $action);
        }
    }

    public function tip(): string
    {
        return __('查看/切换/回滚主题更新代次（不 purge、不需要维护模式）');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'theme:update',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
                '-t, --theme <name>' => '主题名（如 hanfu）；status/list/rollback/gc 必填',
                '--website <id>' => '站点ID，默认 0（全站默认）',
                '--area <area>' => '区域：frontend（默认）/ backend',
                '--keep <n>' => 'GC 时额外保留的历史代次数量，默认 ' . ThemeUpdatePointerPlanner::DEFAULT_KEEP,
                '--no-purge-artifacts' => '手动 gc 时跳过「清理引用已删代次的编译产物/缓存」（快，但可能残留）',
                '--dry-run' => '只打印将要做什么，不落库',
                '--force' => 'auto 子命令：忽略脏标记，强制重建并切换',
                '-l, --layout <type>' => 'build 时只固化指定布局（如 homepage）',
                '--skip-static' => 'build 时跳过静态批次目录',
                '--skip-entities' => 'build 时跳过模板产物固化',
            ],
            [
                '查看当前代次' => 'php bin/w theme:update status -t hanfu',
                '列代次' => 'php bin/w theme:update list -t hanfu',
                '切到指定代次' => 'php bin/w theme:update promote tu-20261022-015300-ab12cd',
                '回滚' => 'php bin/w theme:update rollback -t hanfu',
                '发布后自动更新（脏标记）' => 'php bin/w theme:update auto -t hanfu',
                '回收旧代次' => 'php bin/w theme:update gc -t hanfu --keep 2',
            ]
        );
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

    private function renderStatus(ThemeUpdateBatchService $service, int $themeId, int $websiteId, string $area): void
    {
        $pointer = $this->currentPointer($themeId, $websiteId, $area);
        if ($pointer === null) {
            $this->printing->warning(__('该主题尚无更新代次指针（首次可先 theme:upgrade 构建）'));
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
