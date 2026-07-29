<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Framework\Console\CommandHelper;

final class Start extends AbstractGatewayCommand
{
    public function execute(array $args = [], array $data = []): int
    {
        $json = $this->isJson($args);
        if (!isset($args['confirm'])) {
            return $this->failure(
                __('重新启用共享网关会清除签名 ADMIN_STOPPED 意图；请提供 --confirm。'),
                $json,
                'confirmation_required',
            );
        }
        try {
            $result = $this->gateway()->startGateway();
            if (!$json) {
                $this->printer->success(
                    __('宿主级 WLS 2.0 Gateway 已由管理员显式重新启用。')
                );
            }
            $this->output($result, $json);
            return 0;
        } catch (\Throwable $throwable) {
            return $this->failure($throwable->getMessage(), $json, 'gateway_start_failed');
        }
    }

    public function tip(): string
    {
        return __('清除已验证的 ADMIN_STOPPED 意图并重新启用共享网关');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:gateway:start --confirm',
            $this->tip(),
            [
                '--confirm' => __('确认清除停止意图并启用宿主平台服务'),
                '--json' => __('输出 JSON 状态'),
            ],
            [],
            [],
        );
    }
}
