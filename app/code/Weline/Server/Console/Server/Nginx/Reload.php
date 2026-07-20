<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Nginx;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Server\Service\Edge\Nginx\ManagedNginxService;

final class Reload extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): mixed
    {
        $result = ManagedNginxService::fromEnv()->reload();
        if (!($result['ok'] ?? false)) {
            $this->printer->error((string)$result['message']);
            return 1;
        }
        $this->printer->success((string)$result['message']);
        return 0;
    }

    public function tip(): string
    {
        return __('热重载本项目托管 Nginx');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:nginx:reload',
            __('对本项目托管 Nginx 执行 -s reload（证书续签后亦可自动触发）'),
            [],
            [],
            [__('示例') => 'php bin/w server:nginx:reload']
        );
    }
}
