<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Nginx;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Server\Service\Edge\Nginx\ManagedNginxService;

/**
 * Explicit installer for the per-project managed nginx local install root.
 */
final class Install extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): mixed
    {
        $force = isset($args['force']) || isset($args['f']);
        $this->printer->setup(__('安装本项目托管 Nginx'));
        $this->printer->warning(__('本命令可能联网下载钉死版本的 Nginx 源码/压缩包，并写入项目隔离的本地安装根。'));
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
        return __('安装本项目托管 Nginx 到项目隔离的本地安装根');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:nginx:install',
            __('按平台下载并安装本项目独立 Nginx 实例；普通 server:start 不会静默下载'),
            [
                '--force|-f' => __('强制重新获取并按平台重新安装（macOS/Linux 编译，Windows 解压官方预编译包）'),
            ],
            [
                __('隔离') => __('每个项目使用独立安装根；Windows 固定使用本机 LOCALAPPDATA，避免 UNC/共享盘解压'),
                __('端口') => __('未配置时使用 8080/8443 + projectPortOffset'),
                __('macOS') => __('需 Xcode CLT、OpenSSL 3 与 PCRE2；可执行 brew install openssl@3 pcre2'),
                __('Linux') => __('需 gcc/make 与 OpenSSL/PCRE 头文件（apt/dnf/apk 对应 *-devel/*-dev）'),
                __('Windows') => __('下载官方 nginx.zip 到本机项目隔离目录；需 ZipArchive 或 PowerShell/tar'),
            ],
            [
                __('安装') => 'php bin/w server:nginx:install',
            ]
        );
    }
}
