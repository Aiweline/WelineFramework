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
            $resetSecurity = isset($args['reset-security-ledger'])
                || isset($args['reset_security_ledger']);
            if ($resetSecurity && !isset($args['confirm'])) {
                return $this->failure(
                    __('重置安全账本会撤销全部项目凭据；请同时传入 --confirm。'),
                    $json,
                    'confirmation_required',
                );
            }
            $payload = self::repairPayload($args);
            if (isset($payload['accept_clock']) && \count($payload) > 1) {
                return $this->failure(
                    __('时钟信任恢复必须单独执行；请先仅运行 --accept-clock，再执行其他修复项。'),
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
     * Only selected mutations are sent. CLOCK_UNTRUSTED intentionally accepts
     * the exact admin payload {"accept_clock":true}; false/default fields would
     * turn that recovery action into an unauthorized generic mutation.
     *
     * @return array<string, true>
     */
    private static function repairPayload(array $args): array
    {
        $payload = [];
        if (isset($args['accept-clock']) || isset($args['accept_clock'])) {
            $payload['accept_clock'] = true;
        }
        if (isset($args['reset-security-ledger']) || isset($args['reset_security_ledger'])) {
            $payload['accept_security_reset'] = true;
        }
        if (isset($args['accept-storage']) || isset($args['accept_storage'])) {
            $payload['accept_storage_recovery'] = true;
        }
        if (isset($args['retry-h3']) || isset($args['retry_h3'])) {
            $payload['retry_h3'] = true;
        }

        return $payload;
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:gateway:repair',
            $this->tip(),
            [
                '--accept-clock' => __('管理员确认宿主时钟已校准并清除 CLOCK_UNTRUSTED'),
                '--accept-storage' => __('确认磁盘/配额已恢复并重新验证 journal、重建恢复预留'),
                '--retry-h3' => __('清除当前运行时的 H3 隔离并执行一次显式重新探测'),
                '--reset-security-ledger' => __('隔离损坏账本并撤销全部项目凭据（必须同时 --confirm）'),
                '--confirm' => __('确认安全账本重置这一破坏性操作'),
                '--json' => __('JSON 输出'),
            ],
            [],
            [],
        );
    }
}
