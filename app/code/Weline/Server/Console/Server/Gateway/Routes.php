<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Framework\Console\CommandHelper;

final class Routes extends AbstractGatewayCommand
{
    public function execute(array $args = [], array $data = []): int
    {
        $json = $this->isJson($args);
        try {
            $response = $this->gateway()->request('routes');
            $payload = (array)($response['payload'] ?? []);
            $ok = (bool)($response['ok'] ?? false);
            if (!$json) {
                $this->printer->setup(__('WLS 2.0 网关路由'));
            }
            $this->output(
                $payload,
                $json,
                $ok,
                (array)($response['error'] ?? ['message' => __('读取网关路由失败。')]),
            );
            return $ok ? 0 : 1;
        } catch (\Throwable $throwable) {
            return $this->failure($throwable->getMessage(), $json, 'routes_failed');
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
