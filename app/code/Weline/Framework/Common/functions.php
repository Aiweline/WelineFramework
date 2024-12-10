<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

/**
 *  * 文件信息
 * 作者：邹万才
 * 网名：秋枫雁飞(可以百度看看)
 * 网站：www.aiweline.com/bbs.aiweline.com
 * 工具：PhpStorm
 * 日期：2020/11/30
 * 时间：20:47
 * 描述：此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
 * @DESC         |获取字符串之间的内容
 *
 * 参数区：
 *
 * @param string $str
 * @param        $startDelimiter
 * @param        $endDelimiter
 *
 * @return array
 */

use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Cache\ViewCache;

if (!function_exists('getStringBetweenContents')) {
    function getStringBetweenContents(string $str, string $startDelimiter, string $endDelimiter): array
    {
        $contents             = [];
        $startDelimiterLength = strlen($startDelimiter);
        $endDelimiterLength   = strlen($endDelimiter);
        $startFrom            = $contentStart = $contentEnd = 0;
        while (false !== ($contentStart = strpos($str, $startDelimiter, $startFrom))) {
            $contentStart += $startDelimiterLength;
            $contentEnd   = strpos($str, $endDelimiter, $contentStart);
            if (false === $contentEnd) {
                break;
            }
            $contents[] = substr($str, $contentStart, $contentEnd - $contentStart);
            $startFrom  = $contentEnd + $endDelimiterLength;
        }

        return $contents;
    }
}
if (!function_exists('__')) {
    /**
     * @DESC          # 翻译
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/16 22:48
     * 参数区：
     *
     * @param string            $words
     * @param array|string|null $args
     *
     * @return string
     */
    function __(string $words, array|string|int $args = null): string
    {
        return \Weline\Framework\Phrase\Parser::parse($words, $args);
    }
}
if (!function_exists('w_split_by_capital')) {
    /**
     * @DESC          | 以大写字母分割字符串
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/16 21:13
     * 参数区：
     *
     * @param string $str
     *
     * @return array|bool
     */
    function w_split_by_capital(string $str): array|bool
    {
        return preg_split('/(?=[A-Z])/', $str);
    }
}
if (!function_exists('w_var_export')) {
    /**
     * @DESC          # 打印变量
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/5/13 19:57
     * 参数区：
     *
     * @param      $expression
     * @param bool $return
     *
     * @return string|void
     */
    function w_var_export($expression, bool $return = false)
    {
        $export = var_export($expression, true);
        $export = preg_replace('/^([ ]*)(.*)/m', '$1$1$2', $export);
        $array  = preg_split("/\r\n|\n|\r/", $export);
        $array  = preg_replace(['/\s*array\s\($/', '/\)(,)?$/', '/\s=>\s$/'], [null, ']$1', ' => ['], $array);
        $export = join(PHP_EOL, array_filter(['['] + $array));
        if ($return) {
            return $export;
        }
        echo $export;
    }
}
if (!function_exists('core_var_export')) {
    /**
     * @DESC          # 打印变量
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/5/13 19:57
     * 参数区：
     *
     * @param      $var
     * @param bool $is_str
     *
     * @return string|void
     */
    function core_var_export($var, bool $is_str = false)
    {
        $rtn = preg_replace(array('/Array\s+\(/', '/\[(\d+)\] => (.*)\n/', '/\[([^\d].*)\] => (.*)\n/'), array('array (', '\1 => \'\2\'' . "\n", '\'\1\' => \'\2\'' . "\n"), substr(print_r($var, true), 0, -1));
        $rtn = strtr($rtn, array("=> 'array ('" => '=> array ('));
        $rtn = strtr($rtn, array(")\n\n" => ")\n"));
        $rtn = strtr($rtn, array("'\n" => "',\n", ")\n" => "),\n"));
        $rtn = preg_replace(array('/\n +/e'), array('strtr(\'\0\', array(\'    \'=>\'  \'))'), $rtn);
        $rtn = strtr($rtn, array(" Object'," => " Object'<-"));
        if ($is_str) {
            return $rtn;
        } else {
            echo $rtn;
        }
    }
}

if (!function_exists('framework_view_process_block')) {
    /**
     * @DESC          # 处理框架视图中的block
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/6/11 12:40
     * 参数区：
     *
     * @param array $data
     *
     * @return string
     * @throws ReflectionException
     * @throws \Weline\Framework\App\Exception
     */
    function framework_view_process_block(array $data, $vars = []): string
    {
        if (!isset($data['class'])) {
            $data['class'] = $data[0] ?? '';
            if (!$data['class']) {
                throw new \Weline\Framework\App\Exception(__('framework.view.block.class_not_found %1', $data['class']));
            }
        }

        $block_class = str_replace(' ', '', trim($data['class']));
        # 处理参数
        $params = [];
        foreach ($data as $key => $param) {
            if (is_string($key)) {
                $params[$key] = $param;
            } else {
                $param = explode('=', $param);
                if (isset($param[1])) {
                    $params[$param[0]] = $param[1];
                }
            }
        }
        $params['vars'] = $vars;
        if (isset($params['cache']) && $cache_time = intval($params['cache'])) {
            /**@var CacheInterface $cache */
            $cache = ObjectManager::getInstance(ViewCache::class)->create();
            /**@var Request $request */
            $request   = ObjectManager::getInstance(Request::class);
            $cache_key = $block_class . '_' . json_encode(array_merge($request->getParams(), $params));
            $result    = $cache->get($cache_key) ?: '';
//            if($block_class == 'Weline\Admin\Block\Backend\Page\Topnav'){
//                p($result,1);
//                p(ObjectManager::make($block_class, ['data' => $params])->render());
//                return $result;
//            }
            if (empty($result)) {
                $result = ObjectManager::make($block_class, ['data' => $params])->render();
                $cache->set($cache_key, $result, $cache_time);
            }
        } else {
            $result = ObjectManager::make($block_class, ['data' => $params])->render();
        }
        return $result;
    }
}
if (!function_exists('w_get_string_between_quotes')) {
    /**
     * @DESC          # 读取引号之间的内容
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/4/24 0:20
     * 参数区：
     *
     * @param $string
     *
     * @return array
     */
    function w_get_string_between_quotes($string): array
    {
        $matches_double_quote = [];
        preg_match('/(?<=")[^"\\\\]*(?:\\\\.[^"\\\\]*)*(?=")/', $string, $matches_double_quote);
        foreach ($matches_double_quote as &$item) {
            $item = addslashes($item);
        }
        $matches_single_quote = [];
        preg_match("/(?<=')[^'\\\\]*(?:\\\\.[^'\\\\]*)*(?=')/", $string, $matches_single_quote);
        foreach ($matches_single_quote as &$item) {
            $item = addslashes($item);
        }
        return array_merge($matches_double_quote, $matches_single_quote);
    }
}
