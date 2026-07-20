<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Nginx;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Server\Service\Edge\Nginx\ManagedNginxService;

final class Stop extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): mixed
    {
        $result = ManagedNginxService::fromEnv()->stop();
        if (!($result['ok'] ?? false)) {
            $this->printer->error((string)$result['message']);
            return 1;
        }
        $this->printer->success((string)$result['message']);
        return 0;
    }

    public function tip(): string
    {
        return __('停止本项目托管 Nginx');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:nginx:stop',
            __('停止本项目 var/server/nginx 下的托管 Nginx 进程'),
            [],
            [],
            [__('示例') => 'php bin/w server:nginx:stop']
        );
    }
}
