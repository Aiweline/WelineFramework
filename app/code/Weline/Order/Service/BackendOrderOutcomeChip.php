<?php

declare(strict_types=1);

namespace Weline\Order\Service;

/**
 * 后台结果态芯片：成功用绿色勾，不用英文 code 字面量。
 */
final class BackendOrderOutcomeChip
{
    /**
     * @return array{tone: string, icon: string, label: string, kind: string, raw: string}
     */
    public static function present(string $raw): array
    {
        $code = strtolower(trim($raw));
        if ($code === '') {
            return [
                'tone' => '',
                'icon' => '',
                'label' => '',
                'kind' => 'empty',
                'raw' => '',
            ];
        }

        if (self::isSuccess($code)) {
            return [
                'tone' => 'success',
                'icon' => 'check-circle',
                'label' => (string)\__('成功'),
                'kind' => 'success',
                'raw' => $code,
            ];
        }
        if (self::isFailed($code)) {
            return [
                'tone' => 'danger',
                'icon' => 'x-circle',
                'label' => (string)\__('失败'),
                'kind' => 'failed',
                'raw' => $code,
            ];
        }
        if (self::isProcessing($code)) {
            return [
                'tone' => 'warning',
                'icon' => 'clock',
                'label' => (string)\__('处理中'),
                'kind' => 'processing',
                'raw' => $code,
            ];
        }
        if ($code === 'cancelled' || $code === 'canceled') {
            return [
                'tone' => 'secondary',
                'icon' => 'close',
                'label' => (string)\__('已取消'),
                'kind' => 'cancelled',
                'raw' => $code,
            ];
        }

        return [
            'tone' => 'info',
            'icon' => 'circle',
            'label' => $raw,
            'kind' => 'other',
            'raw' => $code,
        ];
    }

    /**
     * 合并多段状态（如 case/channel），同类只出一枚芯片。
     *
     * @return list<array{tone: string, icon: string, label: string, kind: string, raw: string}>
     */
    public static function presentMany(string ...$raws): array
    {
        $out = [];
        $seen = [];
        foreach ($raws as $raw) {
            $chip = self::present($raw);
            if (($chip['kind'] ?? '') === 'empty') {
                continue;
            }
            $key = (string)($chip['kind'] ?? '') . '|' . (string)($chip['label'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $chip;
        }

        return $out;
    }

    private static function isSuccess(string $code): bool
    {
        return \in_array($code, [
            'succeeded',
            'success',
            'successful',
            'completed',
            'done',
            'ok',
            'refunded',
        ], true);
    }

    private static function isFailed(string $code): bool
    {
        return \in_array($code, [
            'failed',
            'fail',
            'error',
            'dead',
            'rejected',
        ], true);
    }

    private static function isProcessing(string $code): bool
    {
        return \in_array($code, [
            'processing',
            'pending',
            'submitted',
            'open',
            'requested',
            'unknown',
            'refund_late_success_review',
        ], true);
    }
}
