<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Nginx;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Server\Service\Edge\Nginx\ManagedNginxService;

/**
 * Explicit installer for the per-project managed nginx under extend/server/nginx.
 */
final class Install extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): mixed
    {
        $force = isset($args['force']) || isset($args['f']);
        $this->printer->setup(__('安装本项目托管 Nginx'));
        $this->printer->warning(__('本命令可能联网下载钉死版本的 nginx 源码/压缩包，并写入 extend/server/nginx。'));
        $service = ManagedNginxService::fromEnv();
        $result = $service->install($force);
        if (!($result['ok'] ?? false)) {
            $this->printer->error((string)($result['message'] ?? __('安装失败')));
            return 1;
        }
        $this->printer->success((string)$result['message']);
        $details = $service->doctorSnapshot();
        $this->printer->note(__('二进制：%{1}', [(string)$details['binary']]));
        $this->printer->note(__('HTTP 监听：%{1}，HTTPS 监听：%{2}（offset=%{3}）', [
            (string)$details['listen_http'],
            (string)$details['listen_https'],
            (string)$details['project_offset'],
        ]));
        return 0;
    }

    public function tip(): string
    {
        return __('安装本项目托管 Nginx 到 extend/server/nginx');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:nginx:install',
            __('按平台下载并安装本项目独立 Nginx 实例；普通 server:start 不会静默下载'),
            [
                '--force|-f' => __('强制重新下载/编译安装'),
            ],
            [
                __('隔离') => __('每个项目安装到各自 BP/extend/server/nginx，互不影响'),
                __('端口') => __('未配置时使用 8080/8443 + projectPortOffset'),
                __('macOS') => __('需 Xcode CLT；建议 brew install openssl@3 pcre2'),
                __('Linux') => __('需 gcc/make 与 OpenSSL/PCRE 头文件（apt/dnf/apk 对应 *-devel/*-dev）'),
                __('Windows') => __('下载官方 nginx.zip 到 extend/server/nginx；需 ZipArchive 或 PowerShell/tar'),
            ],
            [
                __('安装') => 'php bin/w server:nginx:install',
            ]
        );
    }
}
