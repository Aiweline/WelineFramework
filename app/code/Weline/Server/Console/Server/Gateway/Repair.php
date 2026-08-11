<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Framework\Console\CommandHelper;

final class Repair extends AbstractGatewayCommand
{
    public function execute(array $args = [], array $data = []): int
    {
        $json = $this->isJson($args);
        try {
            $payload = self::repairPayload($args);
            $combinationError = self::repairPayloadValidationError($payload);
            if ($combinationError !== '') {
                return $this->failure(
                    $combinationError,
                    $json,
                    'invalid_repair_combination',
                );
            }
            $response = $this->gateway()->request('repair', $payload);
            if (!($response['ok'] ?? false)) {
                $error = (array)($response['error'] ?? []);
                return $this->failure(
                    (string)($error['message'] ?? __('修复失败')),
                    $json,
                    (string)($error['code'] ?? 'repair_failed'),
                    (array)($error['details'] ?? []),
                );
            }
            if (!$json) {
                $this->printer->success(__('网关已执行身份安全的配置重发、LKG/数据面恢复。'));
            }
            $this->output((array)($response['payload'] ?? []), $json);
            return 0;
        } catch (\Throwable $throwable) {
            return $this->failure($throwable->getMessage(), $json, 'repair_failed');
        }
    }

    public function tip(): string
    {
        return __('触发网关安全恢复并解除当前熔断等待');
    }

    /**
     * Only selected mutations are sent. CLOCK_UNTRUSTED accepts either the
     * exact clock acknowledgement or the Controller's exact storage-recovery
     * field set, which is required when both trust fences are active.
     *
     * @return array<string, true>
     */
    private static function repairPayload(array $args): array
    {
        $payload = [];
        if (isset($args['accept-clock']) || isset($args['accept_clock'])) {
            $payload['accept_clock'] = true;
        }
        if (isset($args['accept-storage'])
            || isset($args['accept_storage'])
            || isset($args['accept-storage-recovery'])
            || isset($args['accept_storage_recovery'])
        ) {
            $payload['accept_storage_recovery'] = true;
        }
        if (isset($args['accept-journal-reset'])
            || isset($args['accept_journal_reset'])
        ) {
            $payload['accept_journal_reset'] = true;
        }
        if (isset($args['retry-h3']) || isset($args['retry_h3'])) {
            $payload['retry_h3'] = true;
        }

        return $payload;
    }

    /** @param array<string,true> $payload */
    private static function repairPayloadValidationError(array $payload): string
    {
        if (isset($payload['retry_h3']) && \count($payload) > 1) {
            return __('H3 重新探测不能与时钟、存储或 journal 信任恢复合并执行。');
        }
        if (isset($payload['accept_journal_reset'])
            && !isset($payload['accept_storage_recovery'])
        ) {
            return __('journal 信任重置必须同时指定 --accept-storage。');
        }
        return '';
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:gateway:repair',
            $this->tip(),
            [
                '--accept-clock' => __('管理员确认宿主时钟已校准并清除 CLOCK_UNTRUSTED'),
                '--accept-storage' => __('确认磁盘/配额已恢复并重新验证 journal、重建恢复预留'),
                '--accept-storage-recovery' => __('--accept-storage 的协议字段兼容别名'),
                '--accept-journal-reset' => __('确认丢弃不可信 journal 链并从当前受信状态重新建立'),
                '--retry-h3' => __('清除当前运行时的 H3 隔离并执行一次显式重新探测'),
                '--json' => __('JSON 输出'),
            ],
            [],
            [],
        );
    }
}
