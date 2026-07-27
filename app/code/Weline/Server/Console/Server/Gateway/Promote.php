<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Framework\Console\CommandHelper;
use Weline\Server\Service\Edge\Gateway\GatewayHostManager;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Edge\Nginx\ManagedNginxService;

final class Promote extends AbstractGatewayCommand
{
    public function execute(array $args = [], array $data = []): int
    {
        if (!isset($args['confirm'])) {
            $this->printer->error(__('显式提升必须携带 --confirm，且只能由当前 80/443 的受管 owner 执行。'));
            return 1;
        }
        $legacy = ManagedNginxService::fromEnv();
        $snapshot = $legacy->doctorSnapshot();
        $paths = new GatewayPaths();
        $owner = \trim((string)($snapshot['owner_instance'] ?? ''));
        if (!($snapshot['running'] ?? false)
            || !($snapshot['runtime_owner_active'] ?? false)
            || $owner === ''
            || (int)($snapshot['listen_http'] ?? 0) !== $paths->publicHttpPort()
            || (int)($snapshot['listen_https'] ?? 0) !== $paths->publicHttpsPort()
        ) {
            $this->printer->error(__('只有身份已验证、正在占用目标公共端口的项目托管 Nginx 才能提升。'));
            return 1;
        }
        $upstreamHost = (string)($snapshot['owner_upstream_host'] ?? '127.0.0.1');
        $upstreamPort = (int)($snapshot['owner_upstream_port'] ?? 0);
        $serverNames = (array)($snapshot['owner_server_names'] ?? []);

        $stopped = $legacy->stopForInstance($owner);
        if (!($stopped['ok'] ?? false)) {
            $this->printer->error(__('旧 Nginx 未安全停止，提升已中止：%{1}', [(string)$stopped['message']]));
            return 1;
        }
        try {
            $gateway = new GatewayHostManager();
            $ready = $gateway->prepare();
            if (!($ready['ok'] ?? false) || !($ready['ready'] ?? false)) {
                throw new \RuntimeException((string)($ready['reason'] ?? 'Gateway did not become ready.'));
            }
            $gateway->register($owner);
            $this->printer->success(__('WLS 1.x 公共端口 owner 已显式提升为宿主级 WLS 2.0 Gateway。'));
            return 0;
        } catch (\Throwable $throwable) {
            $rollback = $legacy->prepareAndStart(
                $upstreamPort,
                $upstreamHost,
                $serverNames,
                $owner,
                'nginx',
            );
            $this->printer->error(__('提升失败：%{1}', [$throwable->getMessage()]));
            if ($rollback['ok'] ?? false) {
                $this->printer->warning(__('已回滚并恢复原项目托管 Nginx。'));
            } else {
                $this->printer->error(__('原项目 Nginx 回滚也失败：%{1}', [(string)($rollback['message'] ?? '')]));
            }
            return 1;
        }
    }

    public function tip(): string
    {
        return __('将当前 80/443 的 WLS 1.x 受管 owner 显式提升为 WLS 2.0 Gateway');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:gateway:promote --confirm',
            $this->tip(),
            ['--confirm' => __('确认公共端口所有权切换')],
            [__('回滚') => __('提升失败时使用冻结的 owner/upstream/server_name 快照恢复旧 Nginx。')],
            [],
        );
    }
}
