<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */


// 定义必要的常量（如果未定义）
if (!defined('START_TIME')) {
    define('START_TIME', microtime(true));
}

if (!defined('BP')) {
    define('BP', dirname(dirname(dirname(dirname(__DIR__)))));
}

if (!defined('DEV')) {
    define('DEV', true);
}

if (!defined('CLI')) {
    define('CLI', PHP_SAPI === 'cli');
}

// 添加颜色支持函数
if (!function_exists('debug_colorize')) {
    /**
     * 为调试输出添加颜色支持
     * @param string $text 文本
     * @param string $type 颜色类型
     * @return string 带颜色的文本
     */
    function debug_colorize(string $text, string $type): string
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
                'class' => "\033[36m",   // 青色
                'function' => "\033[33m", // 黄色
                'highlight' => "\033[1;37m", // 高亮白色
                'muted' => "\033[90m",   // 灰色
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
                'class' => '#00bcd4',    // 青色
                'function' => '#ff9800', // 橙色
                'highlight' => '#ffffff', // 白色
                'muted' => '#757575',   // 灰色
            ];
            
            $color = $colors[$type] ?? $colors['info'];
            return '<span style="color: ' . $color . '; font-weight: bold;">' . $text . '</span>';
        }
    }
}

// 添加调试样式函数
if (!function_exists('debug_get_style')) {
    /**
     * 获取调试输出的CSS样式
     * @param string $type 样式类型
     * @return string CSS样式
     */
    function debug_get_style(string $type = 'default'): string
    {
        $styles = [
            'default' => 'background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%); border: 2px solid #ddd; padding: 20px; margin: 15px; border-radius: 10px; font-family: "Consolas", "Monaco", "Courier New", monospace; box-shadow: 0 4px 6px rgba(0,0,0,0.1);',
            'success' => 'background: linear-gradient(135deg, #e8f5e8 0%, #c8e6c9 100%); border: 2px solid #4caf50; padding: 20px; margin: 15px; border-radius: 10px; font-family: "Consolas", "Monaco", "Courier New", monospace; box-shadow: 0 4px 6px rgba(76,175,80,0.2);',
            'warning' => 'background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%); border: 2px solid #ff9800; padding: 20px; margin: 15px; border-radius: 10px; font-family: "Consolas", "Monaco", "Courier New", monospace; box-shadow: 0 4px 6px rgba(255,152,0,0.2);',
            'error' => 'background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%); border: 2px solid #f44336; padding: 20px; margin: 15px; border-radius: 10px; font-family: "Consolas", "Monaco", "Courier New", monospace; box-shadow: 0 4px 6px rgba(244,67,54,0.2);',
            'info' => 'background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%); border: 2px solid #2196f3; padding: 20px; margin: 15px; border-radius: 10px; font-family: "Consolas", "Monaco", "Courier New", monospace; box-shadow: 0 4px 6px rgba(33,150,243,0.2);',
            'light' => 'background: linear-gradient(135deg, #f0f8ff 0%, #e1f5fe 100%); border: 1px solid #87ceeb; padding: 15px; margin: 10px; border-radius: 8px; font-family: "Consolas", "Monaco", "Courier New", monospace; font-size: 12px; box-shadow: 0 2px 4px rgba(135,206,235,0.1);',
        ];
        
        return $styles[$type] ?? $styles['default'];
    }
}

if (!function_exists('p')) {
    /**
     * @DESC         |打印调试
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param      $data
     * @param bool $pass
     * @param int $trace_deep
     */
    function p($data = null, $pass = false, int $trace_deep = 2): void
    {
        // 执行时间
        $exe_time = microtime(true) - START_TIME;
        $isCli    = (PHP_SAPI === 'cli');
        if (!$isCli) {
            // 响应500
            http_response_code(500);
        }
        
        // 美化输出样式
        $separator = $isCli ? '═' : '═';
        $lineBreak = $isCli ? PHP_EOL : '<br>';
        
        echo ($isCli ? PHP_EOL : '<div style="' . debug_get_style('default') . '">');
        
        // 添加调试标题
        echo debug_colorize('🐛 ' . __('调试输出'), 'highlight') . $lineBreak;
        echo str_repeat($separator, 70) . $lineBreak;
        
        // 限制追踪层数，避免浏览器卡顿
        $trace_deep = min($trace_deep, 3); // 最大限制3层
        $parent_call_info = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $trace_deep);
        $parent_call_info = array_reverse($parent_call_info);
        
        // 添加调用栈标题
        echo debug_colorize('📋 ' . __('调用栈信息'), 'note') . ':' . $lineBreak;
        echo str_repeat($separator, 35) . $lineBreak;
        
        // 简化追踪信息输出，只显示关键信息
        foreach ($parent_call_info as $key => $item) {
            if (is_array($item)) {
                // 只显示关键信息：文件、行号、函数名
                $file = isset($item['file']) ? str_replace(BP, '', $item['file']) : '';
                $line = isset($item['line']) ? $item['line'] : '';
                $function = isset($item['function']) ? $item['function'] : '';
                $class = isset($item['class']) ? $item['class'] : '';
                
                echo debug_colorize('📍 ' . __('文件'), 'file') . ': ' . debug_colorize($file . '(' . $line . ')', 'file') . $lineBreak;
                if ($class) {
                    echo debug_colorize('🏗️ ' . __('类'), 'class') . ': ' . debug_colorize($class . '::' . $function, 'class') . $lineBreak;
                } else {
                    echo debug_colorize('⚙️ ' . __('函数'), 'function') . ': ' . debug_colorize($function, 'function') . $lineBreak;
                }
                echo str_repeat('─', 50) . $lineBreak;
            } else {
                $key      = str_pad($key, 12, '─', STR_PAD_BOTH);
                $item_str = is_string($item) ? $item : json_encode($item);
                print_r("{$key}");
                echo str_repeat('─', 50) . $lineBreak;
            }
        }
        
        // 添加数据输出标题
        echo $lineBreak . debug_colorize('💾 ' . __('数据内容'), 'success') . ':' . $lineBreak;
        echo str_repeat($separator, 35) . $lineBreak;
        
        if (is_object($data)) {
            if (method_exists($data, 'toArray')) {
                $subIsObject = 0;
                foreach ($data->toArray() as $item) {
                    if (is_object($item)) {
                        $subIsObject = 1;
                    }
                }
                if (!$subIsObject) {
                    echo debug_colorize('📊 ' . __('对象类型'), 'info') . ': ' . debug_colorize(get_class($data), 'class') . $lineBreak;
                    echo $isCli ? PHP_EOL : '<br><pre style="background: rgba(255,255,255,0.9); padding: 15px; border-radius: 8px; border: 1px solid #ddd; overflow-x: auto;">';
                    var_dump($data->toArray());
                    echo $isCli ? PHP_EOL : '</pre>';
                    
                    // 添加执行时间信息
                    echo $lineBreak . debug_colorize('⏱️ ' . __('执行时间'), 'warning') . ':' . $lineBreak;
                    echo debug_colorize('   ' . __('毫秒'), 'note') . ': ' . debug_colorize(round($exe_time * 1000, 2) . ' ms', 'success') . $lineBreak;
                    echo debug_colorize('   ' . __('秒'), 'note') . ': ' . debug_colorize(round($exe_time, 4) . ' s', 'success') . $lineBreak;
                    
                    if (DEV) {
                        echo $lineBreak . debug_colorize('🔍 ' . __('源数据'), 'info') . ':' . $lineBreak;
                        echo $isCli ? PHP_EOL : '<br><pre style="background: rgba(255,255,255,0.9); padding: 15px; border-radius: 8px; border: 1px solid #ddd; overflow-x: auto;">';
                        var_dump($data);
                        echo $isCli ? PHP_EOL : '</pre>';
                    }
                    
                    echo str_repeat($separator, 70) . $lineBreak;
                    echo ($isCli ? PHP_EOL : '</div>');
                    
                    if (!$pass) {
                        die;
                    }
                }
            }
            echo debug_colorize('📊 ' . __('对象类型'), 'info') . ': ' . debug_colorize(get_class($data), 'class') . $lineBreak;
            echo $isCli ? PHP_EOL : '<br><pre style="background: rgba(255,255,255,0.9); padding: 15px; border-radius: 8px; border: 1px solid #ddd; overflow-x: auto;">';
            var_dump($data);
            var_dump(get_class_methods($data));
            echo $isCli ? PHP_EOL : '</pre>';
            
            // 添加执行时间信息
            echo $lineBreak . debug_colorize('⏱️ ' . __('执行时间'), 'warning') . ':' . $lineBreak;
            echo debug_colorize('   ' . __('毫秒'), 'note') . ': ' . debug_colorize(round($exe_time * 1000, 2) . ' ms', 'success') . $lineBreak;
            echo debug_colorize('   ' . __('秒'), 'note') . ': ' . debug_colorize(round($exe_time, 4) . ' s', 'success') . $lineBreak;
            
            echo str_repeat($separator, 70) . $lineBreak;
            echo ($isCli ? PHP_EOL : '</div>');
            
            if (!$pass) {
                die;
            }
        }

        // 输出普通数据
        echo $isCli ? PHP_EOL : '<pre style="background: rgba(255,255,255,0.9); padding: 15px; border-radius: 8px; border: 1px solid #ddd; overflow-x: auto;">';
        var_dump($data);
        echo $isCli ? PHP_EOL : '</pre>';
        
        // 添加执行时间信息
        echo $lineBreak . debug_colorize('⏱️ ' . __('执行时间'), 'warning') . ':' . $lineBreak;
        echo debug_colorize('   ' . __('毫秒'), 'note') . ': ' . debug_colorize(round($exe_time * 1000, 2) . ' ms', 'success') . $lineBreak;
        echo debug_colorize('   ' . __('秒'), 'note') . ': ' . debug_colorize(round($exe_time, 4) . ' s', 'success') . $lineBreak;
        
        echo str_repeat($separator, 70) . $lineBreak;
        echo ($isCli ? PHP_EOL : '</div>');
        
        if (!$pass) {
            die;
        }
    }
}
if (!function_exists('pp')) {
    /**
     * 打印并跳过
     *
     * @param $data
     */
    function pp($data, int $trace_deep = 2): void
    {
        // 限制追踪层数，避免浏览器卡顿
        $trace_deep = min($trace_deep, 3); // 最大限制3层
        p($data, 1, $trace_deep);
    }
}
if (function_exists('dump') && !function_exists('d')) {
    function d($data, $trace_deep = 2): void
    {
        // 执行时间
        $exe_time                 = microtime(true) - START_TIME;
        $isCli                    = (PHP_SAPI === 'cli');
        
        // 限制追踪层数，避免浏览器卡顿
        $trace_deep = min($trace_deep, 3); // 最大限制3层
        
        // 美化输出样式
        $separator = $isCli ? '═' : '═';
        $lineBreak = $isCli ? PHP_EOL : '<br>';
        
        echo ($isCli ? PHP_EOL : '<div style="' . debug_get_style('info') . '">');
        
        // 添加调试标题
        echo debug_colorize('🔍 ' . __('调试输出 (d函数)'), 'highlight') . $lineBreak;
        echo str_repeat($separator, 70) . $lineBreak;
        
        $parent_call_info         = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $trace_deep);
        $parent_call_info         = array_reverse($parent_call_info);
        $parent_call_info['time'] = $exe_time;
        
        // 添加调用栈标题
        echo debug_colorize('📋 ' . __('调用栈信息'), 'note') . ':' . $lineBreak;
        echo str_repeat($separator, 35) . $lineBreak;
        
        // 简化追踪信息输出
        foreach ($parent_call_info as $key => $item) {
            if (is_array($item)) {
                // 只显示关键信息：文件、行号、函数名
                $file = isset($item['file']) ? str_replace(BP, '', $item['file']) : '';
                $line = isset($item['line']) ? $item['line'] : '';
                $function = isset($item['function']) ? $item['function'] : '';
                $class = isset($item['class']) ? $item['class'] : '';
                
                echo debug_colorize('📍 ' . __('文件'), 'file') . ': ' . debug_colorize($file . '(' . $line . ')', 'file') . $lineBreak;
                if ($class) {
                    echo debug_colorize('🏗️ ' . __('类'), 'class') . ': ' . debug_colorize($class . '::' . $function, 'class') . $lineBreak;
                } else {
                    echo debug_colorize('⚙️ ' . __('函数'), 'function') . ': ' . debug_colorize($function, 'function') . $lineBreak;
                }
                echo str_repeat('─', 50) . $lineBreak;
            } else {
                $key      = str_pad($key, 12, '─', STR_PAD_BOTH);
                print_r("{$key}");
                echo str_repeat('─', 50) . $lineBreak;
            }
        }
        
        // 添加数据输出标题
        echo $lineBreak . debug_colorize('💾 ' . __('数据内容'), 'success') . ':' . $lineBreak;
        echo str_repeat($separator, 35) . $lineBreak;
        
        // 输出数据
        echo $isCli ? PHP_EOL : '<pre style="background: rgba(255,255,255,0.9); padding: 15px; border-radius: 8px; border: 1px solid #ddd; overflow-x: auto;">';
        dump($data);
        echo $isCli ? PHP_EOL : '</pre>';
        
        // 添加执行时间信息
        echo $lineBreak . debug_colorize('⏱️ ' . __('执行时间'), 'warning') . ':' . $lineBreak;
        echo debug_colorize('   ' . __('毫秒'), 'note') . ': ' . debug_colorize(round($exe_time * 1000, 2) . ' ms', 'success') . $lineBreak;
        echo debug_colorize('   ' . __('秒'), 'note') . ': ' . debug_colorize(round($exe_time, 4) . ' s', 'success') . $lineBreak;
        
        echo str_repeat($separator, 70) . $lineBreak;
        echo ($isCli ? PHP_EOL : '</div>');
    }
}
if (!function_exists('dd')) {
    function dnl($data)
    {
        return d($data) . "<br>\n";
    }

    function dd($data)
    {
        echo dnl($data);
        // 执行时间
        $exe_time = microtime(true) - START_TIME;
        $isCli    = (PHP_SAPI === 'cli');
        
        // 美化输出样式
        $separator = $isCli ? '═' : '═';
        $lineBreak = $isCli ? PHP_EOL : '<br>';
        
        echo ($isCli ? PHP_EOL : '<div style="' . debug_get_style('error') . '">');
        
        // 添加调试标题
        echo debug_colorize('🚨 ' . __('调试输出 (dd函数) - 将终止执行'), 'error') . $lineBreak;
        echo str_repeat($separator, 70) . $lineBreak;
        
        // 限制追踪层数，避免浏览器卡顿
        $parent_call_info = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $parent_call_info = array_reverse($parent_call_info);
        
        // 添加调用栈标题
        echo debug_colorize('📋 ' . __('调用栈信息'), 'note') . ':' . $lineBreak;
        echo str_repeat($separator, 35) . $lineBreak;
        
        // 简化追踪信息输出
        foreach ($parent_call_info as $key => $item) {
            if (is_array($item)) {
                // 只显示关键信息：文件、行号、函数名
                $file = isset($item['file']) ? str_replace(BP, '', $item['file']) : '';
                $line = isset($item['line']) ? $item['line'] : '';
                $function = isset($item['function']) ? $item['function'] : '';
                $class = isset($item['class']) ? $item['class'] : '';
                
                echo debug_colorize('📍 ' . __('文件'), 'file') . ': ' . debug_colorize($file . '(' . $line . ')', 'file') . $lineBreak;
                if ($class) {
                    echo debug_colorize('🏗️ ' . __('类'), 'class') . ': ' . debug_colorize($class . '::' . $function, 'class') . $lineBreak;
                } else {
                    echo debug_colorize('⚙️ ' . __('函数'), 'function') . ': ' . debug_colorize($function, 'function') . $lineBreak;
                }
                echo str_repeat('─', 50) . $lineBreak;
            } else {
                $key      = str_pad($key, 12, '─', STR_PAD_BOTH);
                $item_str = is_string($item) ? $item : json_encode($item);
                print_r("{$key}");
                echo str_repeat('─', 50) . $lineBreak;
            }
        }
        
        // 添加执行时间信息
        echo $lineBreak . debug_colorize('⏱️ ' . __('执行时间'), 'warning') . ':' . $lineBreak;
        echo debug_colorize('   ' . __('毫秒'), 'note') . ': ' . debug_colorize(round($exe_time * 1000, 2) . ' ms', 'success') . $lineBreak;
        echo debug_colorize('   ' . __('秒'), 'note') . ': ' . debug_colorize(round($exe_time, 4) . ' s', 'success') . $lineBreak;
        
        echo str_repeat($separator, 70) . $lineBreak;
        echo debug_colorize('🛑 ' . __('程序已终止'), 'error') . $lineBreak;
        echo ($isCli ? PHP_EOL : '</div>');
        
        exit;
    }

    function ddt($data = '')
    {
        echo '[' . date('Y/m/d H:i:s') . ']' . dnl($data) . "<br>\n";
        exit();
    }
}

if (!function_exists('w')) {
    if (!function_exists('wdnl')) {
        function wdnl($data)
        {
            return d($data) . (CLI?PHP_EOL:'<br>');
        }
    }

    function w($data)
    {
        // 执行时间
        $exe_time = microtime(true) - START_TIME;
        $isCli    = (PHP_SAPI === 'cli');
        
        // 美化输出样式
        $separator = $isCli ? '═' : '═';
        $lineBreak = $isCli ? PHP_EOL : '<br>';
        
        echo ($isCli ? PHP_EOL : '<div style="' . debug_get_style('success') . '">');
        
        // 添加调试标题
        echo debug_colorize('🌿 ' . __('调试输出 (w函数)'), 'success') . $lineBreak;
        echo str_repeat($separator, 70) . $lineBreak;
        
        // 限制追踪层数，避免浏览器卡顿
        $parent_call_info = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $parent_call_info = array_reverse($parent_call_info);
        
        // 添加调用栈标题
        echo debug_colorize('📋 ' . __('调用栈信息'), 'note') . ':' . $lineBreak;
        echo str_repeat($separator, 35) . $lineBreak;
        
        // 简化追踪信息输出
        foreach ($parent_call_info as $key => $item) {
            if (is_array($item)) {
                // 只显示关键信息：文件、行号、函数名
                $file = isset($item['file']) ? str_replace(BP, '', $item['file']) : '';
                $line = isset($item['line']) ? $item['line'] : '';
                $function = isset($item['function']) ? $item['function'] : '';
                $class = isset($item['class']) ? $item['class'] : '';
                
                echo debug_colorize('📍 ' . __('文件'), 'file') . ': ' . debug_colorize($file . '(' . $line . ')', 'file') . $lineBreak;
                if ($class) {
                    echo debug_colorize('🏗️ ' . __('类'), 'class') . ': ' . debug_colorize($class . '::' . $function, 'class') . $lineBreak;
                } else {
                    echo debug_colorize('⚙️ ' . __('函数'), 'function') . ': ' . debug_colorize($function, 'function') . $lineBreak;
                }
                echo str_repeat('─', 50) . $lineBreak;
            } else {
                $key      = str_pad($key, 12, '─', STR_PAD_BOTH);
                $item_str = is_string($item) ? $item : json_encode($item);
                print_r("{$key}");
                echo str_repeat('─', 50) . $lineBreak;
            }
        }
        
        // 添加执行时间信息
        echo $lineBreak . debug_colorize('⏱️ ' . __('执行时间'), 'warning') . ':' . $lineBreak;
        echo debug_colorize('   ' . __('毫秒'), 'note') . ': ' . debug_colorize(round($exe_time * 1000, 2) . ' ms', 'success') . $lineBreak;
        echo debug_colorize('   ' . __('秒'), 'note') . ': ' . debug_colorize(round($exe_time, 4) . ' s', 'success') . $lineBreak;
        
        // 添加数据输出标题
        echo $lineBreak . debug_colorize('💾 ' . __('数据内容'), 'success') . ':' . $lineBreak;
        echo str_repeat($separator, 35) . $lineBreak;
        
        // 输出数据
        echo $isCli ? PHP_EOL : '<pre style="background: rgba(255,255,255,0.9); padding: 15px; border-radius: 8px; border: 1px solid #ddd; overflow-x: auto;">';
        echo wdnl($data);
        echo $isCli ? PHP_EOL : '</pre>';
        
        echo str_repeat($separator, 70) . $lineBreak;
        echo debug_colorize('🛑 ' . __('程序已终止'), 'error') . $lineBreak;
        echo ($isCli ? PHP_EOL : '</div>');
        
        exit;
    }

    function wt($data = '')
    {
        echo '[' . date('Y/m/d H:i:s') . ']' . wdnl($data) . "<br>\n";
        exit();
    }
}

if (!function_exists('cli_d')) {
    function cli_d($data)
    {
        if(!CLI) {
            return;
        }
        $exe_time = microtime(true) - START_TIME;
        $parent_call_info = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $parent_call_info = array_reverse($parent_call_info);
        $parent_call_info['time'] = $exe_time;
        foreach ($parent_call_info as $key => $item) {
            if (is_array($item)) {
                echo w_var_export($item);
                echo '═════════════════════════════════════════════════════════════' . PHP_EOL;
            } else {
                $key      = str_pad($key, 12, '═', STR_PAD_BOTH);
                print_r("{$key}");
                echo '═════════════════════════════════════════════════════════════' . PHP_EOL;
            }
        }
        var_dump($data);
    }
}

// 新增轻量级调试函数，避免浏览器卡顿
if (!function_exists('p_light')) {
    /**
     * 轻量级调试函数，只显示最基本的调用信息，避免浏览器卡顿
     * 
     * @param mixed $data 要调试的数据
     * @param bool $pass 是否跳过终止
     * @param int $trace_deep 追踪层数，默认1层
     */
    function p_light($data = null, $pass = false, int $trace_deep = 1): void
    {
        $isCli = (PHP_SAPI === 'cli');
        if (!$isCli) {
            http_response_code(500);
        }
        
        // 美化输出样式
        $separator = $isCli ? '═' : '═';
        $lineBreak = $isCli ? PHP_EOL : '<br>';
        
        echo ($isCli ? PHP_EOL : '<div style="' . debug_get_style('light') . '">');
        
        // 添加调试标题
        echo debug_colorize('⚡ ' . __('轻量级调试输出'), 'info') . $lineBreak;
        echo str_repeat($separator, 50) . $lineBreak;
        
        // 严格限制追踪层数，避免性能问题
        $trace_deep = min($trace_deep, 2); // 最大限制2层
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $trace_deep);
        
        // 只显示当前调用位置
        if (isset($backtrace[0])) {
            $file = str_replace(BP, '', $backtrace[0]['file']);
            $line = $backtrace[0]['line'];
            $function = $backtrace[0]['function'];
            $class = $backtrace[0]['class'] ?? '';
            
            echo debug_colorize('📍 ' . __('调用位置'), 'file') . ': ' . debug_colorize($file . '(' . $line . ')', 'file') . $lineBreak;
            if ($class) {
                echo debug_colorize('🏗️ ' . __('调用方法'), 'class') . ': ' . debug_colorize($class . '::' . $function, 'class') . $lineBreak;
            } else {
                echo debug_colorize('⚙️ ' . __('调用函数'), 'function') . ': ' . debug_colorize($function, 'function') . $lineBreak;
            }
            echo str_repeat('─', 40) . $lineBreak;
        }
        
        // 添加数据输出标题
        echo debug_colorize('💾 ' . __('数据内容'), 'success') . ':' . $lineBreak;
        echo str_repeat($separator, 25) . $lineBreak;
        
        // 输出数据
        echo $isCli ? PHP_EOL : '<pre style="background: rgba(255,255,255,0.9); padding: 10px; border-radius: 6px; border: 1px solid #ddd; overflow-x: auto; font-size: 11px;">';
        if (is_object($data) && method_exists($data, 'toArray')) {
            var_dump($data->toArray());
        } else {
            var_dump($data);
        }
        echo $isCli ? PHP_EOL : '</pre>';
        
        echo str_repeat($separator, 50) . $lineBreak;
        echo ($isCli ? PHP_EOL : '</div>');
        
        if (!$pass) {
            die;
        }
    }
}

// 新增快速调试函数，无追踪信息
if (!function_exists('p_fast')) {
    /**
     * 快速调试函数，不显示追踪信息，只输出数据
     * 
     * @param mixed $data 要调试的数据
     * @param bool $pass 是否跳过终止
     */
    function p_fast($data = null, $pass = false): void
    {
        $isCli = (PHP_SAPI === 'cli');
        if (!$isCli) {
            http_response_code(500);
        }
        
        echo ($isCli ? PHP_EOL : '<div style="background: #f8f9fa; border: 1px solid #dee2e6; padding: 10px; margin: 5px; border-radius: 5px; font-family: monospace;">');
        echo debug_colorize('🚀 ' . __('快速调试输出'), 'info') . ($isCli ? PHP_EOL : '<br>');
        echo '═════════════════════════════════════════════════════════════' . ($isCli ? PHP_EOL : '<br>');
        
        if (is_object($data) && method_exists($data, 'toArray')) {
            var_dump($data->toArray());
        } else {
            var_dump($data);
        }
        
        echo ($isCli ? PHP_EOL : '</div>');
        
        if (!$pass) {
            die;
        }
    }
}

// 新增彩色调试函数
if (!function_exists('p_color')) {
    /**
     * 彩色调试函数，使用不同的颜色主题
     * 
     * @param mixed $data 要调试的数据
     * @param string $theme 主题颜色 (default, success, warning, error, info)
     * @param bool $pass 是否跳过终止
     */
    function p_color($data = null, string $theme = 'default', bool $pass = false): void
    {
        $isCli = (PHP_SAPI === 'cli');
        if (!$isCli) {
            http_response_code(500);
        }
        
        $themes = [
            'default' => ['icon' => '🎨', 'color' => 'info'],
            'success' => ['icon' => '✅', 'color' => 'success'],
            'warning' => ['icon' => '⚠️', 'color' => 'warning'],
            'error' => ['icon' => '❌', 'color' => 'error'],
            'info' => ['icon' => 'ℹ️', 'color' => 'note'],
        ];
        
        $theme_config = $themes[$theme] ?? $themes['default'];
        
        echo ($isCli ? PHP_EOL : '<div style="' . debug_get_style($theme) . '">');
        echo debug_colorize($theme_config['icon'] . ' ' . __('彩色调试输出') . ' (' . $theme . ')', $theme_config['color']) . ($isCli ? PHP_EOL : '<br>');
        echo '═════════════════════════════════════════════════════════════' . ($isCli ? PHP_EOL : '<br>');
        
        echo $isCli ? PHP_EOL : '<pre style="background: rgba(255,255,255,0.9); padding: 15px; border-radius: 8px; border: 1px solid #ddd; overflow-x: auto;">';
        var_dump($data);
        echo $isCli ? PHP_EOL : '</pre>';
        
        echo ($isCli ? PHP_EOL : '</div>');
        
        if (!$pass) {
            die;
        }
    }
}

// 新增表格调试函数
if (!function_exists('p_table')) {
    /**
     * 表格调试函数，以表格形式显示数组数据
     * 
     * @param array $data 要调试的数组数据
     * @param bool $pass 是否跳过终止
     */
    function p_table(array $data = [], bool $pass = false): void
    {
        $isCli = (PHP_SAPI === 'cli');
        if (!$isCli) {
            http_response_code(500);
        }
        
        echo ($isCli ? PHP_EOL : '<div style="' . debug_get_style('info') . '">');
        echo debug_colorize('📊 ' . __('表格调试输出'), 'info') . ($isCli ? PHP_EOL : '<br>');
        echo '═════════════════════════════════════════════════════════════' . ($isCli ? PHP_EOL : '<br>');
        
        if (empty($data)) {
            echo debug_colorize('📭 ' . __('数据为空'), 'warning') . ($isCli ? PHP_EOL : '<br>');
        } else {
            if ($isCli) {
                // CLI环境下的表格输出
                $headers = array_keys(reset($data));
                $maxLengths = [];
                
                // 计算每列的最大长度
                foreach ($headers as $header) {
                    $maxLengths[$header] = strlen($header);
                }
                
                foreach ($data as $row) {
                    foreach ($headers as $header) {
                        $value = is_array($row[$header] ?? '') ? json_encode($row[$header]) : (string)($row[$header] ?? '');
                        $maxLengths[$header] = max($maxLengths[$header], strlen($value));
                    }
                }
                
                // 输出表头
                echo '┌';
                foreach ($headers as $header) {
                    echo str_repeat('─', $maxLengths[$header] + 2) . '┬';
                }
                echo PHP_EOL;
                
                echo '│';
                foreach ($headers as $header) {
                    echo ' ' . str_pad($header, $maxLengths[$header]) . ' │';
                }
                echo PHP_EOL;
                
                echo '├';
                foreach ($headers as $header) {
                    echo str_repeat('─', $maxLengths[$header] + 2) . '┼';
                }
                echo PHP_EOL;
                
                // 输出数据行
                foreach ($data as $row) {
                    echo '│';
                    foreach ($headers as $header) {
                        $value = is_array($row[$header] ?? '') ? json_encode($row[$header]) : (string)($row[$header] ?? '');
                        echo ' ' . str_pad($value, $maxLengths[$header]) . ' │';
                    }
                    echo PHP_EOL;
                }
                
                echo '└';
                foreach ($headers as $header) {
                    echo str_repeat('─', $maxLengths[$header] + 2) . '┴';
                }
                echo PHP_EOL;
            } else {
                // Web环境下的表格输出
                echo '<table style="width: 100%; border-collapse: collapse; background: rgba(255,255,255,0.9); border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
                
                $headers = array_keys(reset($data));
                
                // 表头
                echo '<thead><tr style="background: linear-gradient(135deg, #2196f3, #1976d2); color: white;">';
                foreach ($headers as $header) {
                    echo '<th style="padding: 12px; text-align: left; border-bottom: 2px solid #1976d2; font-weight: bold;">' . htmlspecialchars($header) . '</th>';
                }
                echo '</tr></thead>';
                
                // 数据行
                echo '<tbody>';
                foreach ($data as $index => $row) {
                    $bgColor = $index % 2 === 0 ? 'rgba(255,255,255,0.9)' : 'rgba(240,248,255,0.9)';
                    echo '<tr style="background: ' . $bgColor . ';">';
                    foreach ($headers as $header) {
                        $value = $row[$header] ?? '';
                        if (is_array($value)) {
                            $value = '<pre style="margin: 0; font-size: 11px;">' . json_encode($value, JSON_PRETTY_PRINT) . '</pre>';
                        } else {
                            $value = htmlspecialchars((string)$value);
                        }
                        echo '<td style="padding: 10px; border-bottom: 1px solid #e0e0e0; word-break: break-word;">' . $value . '</td>';
                    }
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }
        }
        
        echo ($isCli ? PHP_EOL : '</div>');
        
        if (!$pass) {
            die;
        }
    }
}

// 新增JSON美化调试函数
if (!function_exists('p_json')) {
    /**
     * JSON美化调试函数，格式化输出JSON数据
     * 
     * @param mixed $data 要调试的数据
     * @param bool $pass 是否跳过终止
     */
    function p_json($data = null, bool $pass = false): void
    {
        $isCli = (PHP_SAPI === 'cli');
        if (!$isCli) {
            http_response_code(500);
        }
        
        echo ($isCli ? PHP_EOL : '<div style="' . debug_get_style('default') . '">');
        echo debug_colorize('📄 ' . __('JSON调试输出'), 'info') . ($isCli ? PHP_EOL : '<br>');
        echo '═════════════════════════════════════════════════════════════' . ($isCli ? PHP_EOL : '<br>');
        
        $jsonData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        if ($jsonData === false) {
            echo debug_colorize('❌ ' . __('JSON编码失败'), 'error') . ($isCli ? PHP_EOL : '<br>');
            echo debug_colorize('错误信息: ' . json_last_error_msg(), 'error') . ($isCli ? PHP_EOL : '<br>');
        } else {
            if ($isCli) {
                echo $jsonData . PHP_EOL;
            } else {
                echo '<pre style="background: rgba(255,255,255,0.9); padding: 15px; border-radius: 8px; border: 1px solid #ddd; overflow-x: auto; font-family: \'Consolas\', \'Monaco\', monospace; line-height: 1.4;">';
                echo htmlspecialchars($jsonData);
                echo '</pre>';
            }
        }
        
        echo ($isCli ? PHP_EOL : '</div>');
        
        if (!$pass) {
            die;
        }
    }
}

// 新增性能监控调试函数
if (!function_exists('p_perf')) {
    /**
     * 性能监控调试函数，显示内存使用和执行时间
     * 
     * @param string $label 性能标签
     * @param bool $pass 是否跳过终止
     */
    function p_perf(string $label = '性能监控', bool $pass = false): void
    {
        $isCli = (PHP_SAPI === 'cli');
        if (!$isCli) {
            http_response_code(500);
        }
        
        static $startTime = null;
        static $startMemory = null;
        
        if ($startTime === null) {
            $startTime = microtime(true);
            $startMemory = memory_get_usage();
        }
        
        $currentTime = microtime(true);
        $currentMemory = memory_get_usage();
        $peakMemory = memory_get_peak_usage();
        
        $executionTime = $currentTime - $startTime;
        $memoryUsed = $currentMemory - $startMemory;
        
        echo ($isCli ? PHP_EOL : '<div style="' . debug_get_style('warning') . '">');
        echo debug_colorize('⚡ ' . __('性能监控') . ' - ' . $label, 'warning') . ($isCli ? PHP_EOL : '<br>');
        echo '═════════════════════════════════════════════════════════════' . ($isCli ? PHP_EOL : '<br>');
        
        $metrics = [
            '执行时间' => round($executionTime * 1000, 2) . ' ms',
            '内存使用' => round($memoryUsed / 1024 / 1024, 2) . ' MB',
            '峰值内存' => round($peakMemory / 1024 / 1024, 2) . ' MB',
            '当前内存' => round($currentMemory / 1024 / 1024, 2) . ' MB',
        ];
        
        if ($isCli) {
            foreach ($metrics as $key => $value) {
                echo debug_colorize('📊 ' . $key . ': ', 'note') . debug_colorize($value, 'success') . PHP_EOL;
            }
        } else {
            echo '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin: 10px 0;">';
            foreach ($metrics as $key => $value) {
                echo '<div style="background: rgba(255,255,255,0.9); padding: 10px; border-radius: 6px; text-align: center; border: 1px solid #ddd;">';
                echo '<div style="font-weight: bold; color: #2196f3; margin-bottom: 5px;">' . $key . '</div>';
                echo '<div style="font-size: 18px; color: #4caf50; font-weight: bold;">' . $value . '</div>';
                echo '</div>';
            }
            echo '</div>';
        }
        
        echo ($isCli ? PHP_EOL : '</div>');
        
        if (!$pass) {
            die;
        }
    }
}

// 新增SQL调试函数
if (!function_exists('p_sql')) {
    /**
     * SQL调试函数，美化显示SQL查询
     * 
     * @param string $sql SQL查询语句
     * @param array $params 查询参数
     * @param bool $pass 是否跳过终止
     */
    function p_sql(string $sql, array $params = [], bool $pass = false): void
    {
        $isCli = (PHP_SAPI === 'cli');
        if (!$isCli) {
            http_response_code(500);
        }
        
        echo ($isCli ? PHP_EOL : '<div style="' . debug_get_style('info') . '">');
        echo debug_colorize('🗄️ ' . __('SQL调试输出'), 'info') . ($isCli ? PHP_EOL : '<br>');
        echo '═════════════════════════════════════════════════════════════' . ($isCli ? PHP_EOL : '<br>');
        
        // 美化SQL语句
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        $sql = preg_replace('/\s*(SELECT|FROM|WHERE|AND|OR|ORDER BY|GROUP BY|HAVING|LIMIT|OFFSET|INSERT INTO|UPDATE|DELETE FROM|SET|VALUES|JOIN|LEFT JOIN|RIGHT JOIN|INNER JOIN|OUTER JOIN)\s+/i', "\n$1 ", $sql);
        
        if ($isCli) {
            echo debug_colorize('📝 SQL语句:', 'note') . PHP_EOL;
            echo debug_colorize($sql, 'file') . PHP_EOL . PHP_EOL;
            
            if (!empty($params)) {
                echo debug_colorize('🔢 参数:', 'note') . PHP_EOL;
                foreach ($params as $key => $value) {
                    echo debug_colorize('  ' . $key . ': ', 'function') . debug_colorize($value, 'success') . PHP_EOL;
                }
            }
        } else {
            echo '<div style="background: rgba(255,255,255,0.9); padding: 15px; border-radius: 8px; border: 1px solid #ddd; margin: 10px 0;">';
            echo '<div style="font-weight: bold; color: #2196f3; margin-bottom: 10px;">📝 SQL语句:</div>';
            echo '<pre style="background: #f8f9fa; padding: 10px; border-radius: 4px; border-left: 4px solid #2196f3; margin: 0; font-family: \'Consolas\', \'Monaco\', monospace; line-height: 1.4;">';
            echo htmlspecialchars($sql);
            echo '</pre>';
            
            if (!empty($params)) {
                echo '<div style="font-weight: bold; color: #2196f3; margin: 15px 0 10px 0;">🔢 参数:</div>';
                echo '<div style="background: #f8f9fa; padding: 10px; border-radius: 4px; border-left: 4px solid #4caf50;">';
                foreach ($params as $key => $value) {
                    echo '<div style="margin: 5px 0;"><strong>' . htmlspecialchars($key) . ':</strong> <span style="color: #4caf50;">' . htmlspecialchars((string)$value) . '</span></div>';
                }
                echo '</div>';
            }
            echo '</div>';
        }
        
        echo ($isCli ? PHP_EOL : '</div>');
        
        if (!$pass) {
            die;
        }
    }
}

// 新增调试日志函数
if (!function_exists('p_log')) {
    /**
     * 调试日志函数，将调试信息写入日志文件
     * 
     * @param mixed $data 要记录的数据
     * @param string $level 日志级别 (debug, info, warning, error)
     * @param string|null $file 日志文件路径
     */
    function p_log($data, string $level = 'debug', ?string $file = null): void
    {
        if ($file === null) {
            $file = BP . '/var/log/debug.log';
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $level = strtoupper($level);
        
        $logEntry = "[{$timestamp}] [{$level}] ";
        
        if (is_string($data)) {
            $logEntry .= $data;
        } else {
            $logEntry .= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        
        $logEntry .= PHP_EOL;
        
        // 确保日志目录存在
        $logDir = dirname($file);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        file_put_contents($file, $logEntry, FILE_APPEND | LOCK_EX);
    }
}
