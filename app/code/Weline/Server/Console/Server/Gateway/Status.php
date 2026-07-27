<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Framework\Console\CommandHelper;

final class Status extends AbstractGatewayCommand
{
    public function execute(array $args = [], array $data = []): int
    {
        $status = $this->gateway()->status();
        $this->printer->setup(__('WLS 2.0 宿主网关状态'));
        $this->output($status, isset($args['json']));
        return ($status['ok'] ?? false) ? 0 : 1;
    }

    public function tip(): string
    {
        return __('查看宿主级 WLS 2.0 Gateway 状态');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp('server:gateway:status', $this->tip(), ['--json' => __('JSON 输出')], [], []);
    }
}
