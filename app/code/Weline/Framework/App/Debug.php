<?php

namespace Weline\Framework\App;

class Debug
{
    public static function env(string $env_key, mixed $value = null): mixed
    {
        $_ENV['w-debug'][$env_key] = $value;
        return $value;
    }

    public static function target(string $env_key, mixed $value = null): mixed
    {
        # 无值看看是否有键名
        if (!$value) {
            if (!isset($_ENV['w-debug'])) {
                return false;
            }
            if(array_key_exists($env_key, $_ENV['w-debug'])){
                return true;
            }
            return false;
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