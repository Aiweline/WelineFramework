<?php

namespace Weline\Framework\App;

class Debug
{
    public static function env(string $env_key, mixed $value = ''): mixed
    {
        # 有值设置值
        if ($value) {
            $_ENV['w-debug'][$env_key] = $value;
            return true;
        } else {
            # 无值获取环境值
            return $_ENV['w-debug'][$env_key] ?? null;
        }
    }

    public static function target(string $env_key, mixed $value = ''): mixed
    {
        # 无值看看是否有键名
        if (!$value) {
            return (bool)($_ENV['w-debug'][$env_key] ?? false);
        }
        # 有值看看值是否相等
        $env_value = $_ENV['w-debug'][$env_key] ?? null;
        if ($env_value === $value) {
            return true;
        } else {
            return false;
        }
    }
}