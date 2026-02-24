<?php

declare(strict_types=1);

namespace Weline\Framework\System\Console\System\Config;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;

class Set extends CommandAbstract
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

        $key = $positionalArgs[0] ?? null;
        $value = $positionalArgs[1] ?? null;

        if ($key === null || $value === null) {
            $this->printer->error(__('用法: php bin/w system:config:set <key> <value>'));
            $this->printer->note(__('示例: php bin/w system:config:set server.host 127.0.0.1'));
            return;
        }

        $env = Env::getInstance();
        $oldValue = $env->getConfig($key);

        $castValue = $this->castValue($value);

        $result = $env->setConfig($key, $castValue);

        if ($result) {
            $this->printer->success(__('配置已更新'));
            $this->printer->note('  ' . $key . ': ' . $this->formatValue($oldValue) . ' → ' . $this->formatValue($castValue));
        } else {
            $this->printer->error(__('配置写入失败，请检查 app/etc/env.php 文件权限'));
        }
    }

    /**
     * 将字符串值转换为合适的 PHP 类型
     */
    private function castValue(string $raw): mixed
    {
        if (strtolower($raw) === 'true') {
            return true;
        }
        if (strtolower($raw) === 'false') {
            return false;
        }
        if (strtolower($raw) === 'null') {
            return null;
        }
        if (ctype_digit($raw) || (str_starts_with($raw, '-') && ctype_digit(substr($raw, 1)))) {
            return (int)$raw;
        }
        if (is_numeric($raw)) {
            return (float)$raw;
        }
        return $raw;
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
        return __('设置系统配置项（写入 env.php）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'system:config:set',
            __('设置 env.php 中的系统配置项，支持点号分隔的嵌套键'),
            [
                '-h, --help' => __('显示帮助信息'),
            ],
            [
                'key'   => __('配置键名，支持点号分隔（如 server.host）'),
                'value' => __('配置值（自动识别 int/float/bool/null/string 类型）'),
            ],
            [
                __('设置服务器监听地址')   => 'php bin/w system:config:set server.host 127.0.0.1',
                __('设置服务器监听端口')   => 'php bin/w system:config:set server.port 9981',
                __('设置 Worker 数量')    => 'php bin/w system:config:set server.worker_count 4',
                __('设置智能模式')        => 'php bin/w system:config:set server.worker_count auto',
                __('设置布尔值')          => 'php bin/w system:config:set event.debug true',
                __('设置顶级配置')        => 'php bin/w system:config:set lang en_US',
            ]
        );
    }
}
