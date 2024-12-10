<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
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
    function p($data = null, $pass = false, int $trace_deep = 1): void
    {
        // 执行时间
        $exe_time = microtime(true) - START_TIME;
        $isCli    = (PHP_SAPI === 'cli');
        if (!$isCli) {
            // 响应500
            http_response_code(500);
        }
        $echo_pre = ($isCli ? PHP_EOL : '<pre>');
        echo $echo_pre;
        $parent_call_info = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $trace_deep);
        $parent_call_info = array_reverse($parent_call_info);
        foreach ($parent_call_info as $key => $item) {
            if (is_array($item)) {
                echo w_var_export($item);
                echo '---------------------------------------------------------' . ($isCli ? PHP_EOL : '<br>');
            } else {
                $key      = str_pad($key, 12, '-', STR_PAD_BOTH);
                $item_str = is_string($item) ? $item : json_encode($item);
                print_r("{$key}");
                echo '---------------------------------------------------------' . ($isCli ? PHP_EOL : '<br>');
            }
        }
        if (is_object($data)) {
            if (method_exists($data, 'toArray')) {
                $subIsObject = 0;
                foreach ($data->toArray() as $item) {
                    if (is_object($item)) {
                        $subIsObject = 1;
                    }
                }
                if (!$subIsObject) {
                    var_dump(get_class($data));
                    echo $isCli ? PHP_EOL : '<br><pre>';
                    var_dump($data->toArray());
                    echo $isCli ? PHP_EOL : '</div><br><div>调试时间：<br>--' . ($exe_time * 1000) . '(ms/毫秒)<br>--' . $exe_time . '(s/秒)<br></div></div></pre>';
                    echo $isCli ? PHP_EOL : '</div>';
                    echo $isCli ? PHP_EOL : '</div>';
                    if (DEV) {
                        echo $isCli ? PHP_EOL : '<b>源数据：</b>';
                        echo $isCli ? PHP_EOL : '<br>';
                        var_dump($data);
                        echo $isCli ? PHP_EOL : '</pre>';
                    }
                    if (!$pass) {
                        die;
                    }
                }
            }
            echo $isCli ? PHP_EOL : '<br><pre>';
            var_dump($data);
            var_dump(get_class($data));
            var_dump(get_class_methods($data));
            echo $isCli ? PHP_EOL : '</div><br><div>调试时间：<br>--' . ($exe_time * 1000) . '(ms/毫秒)<br>--' . $exe_time . '(s/秒)<br></div></div></pre>';
            echo $isCli ? PHP_EOL : '</div></div></pre>';
            if (!$pass) {
                die;
            }
        }

        var_dump($data);
        echo $isCli ? PHP_EOL : '</div><br><div>调试时间：<br>--' . ($exe_time * 1000) . '(ms/毫秒)<br>--' . $exe_time . '(s/秒)<br></div></div></pre>';
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
        p($data, 1,$trace_deep);
    }
}
if (function_exists('dump') && !function_exists('d')) {
    function d($data, $trace_deep = 2): void
    {
        // 执行时间
        $exe_time                 = microtime(true) - START_TIME;
        $parent_call_info         = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $trace_deep);
        $parent_call_info         = array_reverse($parent_call_info);
        $parent_call_info['time'] = $exe_time;
//        if (DEV) {
//            dump($parent_call_info);
//        }
        $parent_call_info = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $trace_deep);
        $parent_call_info = array_reverse($parent_call_info);
        foreach ($parent_call_info as $key => $item) {
            if (is_array($item)) {
                echo w_var_export($item);
                echo '---------------------------------------------------------' . (CLI ? PHP_EOL : '<br>');
            } else {
                $key      = str_pad($key, 12, '-', STR_PAD_BOTH);
                print_r("{$key}");
                echo '---------------------------------------------------------' . (CLI ? PHP_EOL : '<br>');
            }
        }
        dump($data);
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
        $echo_pre = ($isCli ? PHP_EOL : '<pre>');
        echo $echo_pre;
        $parent_call_info = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $parent_call_info = array_reverse($parent_call_info);
        foreach ($parent_call_info as $key => $item) {
            if (is_array($item)) {
                foreach ($item as $k => $i) {
                    $end_line = '';
                    if (isset($item['line']) && 'file' === $k) {
                        $end_line = ':' . $item['line'];
                    }
                    $k     = "【{$k}】";
                    $i_str = is_string($i) ? $i . $end_line : json_encode($i) . $end_line;
                    print_r("{$k} " . $i_str . ($isCli ? PHP_EOL : '<br>'));
                }
                echo '---------------------------------------------------------' . ($isCli ? PHP_EOL : '<br>');
            } else {
                $key      = str_pad($key, 12, '-', STR_PAD_BOTH);
                $item_str = is_string($item) ? $item : json_encode($item);
                print_r("{$key}");
                echo '---------------------------------------------------------' . ($isCli ? PHP_EOL : '<br>');
            }
        }
        echo $exe_time . PHP_EOL;
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
        $echo_pre = ($isCli ? PHP_EOL : '<pre>');
        $break    = ($isCli ? PHP_EOL : '<br>');
        echo $echo_pre;
        $parent_call_info = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $parent_call_info = array_reverse($parent_call_info);
        foreach ($parent_call_info as $key => $item) {
            if (is_array($item)) {
//                foreach ($item as $k => $i) {
//                    $end_line = '';
//                    if (isset($item['line']) && 'file' === $k) {
//                        $end_line = ':' . $item['line'];
//                    }
//                    $k     = "【{$k}】";
//                    $i_str = is_string($i) ? $i . $end_line : json_encode($i) . $end_line;
//                    print_r("{$k} " . $i_str . ($isCli ? PHP_EOL : '<br>'));
//                }
                print_r(w_var_export($item)).$break;
                echo '---------------------------------------------------------' . ($isCli ? PHP_EOL : '<br>');
            } else {
                $key      = str_pad($key, 12, '-', STR_PAD_BOTH);
                $item_str = is_string($item) ? $item : json_encode($item);
                print_r("{$key}");
                echo '---------------------------------------------------------' . ($isCli ? PHP_EOL : '<br>');
            }
        }
        echo __('执行时间:').$exe_time . PHP_EOL;
        echo wdnl($data);
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
        w($data);
    }
}
