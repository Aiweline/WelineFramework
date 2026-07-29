<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Framework\Console\CommandHelper;

final class Upgrade extends AbstractGatewayCommand
{
    public function execute(array $args = [], array $data = []): int
    {
        $json = $this->isJson($args);
        if (!isset($args['confirm'])) {
            return $this->failure(
                __('网关升级必须由管理员显式携带 --confirm。'),
                $json,
                'confirmation_required',
            );
        }
        $package = \trim((string)($args['package'] ?? ''));
        if ($package === '') {
            return $this->failure(
                __('必须通过 --package 指定签名自包含候选包目录。'),
                $json,
                'package_required',
            );
        }
        $profile = \strtolower(\trim((string)($args['profile'] ?? 'default')));
        try {
            $result = $this->gateway()->upgrade($package, $profile);
            if (!$json) {
                $this->printer->success(
                    __('WLS 2.0 Gateway 全包已切换到候选 A/B 槽；Root Broker 正在执行五分钟观察。')
                );
            }
            $this->output($result, $json);
            return 0;
        } catch (\Throwable $throwable) {
            return $this->failure($throwable->getMessage(), $json, 'gateway_upgrade_failed');
        }
    }

    public function tip(): string
    {
        return __('安装并激活经过自检的 WLS 2.0 Gateway A/B 候选槽');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:gateway:upgrade --package=/absolute/package --confirm [--profile=default]',
            $this->tip(),
            [
                '--package' => __('签名自包含候选包目录'),
                '--profile' => __('default（IPv4+IPv6）或 ipv4-only'),
                '--confirm' => __('确认进入 A/B 升级事务'),
                '--json' => __('JSON 输出'),
            ],
            [],
            [],
        );
    }
}
