<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Framework\Console\CommandHelper;

final class Repair extends AbstractGatewayCommand
{
    public function execute(array $args = [], array $data = []): int
    {
        try {
            $response = $this->gateway()->request('repair');
            if (!($response['ok'] ?? false)) {
                $this->printer->error((string)($response['error']['message'] ?? __('修复失败')));
                return 1;
            }
            $this->printer->success(__('网关已执行身份安全的配置重发、LKG/数据面恢复。'));
            $this->output((array)($response['payload'] ?? []), isset($args['json']));
            return 0;
        } catch (\Throwable $throwable) {
            $this->printer->error($throwable->getMessage());
            return 1;
        }
    }

    public function tip(): string
    {
        return __('触发网关安全恢复并解除当前熔断等待');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp('server:gateway:repair', $this->tip(), ['--json' => __('JSON 输出')], [], []);
    }
}
