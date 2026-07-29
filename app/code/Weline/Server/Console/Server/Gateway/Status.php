<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Framework\Console\CommandHelper;

final class Status extends AbstractGatewayCommand
{
    public function execute(array $args = [], array $data = []): int
    {
        $json = $this->isJson($args);
        try {
            $status = $this->gateway()->administratorStatus();
            $ok = (bool)($status['ok'] ?? false);
            if (!$json) {
                $this->printer->setup(__('WLS 2.0 宿主网关状态'));
            }
            $this->output(
                $status,
                $json,
                $ok,
                (array)($status['error'] ?? ['message' => __('读取网关状态失败。')]),
            );
            return $ok ? 0 : 1;
        } catch (\Throwable $throwable) {
            return $this->failure($throwable->getMessage(), $json, 'status_failed');
        }
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
