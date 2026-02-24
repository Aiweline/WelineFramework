<?php

declare(strict_types=1);

namespace Weline\Framework\System\Console\System\Config;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;

class Get extends CommandAbstract
{
    public function execute(array $args = [], array $data = [])
    {
        $positionalArgs = [];
        foreach ($args as $k => $v) {
            if (is_int($k) && !str_starts_with((string)$v, '-')) {
                $positionalArgs[] = $v;
            }
        }
        array_shift($positionalArgs);

        $key = $positionalArgs[0] ?? '';

        $env = Env::getInstance();
        $value = $env->getConfig($key);

        if ($key === '') {
            $this->printer->note(__('当前系统配置（env.php）：'));
            $this->printConfig($value);
            return;
        }

        if ($value === null) {
            $this->printer->warning(__('配置键 "%{1}" 不存在或值为 null', $key));
            return;
        }

        $this->printer->note($key . ' = ' . $this->formatValue($value));
    }

    private function printConfig(mixed $config, string $prefix = '', int $depth = 0): void
    {
        if (!is_array($config)) {
                $this->printer->note('  ' . $prefix . ' = ' . $this->formatValue($config));
            return;
        }

        foreach ($config as $k => $v) {
            $fullKey = $prefix ? $prefix . '.' . $k : (string)$k;
            if (is_array($v)) {
                $this->printConfig($v, $fullKey, $depth + 1);
            } else {
                $this->printer->note('  ' . $fullKey . ' = ' . $this->formatValue($v));
            }
        }
    }

    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return (string)$value;
    }

    public function tip(): string
    {
        return __('读取系统配置项（来自 env.php）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'system:config:get',
            __('读取 env.php 中的系统配置项，支持点号分隔的嵌套键。不传 key 则显示全部配置。'),
            [
                '-h, --help' => __('显示帮助信息'),
            ],
            [
                'key' => __('配置键名（可选），支持点号分隔（如 server.host）'),
            ],
            [
                __('查看全部配置')        => 'php bin/w system:config:get',
                __('查看服务器配置')      => 'php bin/w system:config:get server',
                __('查看监听地址')        => 'php bin/w system:config:get server.host',
                __('查看数据库类型')      => 'php bin/w system:config:get db.master.type',
            ]
        );
    }
}
