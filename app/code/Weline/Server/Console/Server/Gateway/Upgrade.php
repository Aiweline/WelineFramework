<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Framework\Console\CommandHelper;

final class Upgrade extends AbstractGatewayCommand
{
    public function execute(array $args = [], array $data = []): int
    {
        try {
            $gateway = $this->gateway();
            $slot = $gateway->installInactiveSlot();
            $response = $gateway->request('upgrade', ['activate' => true, 'slot' => $slot['slot']]);
            if (!($response['ok'] ?? false)) {
                $this->printer->error((string)($response['error']['message'] ?? __('升级失败')));
                return 1;
            }
            $this->printer->success(__('WLS 2.0 Gateway 已切换到候选 A/B 槽；五分钟崩溃循环将自动回滚。'));
            $this->output((array)($response['payload'] ?? []), isset($args['json']));
            return 0;
        } catch (\Throwable $throwable) {
            $this->printer->error($throwable->getMessage());
            return 1;
        }
    }

    public function tip(): string
    {
        return __('安装并激活经过自检的 WLS 2.0 Gateway A/B 候选槽');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp('server:gateway:upgrade', $this->tip(), ['--json' => __('JSON 输出')], [], []);
    }
}
