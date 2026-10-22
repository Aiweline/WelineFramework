<?php

declare(strict_types=1);

namespace Weline\Theme\Service\ThemeUpdate;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutOriginWatchService;

/**
 * 自动收敛入口：`theme:update auto` 与发布后 cron 共用。
 *
 * 两种模式（按 `system.deploy`）：
 * - **dev**：委托给既有的开发环境自动固化/编译（{@see ThemeLayoutOriginWatchService}），
 *   就地刷新（文件即产物），与 `theme_layout_origin_watch` cron 完全同一实现；
 * - **非 dev（prod 等）**：走「更新代次」——有发布脏标记时构建新代次 → 校验 → 翻指针。
 */
final class ThemeUpdateAutoService
{
    public function __construct(
        private readonly ThemeUpdateBuildService $builder,
        private readonly ThemeUpdateBatchService $batches,
        private readonly ThemeLayoutOriginWatchService $originWatch,
    ) {
    }

    /**
     * @return array{
     *   mode:string,
     *   skipped?:string,
     *   batch_id?:string,
     *   state?:array{current:string,previous:string,epoch:int},
     *   report?:array<string,mixed>,
     *   dry_run?:bool
     * }
     */
    public function run(int $themeId, bool $force = false, bool $dryRun = false): array
    {
        if ($themeId < 1) {
            return ['mode' => 'unknown', 'skipped' => 'theme_id_invalid'];
        }

        // dev：与「开发环境自动固化编译」同一实现（就地刷新，不产代次）
        if ($this->deployMode() === 'dev') {
            if ($dryRun) {
                return ['mode' => 'dev', 'dry_run' => true];
            }

            return ['mode' => 'dev', 'report' => $this->originWatch->run()];
        }

        // 非 dev：发布后原子切换
        $dirty = ThemeUpdateRuntime::isDirty($themeId);
        if (!$force && !$dirty) {
            return ['mode' => 'batch', 'skipped' => 'no_dirty'];
        }
        if ($dryRun) {
            return [
                'mode' => 'batch',
                'dry_run' => true,
                'skipped' => $dirty ? '' : 'no_dirty',
            ];
        }

        $result = $this->builder->build($themeId, []);
        $manifest = $result['manifest'] ?? [];
        if (($manifest['verify']['ok'] ?? false) !== true) {
            // 不清脏标记：下次重试；线上保持旧代次
            return [
                'mode' => 'batch',
                'batch_id' => $result['batch_id'],
                'skipped' => 'verify_failed',
            ];
        }

        $state = $this->batches->promote($result['batch_id']);
        ThemeUpdateRuntime::clearDirty($themeId);

        return [
            'mode' => 'batch',
            'batch_id' => $result['batch_id'],
            'state' => $state,
        ];
    }

    /** @return list<int> 有待收敛发布的主题ID */
    public static function dirtyThemeIds(): array
    {
        $dir = \dirname(ThemeUpdateRuntime::dirtyFile(1));
        if (!\is_dir($dir)) {
            return [];
        }
        $ids = [];
        foreach ((array)@\scandir($dir) as $file) {
            if (!\is_string($file) || !\preg_match('/^(\d+)\.json$/', $file, $m)) {
                continue;
            }
            $ids[] = (int)$m[1];
        }
        \sort($ids);

        return $ids;
    }

    private function deployMode(): string
    {
        try {
            return (string)(Env::system('deploy') ?? '');
        } catch (\Throwable) {
            return '';
        }
    }
}
