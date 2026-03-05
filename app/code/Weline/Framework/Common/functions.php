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

use Weline\Framework\App\Debug;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\FrameworkQueryService;

# 数组点号语法取值（支持嵌套数组）
if (!function_exists('w_array_get')) {
    function w_array_get(array $array, string $key, mixed $default = null): mixed
    {
        if (!str_contains($key, '.')) {
            return $array[$key] ?? $default;
        }
        foreach (explode('.', $key) as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return $default;
            }
            $array = $array[$segment];
        }
        return $array;
    }
}

# obj 模型实例化方法
if (!function_exists('w_obj')) {
    function w_obj(string $class)
    {
        return ObjectManager::getInstance($class);
    }
}
# url url生成实例化方法
if (!function_exists('w_url')) {
    function w_url(string $path, array $params=[], string $area='auto', bool $merge_url_params = false)
    {
        /**@var Weline\Framework\Http\Url $urlObj */
        $urlObj = w_obj('Weline\Framework\Http\Url');
        if('auto'===$area or 0 === $area){
           return $urlObj->getUrl($path, $params, $merge_url_params);
        }elseif('backend'===$area or 1 === $area){
            return $urlObj->getBackendUrl($path, $params, $merge_url_params);
        }elseif('backend_api'===$area or 2 === $area){
            return $urlObj->getBackendApiUrl($path, $params, $merge_url_params);
        }elseif('frontend'===$area or 3 === $area){
            return $urlObj->getFrontendUrl($path, $params, $merge_url_params);
        }elseif('frontend_api'===$area or 4 === $area){
            return $urlObj->getFrontendApiUrl($path, $params, $merge_url_params);
        }else{
            return $urlObj->getUrl($path, $params, $merge_url_params);
        }
    }
}
if (!function_exists('getStringBetweenContents')) {
    function getStringBetweenContents(string $str, string $startDelimiter, string $endDelimiter): array
    {
        $contents = [];
        $startDelimiterLength = strlen($startDelimiter);
        $endDelimiterLength = strlen($endDelimiter);
        $startFrom = $contentStart = $contentEnd = 0;
        while (false !== ($contentStart = strpos($str, $startDelimiter, $startFrom))) {
            $contentStart += $startDelimiterLength;
            $contentEnd = strpos($str, $endDelimiter, $contentStart);
            if (false === $contentEnd) {
                break;
            }
            $contents[] = substr($str, $contentStart, $contentEnd - $contentStart);
            $startFrom = $contentEnd + $endDelimiterLength;
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
     * @param string $words
     * @param array|string|null $args
     *
     * @return string
     */
    function __(string $words, array|string|int $args = ''): string
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
        $arrs = preg_split('/(?=[A-Z])/', $str);
        foreach ($arrs as $ik => $item) {
            if ('' === $item) {
                unset($arrs[$ik]);
            }
        }
        return array_values($arrs);
    }
}

if (!function_exists('w_snake_to_pascal')) {
    /**
     * snake_case / kebab-case 转 PascalCase
     *
     * 用于动态事件 {Module}::query 场景下，model 参数简写解析：
     * - product -> Product
     * - product_category -> ProductCategory
     *
     * @param string $str 输入字符串，支持 snake_case、kebab-case、小写
     * @return string PascalCase 字符串
     */
    function w_snake_to_pascal(string $str): string
    {
        $str = str_replace(['_', '-'], ' ', $str);
        return str_replace(' ', '', ucwords($str, ' '));
    }
}

if (!function_exists('w_resolve_model_class')) {
    /**
     * 在已知模块下解析短 model 名为完整类名
     *
     * 当 dispatch(WeShop_Product::query) 时，model 可省去模块前缀，直接写 Product、product、product_category 等。
     *
     * @param string $module 模块名，如 WeShop_Product
     * @param string $model 短 model 名：Product、product、product_category
     * @return string 完整类名，如 WeShop\Product\Model\ProductCategory
     */
    function w_resolve_model_class(string $module, string $model): string
    {
        if (str_contains($model, '\\')) {
            return $model;
        }
        $pascal = w_snake_to_pascal($model);
        $namespace = str_replace('_', '\\', $module);
        return $namespace . '\\Model\\' . $pascal;
    }
}

if (!function_exists('w_query')) {
    /**
     * 统一查询器全局函数
     *
     * 对应前端 JS 的 window.w_query()，用于模块间数据查询。
     * 内部调用 FrameworkQueryService::execute()，由 QueryProviderRegistry 中已注册的查询器处理。
     *
     * @param string $provider  提供者标识（如 crud、widget、websites、saas）或 framework（introspect）
     * @param string $operation 操作名
     * @param array  $params    操作参数
     * @param string $area      frontend|backend，默认 backend
     * @return mixed 查询结果
     * @throws \InvalidArgumentException 参数错误
     * @throws \RuntimeException 查询被拒绝或执行失败
     *
     * @example
     * // 查询 Widget 列表
     * $widgets = w_query('widget', 'getAvailableList', ['page_type' => 'homepage']);
     *
     * // 查询域名列表
     * $domains = w_query('websites', 'getDomainList', ['account_id' => 123]);
     *
     * // 使用 CRUD 通用查询
     * $products = w_query('crud', 'list', [
     *     'model' => 'WeShop\\Product\\Model\\Product',
     *     'page' => 1,
     *     'page_size' => 20,
     * ]);
     *
     * // 查询所有已注册的 provider
     * $providers = w_query('framework', 'introspect', ['what' => 'providers']);
     *
     * // 查询某 provider 的所有 operation
     * $ops = w_query('framework', 'introspect', ['what' => 'operations', 'provider' => 'widget']);
     */
    function w_query(string $provider, string $operation, array $params = [], string $area = 'backend'): mixed
    {
        /** @var FrameworkQueryService $queryService */
        $queryService = ObjectManager::getInstance(FrameworkQueryService::class);
        return $queryService->execute($provider, $operation, $params, $area);
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
        $array = preg_split("/\r\n|\n|\r/", $export);
        $array = preg_replace(['/\s*array\s\($/', '/\)(,)?$/', '/\s=>\s$/'], [null, ']$1', ' => ['], $array);
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
                throw new \Weline\Framework\App\Exception(__('framework.view.block.class_not_found %{1}', $data['class']));
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
            $request = ObjectManager::getInstance(Request::class);
            $cache_key = $block_class . '_' . json_encode(array_merge($request->getParams(), $params));
            $result = $cache->get($cache_key) ?: '';
            // form_key 不能做缓存，否则 key 不对（每个 session 不同）
            $hasFormKey = str_contains($result, 'name="form_key"');
            if (empty($result) || $hasFormKey) {
                $result = ObjectManager::make($block_class, ['data' => $params])->render();
                if (!str_contains($result, 'name="form_key"')) {
                    $cache->set($cache_key, $result, $cache_time);
                }
            }
        } else {
            $result = ObjectManager::make($block_class, ['data' => $params])->render();
        }
        return $result;
    }
}
if (!function_exists('w_msg')) {
    /**
     * 发送系统通知
     *
     * @param string $topic 消息主题（如 domain_expiring, system_info）
     * @param string $type 消息类型：info/success/warning/error/urgent
     * @param string $title 消息标题
     * @param string $content 消息内容
     * @param array $options 可选参数：
     *   - priority: int 优先级 1-10，默认根据 type 自动设定
     *   - metadata: array 扩展数据
     *   - icon: string 图标类名，默认 ri-notification-line
     *   - notify_users: array 指定通知的用户 ID 列表，空则通知所有订阅者
     *   - source_module: string 来源模块，自动检测
     */
    function w_msg(
        string $topic,
        string $type,
        string $title,
        string $content,
        array $options = []
    ): void {
        /** @var \Weline\Framework\Event\EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(
            \Weline\Framework\Event\EventsManager::class
        );

        $eventData = [
            'data' => [
                'topic'         => $topic,
                'type'          => $type,
                'title'         => $title,
                'content'       => $content,
                'priority'      => $options['priority'] ?? null,
                'metadata'      => $options['metadata'] ?? [],
                'is_icon'       => 1,
                'avatar'        => $options['icon'] ?? 'ri-notification-line',
                'notify_users'  => $options['notify_users'] ?? [],
                'source_module' => $options['source_module'] ?? '',
            ]
        ];

        $eventsManager->dispatch('Weline_Backend::application::system_notification', $eventData);
    }
}
if (!function_exists('w_cache')) {
    /**
     * 获取缓存池
     *
     * @param string $identity 池标识（如 router, config, database）
     * @return CachePoolInterface
     */
    function w_cache(string $identity = 'default'): CachePoolInterface
    {
        static $manager = null;
        if ($manager === null) {
            $manager = ObjectManager::getInstance(CacheManager::class);
        }
        return $manager->pool($identity);
    }
}

if (!function_exists('w_cache_manager')) {
    /**
     * 获取缓存管理器
     *
     * @return CacheManager
     */
    function w_cache_manager(): CacheManager
    {
        return ObjectManager::getInstance(CacheManager::class);
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
