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

        // 失败退避：最近一次构建失败后 N 分钟内不重试，避免每分钟重复失败
        $backoff = $this->backoffState($themeId);
        if ($backoff !== null) {
            return ['mode' => 'batch', 'skipped' => 'backoff', 'retry_at' => $backoff];
        }

        $dirtyLayouts = ThemeUpdateRuntime::dirtyLayouts($themeId);
        $buildOptions = [];
        if (empty($dirtyLayouts['all']) && $dirtyLayouts['layouts'] !== []) {
            $buildOptions['layout_targets'] = $dirtyLayouts['layouts'];
        }
        $result = $this->builder->build($themeId, $buildOptions);
        $manifest = $result['manifest'] ?? [];
        if (($manifest['verify']['ok'] ?? false) !== true) {
            // 不清脏标记：下次重试；线上保持旧代次。记录退避窗口，避免每分钟重复失败。
            $this->writeBackoff($themeId, 'verify_failed');
            return [
                'mode' => 'batch',
                'batch_id' => $result['batch_id'],
                'skipped' => 'verify_failed',
            ];
        }

        $state = $this->batches->promote($result['batch_id']);
        ThemeUpdateRuntime::clearDirty($themeId);
        $this->clearBackoff($themeId);

        return [
            'mode' => 'batch',
            'batch_id' => $result['batch_id'],
            'state' => $state,
            'layout_targets' => $buildOptions['layout_targets'] ?? [],
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

    /** 退避默认分钟数。 */
    private const BACKOFF_MINUTES = 5;

    private static function backoffFile(int $themeId): string
    {
        return rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR
            . 'theme-update' . DIRECTORY_SEPARATOR . 'backoff' . DIRECTORY_SEPARATOR . max(0, $themeId) . '.json';
    }

    /** 退避未过期时返回重试时间，否则 null。 */
    private function backoffState(int $themeId): ?string
    {
        $file = self::backoffFile($themeId);
        if (!is_file($file)) {
            return null;
        }
        $decoded = json_decode((string)@file_get_contents($file), true);
        $retryAt = is_array($decoded) ? (string)($decoded['retry_at'] ?? '') : '';
        $ts = $retryAt !== '' ? strtotime($retryAt) : false;
        if ($ts === false || $ts <= time()) {
            @unlink($file);
            return null;
        }

        return $retryAt;
    }

    private function writeBackoff(int $themeId, string $reason): void
    {
        $file = self::backoffFile($themeId);
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        @file_put_contents($file, (string)json_encode([
            'reason' => $reason,
            'retry_at' => date('c', time() + self::BACKOFF_MINUTES * 60),
        ], JSON_UNESCAPED_UNICODE));
    }

    private function clearBackoff(int $themeId): void
    {
        $file = self::backoffFile($themeId);
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
