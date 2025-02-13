<?php

namespace Weline\Framework\App;

use Weline\Framework\Manager\ObjectManager;

class Debug
{
    public static function env(string $env_key, bool $target_stop = true, mixed $value = null): mixed
    {
        if (!$value) {
            # 获取上级调用文件和行数
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $file = $backtrace[0]['file'];
            $line = $backtrace[0]['line'];
            $value = __('调试位置：') . "{$file}({$line})";
        }
        $_ENV['w-debug'][$env_key] = $value;
        $_ENV['w-debug'][$env_key . '_target_stop'] = $target_stop;
        return $value;
    }

    public static function target(string $env_key, mixed $value = null): mixed
    {
        if ($value) {
            # 获取触发位置
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $file = $backtrace[0]['file'];
            $line = $backtrace[0]['line'];
            # 调用者位置
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
            $call_file = $backtrace[1]['file'];
            $call_line = $backtrace[1]['line'];

            $printerClass = 'Weline\Framework\Output\\' . (CLI ? 'Cli' : 'Debug') . '\Printing';
            /**@var \Weline\Framework\Output\Debug\Printing $printer */
            $printer = ObjectManager::getInstance($printerClass);
            $printer->printing($_ENV['w-debug'][$env_key] . PHP_EOL . __('触发位置：') . "{$file}({$line})" . PHP_EOL . __('调用者位置：') . "{$call_file}({$call_line})");
            if (is_string($value)) {
                $printer->printing($value);
            } else {
                $printer->printing(w_var_export($value, true));
            }
            if (isset($_ENV['w-debug'][$env_key . '_target_stop']) && $_ENV['w-debug'][$env_key . '_target_stop']) {
                exit();
            }
        }
        # 无值看看是否有键名
        if (!$value) {
            if (!isset($_ENV['w-debug'])) {
                return false;
            }
            if (array_key_exists($env_key, $_ENV['w-debug'])) {
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

    public static function log(mixed $content = '', bool $append = true): bool
    {
        $log = Env::VAR_DIR . '/log/' . Env::log_path_DEBUG . '.log';
        if (!is_dir(dirname($log))) {
            mkdir(dirname($log), 0777, true);
        }
        if (!is_string($content)) {
            $content = w_var_export($content, true);
        }
        $content .= date('Y-m-d H:i:s') . PHP_EOL;
        $content .= PHP_EOL;
        file_put_contents($log, $content, $append ? FILE_APPEND : 0);
        return true;
    }
}