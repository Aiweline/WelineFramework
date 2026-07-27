<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Framework\Console\CommandHelper;

final class Routes extends AbstractGatewayCommand
{
    public function execute(array $args = [], array $data = []): int
    {
        try {
            $response = $this->gateway()->request('routes');
            $payload = (array)($response['payload'] ?? []);
            $this->printer->setup(__('WLS 2.0 网关路由'));
            $this->output($payload, isset($args['json']));
            return ($response['ok'] ?? false) ? 0 : 1;
        } catch (\Throwable $throwable) {
            $this->printer->error($throwable->getMessage());
            return 1;
        }
    }

    public function tip(): string
    {
        return __('查看宿主网关的项目路由与租约状态');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp('server:gateway:routes', $this->tip(), ['--json' => __('JSON 输出')], [], []);
    }
}
