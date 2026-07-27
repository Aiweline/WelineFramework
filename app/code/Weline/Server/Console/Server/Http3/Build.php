<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Http3;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;

/**
 * Retired compatibility command for the removed WLS-native HTTP/3 transport.
 */
final class Build extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): int
    {
        $this->printer->error(__(
            'WLS 原生 HTTP/3 构建已退役；公网协议只由 Nginx 提供。'
        ));
        $this->printer->note(__(
            '如需安装或重建项目隔离 Nginx，请显式执行 php bin/w server:nginx:install；普通启动不会下载或编译。'
        ));

        return 1;
    }

    public function tip(): string
    {
        return __('已退役：WLS 原生 HTTP/3 构建');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:http3:build',
            __('该兼容命令已退役；HTTP/3 仅由包含 ngx_http_v3_module 的 Nginx 提供'),
            [],
            [
                __('替代命令') => __('php bin/w server:nginx:install'),
                __('能力门禁') => __('server:doctor 读取 nginx -V；真实 QUIC 请求通过前不会宣称 HTTP/3 已验证'),
            ],
            []
        );
    }
}
