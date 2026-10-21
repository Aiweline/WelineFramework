<?php

declare(strict_types=1);

namespace Weline\Theme\Service\ThemeUpdate;

/**
 * 主题更新代次的纯决策逻辑（不碰 DB / 文件系统，便于单测）。
 *
 * 约定：
 * - 代次ID 形如 `tu-20261022-015300-ab12cd`；
 * - 切换/回滚都让 `epoch` 单调 +1，FPC 与 Worker 以 epoch 为代次信号；
 * - GC 永远不删当前代次与回滚目标（previous）。
 */
final class ThemeUpdatePointerPlanner
{
    public const BATCH_ID_PATTERN = '/^tu-\d{8}-\d{6}-[a-z0-9]{6}$/';

    /** 默认保留的「历史代次」数量（不含 current 与 previous）。 */
    public const DEFAULT_KEEP = 2;

    public static function newBatchId(\DateTimeImmutable $now, string $randomSuffix): string
    {
        $suffix = \strtolower(\preg_replace('/[^a-zA-Z0-9]/', '', $randomSuffix) ?? '');
        $suffix = \substr($suffix . '000000', 0, 6);

        return 'tu-' . $now->format('Ymd-His') . '-' . $suffix;
    }

    public static function isBatchIdValid(string $batchId): bool
    {
        return \preg_match(self::BATCH_ID_PATTERN, $batchId) === 1;
    }

    /**
     * 切换到新代次。
     *
     * @param array{current?:string,previous?:string,epoch?:int} $state
     * @return array{current:string,previous:string,epoch:int}
     */
    public static function planPromote(array $state, string $batchId): array
    {
        if (!self::isBatchIdValid($batchId)) {
            throw new \InvalidArgumentException('theme_update_batch_id_invalid: ' . $batchId);
        }
        $current = (string)($state['current'] ?? '');
        if ($current === $batchId) {
            throw new \InvalidArgumentException('theme_update_batch_already_current: ' . $batchId);
        }

        return [
            'current' => $batchId,
            'previous' => $current,
            'epoch' => \max(1, (int)($state['epoch'] ?? 0) + 1),
        ];
    }

    /**
     * 回滚到 previous。调用方必须先确认目标代次仍可服务（目录未被 GC）。
     *
     * @param array{current?:string,previous?:string,epoch?:int} $state
     * @return array{current:string,previous:string,epoch:int}
     */
    public static function planRollback(array $state): array
    {
        $current = (string)($state['current'] ?? '');
        $previous = (string)($state['previous'] ?? '');
        if ($previous === '') {
            throw new \RuntimeException('theme_update_rollback_target_missing');
        }
        if ($previous === $current) {
            throw new \RuntimeException('theme_update_rollback_target_is_current');
        }
        if (!self::isBatchIdValid($previous)) {
            throw new \RuntimeException('theme_update_rollback_target_invalid: ' . $previous);
        }

        return [
            'current' => $previous,
            'previous' => $current,
            'epoch' => \max(1, (int)($state['epoch'] ?? 0) + 1),
        ];
    }

    /**
     * 选择可删除的历史代次。
     *
     * @param list<array{batch_id:string,create_time?:string,status?:string}> $batches 现有代次（任意顺序）
     * @param string $currentBatchId 当前代次（永不删除）
     * @param string $previousBatchId 回滚目标（永不删除）
     * @param int $keep 额外保留的最近历史代次数量（>=0；不含 current/previous）
     * @return list<string> 待删除代次ID（按创建时间从旧到新）
     */
    public static function planGc(
        array $batches,
        string $currentBatchId,
        string $previousBatchId,
        int $keep = self::DEFAULT_KEEP
    ): array {
        $keep = \max(0, $keep);
        $protected = \array_filter([$currentBatchId, $previousBatchId], static fn(string $id): bool => $id !== '');

        $rows = [];
        foreach ($batches as $row) {
            $id = (string)($row['batch_id'] ?? '');
            if ($id === '' || \in_array($id, $protected, true)) {
                continue;
            }
            $rows[] = [
                'batch_id' => $id,
                'create_time' => (string)($row['create_time'] ?? ''),
            ];
        }

        \usort($rows, static function (array $a, array $b): int {
            $cmp = \strcmp($a['create_time'], $b['create_time']);
            return $cmp !== 0 ? $cmp : \strcmp($a['batch_id'], $b['batch_id']);
        });

        if (\count($rows) <= $keep) {
            return [];
        }

        $drop = \array_slice($rows, 0, \count($rows) - $keep);

        return \array_values(\array_map(static fn(array $r): string => $r['batch_id'], $drop));
    }

    /**
     * 渲染期是否可用该代次：状态必须已验证/当前，且清单校验通过。
     *
     * @param array{status?:string,manifest?:array<string,mixed>} $batch
     */
    public static function isServable(array $batch): bool
    {
        $status = (string)($batch['status'] ?? '');
        if (!\in_array($status, ['verified', 'current'], true)) {
            return false;
        }
        $verify = $batch['manifest']['verify'] ?? null;

        return \is_array($verify) && ($verify['ok'] ?? false) === true;
    }
}
