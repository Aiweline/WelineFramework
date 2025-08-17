<?php

namespace Weline\Framework\App;

use Weline\Framework\Console\Cli;
use Weline\Framework\Manager\ObjectManager;

class Debug
{
    /**
     * 是否已注入过Web调试浮窗，避免重复注入
     */
    private static bool $panelInjected = false;

    public static function env(string $env_key, bool $target_stop = true, mixed $value = null): mixed
    {
        if (isset($_ENV['w-debug'][$env_key])) {
            return $_ENV['w-debug'][$env_key];
        }
        if (!$value) {
            # 获取上级调用文件和行数，限制追踪层数
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $file = str_replace(BP, '', $backtrace[0]['file']);
            $line = $backtrace[0]['line'];
            $value = __('调试位置：') . "{$file}({$line})";
        }
        $_ENV['w-debug'][$env_key] = $value;
        $_ENV['w-debug'][$env_key . '_target_stop'] = $target_stop;
        return $value;
    }

    public static function target(string $env_key, mixed $value = 'debug::skip'): mixed
    {
        if (!isset($_ENV['w-debug']) || !isset($_ENV['w-debug'][$env_key])) {
            return false;
        }
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }
        if ($value !== 'debug::skip') {
            # 获取触发位置，限制追踪层数
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $file = str_replace(BP, '', $backtrace[0]['file']);
            $line = $backtrace[0]['line'];
            # 调用者位置，限制追踪层数
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $call_file = str_replace(BP, '', $backtrace[1]['file']);
            $call_line = $backtrace[1]['line'];

            $printerClass = 'Weline\Framework\Output\\' . (CLI ? 'Cli' : 'Debug') . '\Printing';
            /**@var \Weline\Framework\Output\Debug\Printing $printer */
            $printer = ObjectManager::getInstance($printerClass);
            
            // 美化输出格式
            $debugInfo = self::formatDebugInfo($_ENV['w-debug'][$env_key], $file, $line, $call_file, $call_line);
            $printer->printing($debugInfo);
            
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
        if('debug::skip' === $value){
            $value = null;
        }
        if (!$value) {
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
        if ($append) {
            file_put_contents($log, $content, FILE_APPEND);
        } else {
            file_put_contents($log, $content);
        }
        // CLI模式下直接输出，Web模式下输出JS悬浮窗
        if (PHP_SAPI === 'cli') {
            // 终端直接输出内容
            echo $content . PHP_EOL;
        } else {
            // Web模式输出debug悬浮窗
            $html = '<script>
                (function(){
                    var debug = document.createElement("div");
                    debug.style.position = "fixed";
                    debug.style.top = "0";
                    debug.style.right = "0";
                    debug.style.width = "300px";
                    debug.style.height = "100%";
                    debug.style.backgroundColor = "#f5f5f5";
                    debug.style.zIndex = "9999";
                    debug.style.overflow = "auto";
                    debug.style.fontSize = "12px";
                    debug.style.fontFamily = "monospace";
                    debug.style.borderLeft = "1px solid #ccc";
                    debug.style.boxShadow = "-2px 0 8px rgba(0,0,0,0.08)";
                    debug.innerHTML = "<pre style=\"margin:0;padding:10px;\">" + ' . json_encode(str_replace("\n", '<br>', $content)) . ' + "</pre>";
                    document.body.appendChild(debug);
                })();
                </script>';
            echo $html;
        }
        return true;
    }

    /**
     * 格式化调试信息，美化输出
     * @param string $debugValue 调试值
     * @param string $file 文件路径
     * @param int $line 行号
     * @param string $callFile 调用文件
     * @param int $callLine 调用行号
     * @return string 格式化后的调试信息
     */
    private static function formatDebugInfo(string $debugValue, string $file, int $line, string $callFile, int $callLine): string
    {
        $isCli = (PHP_SAPI === 'cli');
        $separator = $isCli ? '=' : '═';
        $lineBreak = $isCli ? PHP_EOL : '<br>';
        
        $formatted = '';
        
        // 添加调试标题
        $formatted .= self::colorize('🔍 ' . __('调试信息'), 'info') . $lineBreak;
        $formatted .= str_repeat($separator, 50) . $lineBreak;
        
        // 调试位置信息
        $formatted .= self::colorize('📍 ' . __('调试位置'), 'note') . ': ' . self::colorize($file . '(' . $line . ')', 'file') . $lineBreak;
        $formatted .= self::colorize('📞 ' . __('调用位置'), 'note') . ': ' . self::colorize($callFile . '(' . $callLine . ')', 'file') . $lineBreak;
        
        // 调试值
        $formatted .= $lineBreak . self::colorize('💡 ' . __('调试内容'), 'success') . ':' . $lineBreak;
        $formatted .= str_repeat($separator, 30) . $lineBreak;
        $formatted .= $debugValue . $lineBreak;
        $formatted .= str_repeat($separator, 50) . $lineBreak;
        
        return $formatted;
    }
    
    /**
     * 添加颜色支持
     * @param string $text 文本
     * @param string $type 颜色类型
     * @return string 带颜色的文本
     */
    private static function colorize(string $text, string $type): string
    {
        $isCli = (PHP_SAPI === 'cli');
        
        if ($isCli) {
            // CLI环境使用ANSI颜色
            $colors = [
                'info' => "\033[36m",    // 青色
                'note' => "\033[34m",    // 蓝色
                'success' => "\033[32m", // 绿色
                'warning' => "\033[33m", // 黄色
                'error' => "\033[31m",   // 红色
                'file' => "\033[35m",    // 紫色
            ];
            $reset = "\033[0m";
            
            $color = $colors[$type] ?? $colors['info'];
            return $color . $text . $reset;
        } else {
            // Web环境使用HTML颜色
            $colors = [
                'info' => '#00bcd4',     // 青色
                'note' => '#2196f3',     // 蓝色
                'success' => '#4caf50',  // 绿色
                'warning' => '#ff9800',  // 橙色
                'error' => '#f44336',    // 红色
                'file' => '#9c27b0',     // 紫色
            ];
            
            $color = $colors[$type] ?? $colors['info'];
            return '<span style="color: ' . $color . '; font-weight: bold;">' . $text . '</span>';
        }
    }
}