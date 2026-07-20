<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Nginx;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Server\Service\Edge\Nginx\ManagedNginxService;

final class Start extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): mixed
    {
        $upstream = (int)($args['upstream'] ?? $args['u'] ?? 0);
        if ($upstream <= 0) {
            $this->printer->error(__('请提供 --upstream=<WLS端口>，例如 --upstream=9981'));
            return 1;
        }
        $host = \trim((string)($args['upstream-host'] ?? $args['upstream_host'] ?? '127.0.0.1'));
        $service = ManagedNginxService::fromEnv();
        $result = $service->prepareAndStart($upstream, $host !== '' ? $host : '127.0.0.1');
        if (!($result['ok'] ?? false)) {
            $this->printer->error((string)$result['message']);
            return 1;
        }
        $this->printer->success((string)$result['message']);
        $details = \is_array($result['details'] ?? null) ? $result['details'] : [];
        if ($details !== []) {
            $this->printer->note(__('边缘 HTTP：%{1} → %{2}', [
                (string)($details['listen_http'] ?? ''),
                (string)($details['upstream'] ?? ''),
            ]));
            if (!empty($details['ssl'])) {
                $this->printer->note(__('边缘 HTTPS：%{1}', [(string)($details['listen_https'] ?? '')]));
            }
        }
        return 0;
    }

    public function tip(): string
    {
        return __('启动本项目托管 Nginx');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:nginx:start',
            __('生成本项目 nginx.conf 并启动托管 Nginx'),
            [
                '--upstream=<port>' => __('WLS 明文回源端口'),
                '--upstream-host=<host>' => __('回源主机，默认 127.0.0.1'),
            ],
            [],
            [
                __('示例') => 'php bin/w server:nginx:start --upstream=9981',
            ]
        );
    }
}
