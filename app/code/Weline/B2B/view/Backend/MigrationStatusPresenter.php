<?php

declare(strict_types=1);

namespace Weline\B2B\View\Backend;

/**
 * Present B2B migration/rollout diagnostics in operator-readable Chinese.
 */
final class MigrationStatusPresenter
{
    /**
     * @param array<string,mixed> $raw
     * @return array{
     *   summary:string,
     *   alert:string,
     *   rows:list<array{key:string,label:string,value:string,hint:string}>
     * }
     */
    public static function present(array $raw): array
    {
        if (isset($raw['status_error'])) {
            return [
                'summary' => (string) __('暂时无法读取迁移状态。'),
                'alert' => (string) $raw['status_error'],
                'rows' => [],
            ];
        }

        $mode = strtolower(trim((string) ($raw['mode'] ?? 'off')));
        $allowlistCount = (int) ($raw['allowlist_count'] ?? 0);
        $envLocked = !empty($raw['env_locked']);
        $policy = (string) ($raw['execution_policy'] ?? '');
        $productionExposed = !empty($raw['production_actions_exposed']);

        $modeValue = match ($mode) {
            'off' => (string) __('关闭（未启用）'),
            'shadow' => (string) __('影子对照（只对比，不算新价）'),
            'allowlist' => (string) __('白名单灰度'),
            'on' => (string) __('全面开启'),
            default => $mode !== '' ? $mode : (string) __('未知'),
        };

        $policyValue = match ($policy) {
            'registered_postgresql_full_clone_cli_only' => (string) __('仅允许已登记的 PostgreSQL 全量克隆命令行'),
            '' => (string) __('未配置'),
            default => $policy,
        };

        $summary = match ($mode) {
            'off' => (string) __('当前结论：B2B 价格迁移灰度未开启。本页只能查看，网页上不能执行生产迁移。'),
            'shadow' => (string) __('当前结论：处于影子对照。网页仍然不能执行生产迁移。'),
            'allowlist' => (string) __('当前结论：白名单灰度已开启。网页仍然不能执行生产迁移。'),
            'on' => (string) __('当前结论：灰度已全面开启。网页仍然不能执行生产迁移。'),
            default => (string) __('当前结论：请按下方说明理解状态。网页不能执行生产迁移。'),
        };

        return [
            'summary' => $summary,
            'alert' => (string) __('本页只查看 B2B 报价与订单价格快照相关的迁移配置。真正的生产迁移必须由运维在服务器上用受控命令行完成，网页上没有「一键迁移」。'),
            'rows' => [
                [
                    'key' => 'mode',
                    'label' => (string) __('灰度模式'),
                    'value' => $modeValue,
                    'hint' => (string) __('决定新定价逻辑是否对客户生效。'),
                ],
                [
                    'key' => 'allowlist_count',
                    'label' => (string) __('白名单站点数量'),
                    'value' => (string) $allowlistCount,
                    'hint' => (string) __('仅在「白名单灰度」模式下有意义。'),
                ],
                [
                    'key' => 'env_locked',
                    'label' => (string) __('环境锁定'),
                    'value' => $envLocked ? (string) __('是') : (string) __('否'),
                    'hint' => (string) __('锁定后配置只能读，不能在运行时改写。'),
                ],
                [
                    'key' => 'execution_policy',
                    'label' => (string) __('生产迁移执行方式'),
                    'value' => $policyValue,
                    'hint' => (string) __('生产数据迁移不走网页按钮，只走受控命令行。'),
                ],
                [
                    'key' => 'production_actions_exposed',
                    'label' => (string) __('网页是否开放生产迁移操作'),
                    'value' => $productionExposed ? (string) __('是') : (string) __('否'),
                    'hint' => (string) __('为否时，后台只提供只读诊断。'),
                ],
            ],
        ];
    }
}
