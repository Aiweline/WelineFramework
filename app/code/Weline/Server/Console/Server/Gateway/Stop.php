<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Framework\Console\CommandHelper;

final class Stop extends AbstractGatewayCommand
{
    public function execute(array $args = [], array $data = []): int
    {
        $force = isset($args['force']) || isset($args['f']);
        try {
            $response = $this->gateway()->request('stop', ['force' => $force]);
            if (!($response['ok'] ?? false)) {
                $this->printer->error((string)($response['error']['message'] ?? __('停止失败')));
                return 1;
            }
            $this->printer->success(__('宿主级 WLS 2.0 Gateway 正在停止。'));
            return 0;
        } catch (\Throwable $throwable) {
            $this->printer->error($throwable->getMessage());
            return 1;
        }
    }

    public function tip(): string
    {
        return __('显式停止共享网关；普通 server:stop 不会执行此操作');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:gateway:stop',
            $this->tip(),
            ['--force|-f' => __('存在活动路由时仍强制停止')],
            [__('危险') => __('优先逐项目 server:stop 或 gateway:revoke；共享入口停止会影响所有租户。')],
            [],
        );
    }
}
