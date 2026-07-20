<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Nginx;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Server\Service\Edge\Nginx\ManagedNginxService;

final class Status extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): mixed
    {
        $snap = ManagedNginxService::fromEnv()->doctorSnapshot();
        if (isset($args['json'])) {
            echo \json_encode($snap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
            return 0;
        }
        $this->printer->setup(__('本项目托管 Nginx 状态'));
        foreach ([
            'managed' => __('托管'),
            'installed' => __('已安装'),
            'running' => __('运行中'),
            'pid' => 'PID',
            'binary' => __('二进制'),
            'listen_http' => 'HTTP',
            'listen_https' => 'HTTPS',
            'project_offset' => __('项目偏移'),
            'install_root' => __('安装目录'),
            'runtime_root' => __('运行目录'),
        ] as $key => $label) {
            $value = $snap[$key] ?? '';
            if (\is_bool($value)) {
                $value = $value ? __('是') : __('否');
            }
            $this->printer->note($label . ': ' . (string)$value);
        }
        return 0;
    }

    public function tip(): string
    {
        return __('查看本项目托管 Nginx 状态');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:nginx:status',
            __('查看本项目托管 Nginx 安装与运行状态'),
            ['--json' => __('JSON 输出')],
            [],
            [__('示例') => 'php bin/w server:nginx:status']
        );
    }
}
