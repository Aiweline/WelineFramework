<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Framework\Console\CommandHelper;

final class Stop extends AbstractGatewayCommand
{
    public function execute(array $args = [], array $data = []): int
    {
        $json = $this->isJson($args);
        if (!isset($args['confirm'])) {
            return $this->failure(
                __('共享网关停止会影响所有租户；请提供 --confirm。'),
                $json,
                'confirmation_required',
            );
        }
        $force = isset($args['force']) || isset($args['f']);
        try {
            $this->gateway()->stopGateway($force);
            if (!$json) {
                $this->printer->success(
                    __('宿主级 WLS 2.0 Gateway 已持久停止；平台守护不会自动拉起。')
                );
            }
            $this->output(['admin_stopped' => true, 'forced' => $force], $json);
            return 0;
        } catch (\Throwable $throwable) {
            return $this->failure($throwable->getMessage(), $json, 'gateway_stop_failed');
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
            [
                '--confirm' => __('确认写入签名 ADMIN_STOPPED 意图并禁用平台服务'),
                '--force|-f' => __('存在活动路由时仍强制停止'),
                '--json' => __('输出稳定 JSON 文档'),
            ],
            [__('危险') => __('优先逐项目 server:stop 或 gateway:revoke；共享入口停止会影响所有租户。')],
            [],
        );
    }
}
