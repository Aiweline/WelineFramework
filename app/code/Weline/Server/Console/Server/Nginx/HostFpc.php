<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Nginx;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Server\Service\Edge\Nginx\HostNginxFpcDiskPublisher;

/**
 * Write the host-Nginx FPC page-generation map and reload.
 * server:start calls the same publisher; this command is for a root heal
 * step that runs before the www-user WLS process.
 */
final class HostFpc extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): mixed
    {
        $host = \trim((string)($args['host'] ?? ''));
        $port = (int)($args['p'] ?? $args['port'] ?? 0);
        if ($host === '' || $port < 1 || !\defined('BP')) {
            $this->printer->error(__('用法：server:nginx:host-fpc --host=<域名> -p=<回环端口>'));
            return 1;
        }
        $nginxBin = \trim((string)($args['nginx-bin'] ?? $args['nginx_bin'] ?? ''));
        $nginxConf = \trim((string)($args['nginx-conf'] ?? $args['nginx_conf'] ?? ''));
        $result = (new HostNginxFpcDiskPublisher())->publish(
            (string)\BP,
            $host,
            $port,
            $nginxBin !== '' ? $nginxBin : null,
            $nginxConf !== '' ? $nginxConf : null,
        );
        $message = (string)($result['message'] ?? '');
        $notice = \trim((string)($result['notice'] ?? ''));
        if ($notice !== '') {
            $this->printer->note($notice);
        }
        if (($result['reloaded'] ?? false) === true) {
            $this->printer->success(__('宿主 Nginx 已加载 FPC 世代配置。'));
            return 0;
        }
        if (($result['ok'] ?? false) === true || ($result['skipped'] ?? false) === true) {
            $this->printer->note($message !== '' ? $message : __('宿主 Nginx FPC 配置无变化。'));
            return 0;
        }
        $this->printer->error(__('宿主 Nginx FPC 配置未加载：%{1}', [$message]));
        return 1;
    }

    public function tip(): string
    {
        return __('写入宿主 Nginx 的 FPC 世代 map/location 并 reload');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:nginx:host-fpc',
            __('宿主 Nginx（系统/宝塔/第三方）接入本项目 FPC 世代读取；需 root 或配置属主权限'),
            [
                '--host' => __('宿主 Nginx server_name，例如 shop.example.com'),
                '-p, --port' => __('WLS 回环端口，例如 9555'),
                '--nginx-bin' => __('非标准宿主 Nginx 二进制路径（可选）'),
                '--nginx-conf' => __('非标准宿主 Nginx 主配置路径（可选）'),
            ],
            [],
            [__('示例') => 'sudo php bin/w server:nginx:host-fpc --host=shop.example.com -p=9555']
        );
    }
}
