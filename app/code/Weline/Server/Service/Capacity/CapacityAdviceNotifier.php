<?php
declare(strict_types=1);

namespace Weline\Server\Service\Capacity;

/**
 * Emits one ops tip via w_msg (skip_if_unread). No scale side effects.
 */
final class CapacityAdviceNotifier
{
    public const TOPIC = 'wls_capacity_advice';
    public const DEDUPE_KEY = 'wls_capacity_advice';

    /**
     * @param list<string> $reasonCodes
     * @param list<string> $advice
     * @param array<string, mixed> $summary
     */
    public function notifyIfNeeded(
        bool $notifyEnabled,
        bool $triggered,
        array $reasonCodes,
        array $advice,
        array $summary,
    ): bool {
        if (!$notifyEnabled || !$triggered || $reasonCodes === []) {
            return false;
        }
        if (!\function_exists('w_msg')) {
            return false;
        }

        $title = (string)__('WLS 容量建议：机器资源可能不足');
        $lines = [
            (string)__('实例：%{1}', [(string)($summary['instance'] ?? 'default')]),
            (string)__('原因：%{1}', [\implode(', ', $reasonCodes)]),
            (string)__('建议：%{1}', [\implode(', ', $advice)]),
            (string)__(
                'Worker 压力 avg=%{1} max=%{2}；主机压力=%{3}',
                [
                    \sprintf('%.2f', (float)($summary['avg_pressure'] ?? 0)),
                    \sprintf('%.2f', (float)($summary['max_pressure'] ?? 0)),
                    \sprintf('%.2f', (float)($summary['host_pressure'] ?? 0)),
                ]
            ),
            (string)__('请在 WLS 面板查看负载曲线，并人工增加服务器内存/Worker 配置。'),
        ];

        try {
            w_msg(
                self::TOPIC,
                'warning',
                $title,
                \implode("\n", $lines),
                [
                    'icon' => 'warning',
                    'source_module' => 'Weline_Server',
                    'dedupe_key' => self::DEDUPE_KEY,
                    'metadata' => [
                        'skip_if_unread' => true,
                        'reason_codes' => $reasonCodes,
                        'advice' => $advice,
                        'summary' => $summary,
                    ],
                ]
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
