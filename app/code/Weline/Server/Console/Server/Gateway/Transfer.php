<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Framework\Console\CommandHelper;
use Weline\Server\Service\Edge\Gateway\GatewayRegistrationBuilder;

final class Transfer extends AbstractGatewayCommand
{
    public function execute(array $args = [], array $data = []): int
    {
        $json = $this->isJson($args);
        if (!isset($args['confirm'])) {
            return $this->failure(
                __('域名转移会原子改变宿主入口归属；请提供 --confirm。'),
                $json,
                'confirmation_required',
            );
        }
        $domain = \trim((string)($args['domain'] ?? ''));
        $from = \strtolower(\trim((string)($args['from'] ?? '')));
        $to = \strtolower(\trim((string)($args['to'] ?? '')));
        $instance = \trim((string)(
            $args['instance'] ?? $this->positional($args)
        ));
        if ($domain === '' || $from === '' || $to === '' || $instance === '') {
            return $this->failure(
                __('必须提供 --domain、--from、--to 和目标项目 --instance。'),
                $json,
                'transfer_arguments_required',
            );
        }
        try {
            $current = (new GatewayRegistrationBuilder())->projectUuid();
            if (!\hash_equals($current, $to)) {
                return $this->failure(
                    __('--to 必须等于当前目标项目 UUID：%{1}', [$current]),
                    $json,
                    'transfer_target_mismatch',
                    ['expected_project_uuid' => $current],
                );
            }
            $result = $this->gateway()->transferDomain(
                $instance,
                $domain,
                $from,
                $to,
            );
            if (!$json) {
                $this->printer->success(
                    __('域名已在同一网关 generation 内转移到当前项目。')
                );
            }
            $this->output($result, $json);
            return 0;
        } catch (\Throwable $throwable) {
            return $this->failure($throwable->getMessage(), $json, 'transfer_failed');
        }
    }

    public function tip(): string
    {
        return __('将域名原子转移到当前已 enrollment 的项目');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:gateway:transfer --domain=<domain> --from=<uuid> --to=<uuid> '
                . '--instance=<name> --confirm',
            $this->tip(),
            [
                '--domain <domain>' => __('待转移的精确域名或通配符域名'),
                '--from <uuid>' => __('当前源项目 UUID'),
                '--to <uuid>' => __('目标项目 UUID，必须是当前项目'),
                '--instance <name>' => __('目标项目中用于证明后端和证书的运行实例'),
                '--confirm' => __('确认短租约预检、目标证明和原子归属切换'),
                '--json' => __('输出 JSON 结果'),
            ],
            [
                __('安全语义') => __(
                    '目标项目先通过自己的凭据证明后端与证书；提交失败时不会双归属，'
                    . '安全墓碑已落盘的失败会停止数据面。'
                ),
            ],
            [],
        );
    }
}
