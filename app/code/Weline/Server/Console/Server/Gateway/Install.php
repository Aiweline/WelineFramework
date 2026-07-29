<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Framework\Console\CommandHelper;

final class Install extends AbstractGatewayCommand
{
    public function execute(array $args = [], array $data = []): int
    {
        $json = $this->isJson($args);
        if (!isset($args['confirm'])) {
            return $this->failure(
                __('宿主级安装必须由管理员显式携带 --confirm。'),
                $json,
                'confirmation_required',
            );
        }
        $package = \trim((string)($args['package'] ?? ''));
        if ($package === '') {
            return $this->failure(
                __('必须通过 --package 指定离线或 CI 生成的签名自包含包目录。'),
                $json,
                'package_required',
            );
        }
        $profile = \strtolower(\trim((string)($args['profile'] ?? 'default')));
        if (!\in_array($profile, ['default', 'ipv4-only'], true)) {
            return $this->failure(
                __('--profile 只允许 default 或 ipv4-only。'),
                $json,
                'invalid_profile',
            );
        }

        try {
            $result = $this->gateway()->install($package, $profile);
        } catch (\Throwable $throwable) {
            return $this->failure($throwable->getMessage(), $json, 'install_failed');
        }
        $ok = (bool)($result['ok'] ?? false);
        $this->output(
            $result,
            $json,
            $ok,
            [
                'code' => 'install_failed',
                'message' => (string)($result['reason'] ?? __('WLS 2.0 Gateway 安装失败。')),
            ],
        );
        if (!$ok) {
            if (!$json) {
                $this->printer->error((string)($result['reason'] ?? __('WLS 2.0 Gateway 安装失败。')));
            }
            return 1;
        }
        if (!$json && ($result['test_mode'] ?? false)) {
            $this->printer->warning(__('测试包已安装到隔离根；它不会被生产客户端信任，也不会报告 release ready。'));
        } elseif (!$json) {
            $this->printer->success(__('WLS 2.0 Gateway 自包含包和系统服务安装完成。'));
        }
        return 0;
    }

    public function tip(): string
    {
        return __('显式安装签名、自包含的宿主级 WLS 2.0 Gateway');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:gateway:install --package=/absolute/package --confirm [--profile=default]',
            $this->tip(),
            [
                '--package' => __('签名自包含包目录'),
                '--profile' => __('default（IPv4+IPv6）或 ipv4-only'),
                '--confirm' => __('确认写入系统信任根并安装系统服务'),
                '--json' => __('JSON 输出'),
            ],
            [
                __('安全边界') => __('普通 server:start/auto 不会调用本命令；未知 80/443 owner 永不被终止。'),
            ],
            [],
        );
    }
}
