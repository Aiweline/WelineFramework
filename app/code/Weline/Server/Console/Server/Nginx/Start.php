<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Nginx;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\Edge\Nginx\ManagedNginxService;
use Weline\Server\Service\ServerInstanceManager;

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
        $owner = \trim((string)($args['owner'] ?? $args['instance'] ?? ''));
        if ($owner === '') {
            $this->printer->error(__('请提供 --owner=<WLS实例名>，托管 Nginx 不接受未归因的回源接管'));
            return 1;
        }
        /** @var ServerInstanceManager $manager */
        $manager = ObjectManager::getInstance(ServerInstanceManager::class);
        $endpoint = $manager->getRawInstanceData($owner);
        $instance = $manager->getInstanceInfoWithIpcTimeout($owner, false, 0.5);
        if (!\is_array($endpoint)
            || $instance === null
            || !$instance->isMasterRunning()
            || (int)($endpoint['port'] ?? $endpoint['main_port'] ?? 0) !== $upstream
            || (string)($endpoint['edge_adapter'] ?? '') !== 'nginx'
        ) {
            $this->printer->error(__('owner 必须是运行中的 Nginx-edge WLS 实例，且其端口必须等于 --upstream'));
            return 1;
        }
        $publicHost = \trim((string)($endpoint['public_host'] ?? ''));
        $publicHost = $publicHost !== '' ? $publicHost : \trim((string)($endpoint['host'] ?? ''));
        $serverNames = $publicHost !== '' ? [$publicHost] : [];

        $service = ManagedNginxService::fromEnv();
        $result = $service->prepareAndStart(
            $upstream,
            $host !== '' ? $host : '127.0.0.1',
            $serverNames,
            $owner,
            \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX,
        );
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
                '--owner=<instance>' => __('拥有该托管边缘的 WLS 实例名'),
            ],
            [],
            [
                __('示例') => 'php bin/w server:nginx:start --upstream=9981 --owner=default',
            ]
        );
    }
}
