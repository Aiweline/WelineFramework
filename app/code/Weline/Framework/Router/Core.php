<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Router;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\Cache\RouterCache;

class Core
{
    public const dir_static = 'static';

    public const url_path_split = '/';

    private Request $request;

    private string $request_area;

    private string $area_router;

    private bool $is_admin;
    private bool $is_match = false;

    private CacheInterface $cache;

    protected array $router;
    protected string $url;
    /**缓存建*/
    protected string $_router_cache_key;
    protected string $url_cache_key;
    protected string $rule_cache_key;

    /**缓存结果*/
    protected mixed $rule_cache_data = null;
    protected mixed $url_cache_data = null;

    /**
     * @DESC         |任何时候都会初始化
     *
     * 参数区：
     *
     */
    public function __init(): void
    {
        $this->request = ObjectManager::getInstance(Request::class);
        if (empty($this->cache)) {
            $this->cache = ObjectManager::getInstance(RouterCache::class . 'Factory');
        }

        if (empty($this->request_area)) {
            $this->request_area = $this->request->getRequestArea();
        }

        if (empty($this->area_router)) {
            $this->area_router = $this->request->getAreaRouter();
        }
        if (empty($this->is_admin)) {
            $this->is_admin = is_int(strpos(strtolower($this->request_area), \Weline\Framework\Router\DataInterface::area_BACKEND));
        }
        // 读取url
        $this->url_cache_key = 'url_cache_key_' . $this->request->getUri() . $this->request->getMethod();
        $this->rule_cache_key = 'rule_data_cache_key_' . $this->request->getUri() . $this->request->getMethod();
        $this->_router_cache_key = 'router_start_cache_key_' . $this->request->getUri() . $this->request->getMethod();
    }

    public function getRequest(): Request
    {
        return $this->request;
    }


    /**
     * @DESC         |路由处理
     *
     * 参数区：
     *
     * @throws Exception
     * @throws \ReflectionException
     */
    public function start()
    {
        # 获取URL
        $this->url = $url = $this->processUrl();

//        $url                     = str_replace('-', '', $origin_url);
        if ($router = $this->cache->get($this->_router_cache_key)) {
            $this->router = $router;
            return $this->route();
        }
        if (($pc_result = $this->Pc($url)) || $this->is_match) {
            return $pc_result;
        }
        // API
        if (($api_result = $this->Api($url)) || $this->is_match) {
            return $api_result;
        }
        // 非开发模式（匹配不到任何路由将报错）
        if (PROD) {
            $this->request->getResponse()->noRouter();
        } else {
            // 开发模式(静态资源可访问app本地静态资源)
            $static = $this->StaticFile($url);
            if ($static) {
                exit();
            }
            http_response_code(404);
            throw new Exception('未知的路由！');
        }
        return '';
    }

    public function processUrl()
    {
        $url = $this->cache->get($this->url_cache_key);
        $rule = $this->cache->get($this->rule_cache_key);
        if (PROD && $url) {
            $this->url_cache_data = $url;
            $this->rule_cache_data = $rule;
            # 将规则设置到请求类
            $this->request->setRule($rule);
            $this->request->setData($rule);
        } else {
            $url = $this->request->getUrlPath();
            if ($this->is_admin) {
                $url = str_replace($this->area_router, '', $url);
            }
            $url = str_replace('//', '/', $url);
            # ----------事件：处理url之前 开始------------
            /**@var EventsManager $eventManager */
            $eventManager = ObjectManager::getInstance(EventsManager::class);
            $routerData = new DataObject(['path' => $url, 'rule' => []]);
            $eventManager->dispatch('Weline_Framework_Router::process_uri_before', ['data' => $routerData]);
            $url = $routerData->getData('path');
            $rule = $routerData->getData('rule');

            # 将规则设置到请求类
            $this->request->setRule($rule);
            $this->request->setData($rule);
            # ----------事件：处理url之前 结束------------

            $url = trim($url, self::url_path_split);
//            $url = str_replace('.html', '', $url);
            # 去除后缀index
            $url_arr = explode('/', $url);

            $last_rule_value = $url_arr[array_key_last($url_arr)] ?? '';
            while ('index' === array_pop($url_arr)) {
                $last_rule_value = $url_arr[array_key_last($url_arr)] ?? '';
            }
            $url = implode('/', $url_arr) . (('index' !== $last_rule_value) ? '/' . $last_rule_value : '');
            $url = trim($url, '/');
            $url = str_replace('//', '/', $url);
        }

        return $url;
    }

    /**
     * @DESC         |api路由
     *
     * 参数区：
     *
     * @param string $url
     *
     * @return false|void
     * @throws Exception
     * @throws \ReflectionException
     */
    public function Api(string $url)
    {
        $url = strtolower($url);
        $is_api_admin = $this->request_area === \Weline\Framework\Controller\Data\DataInterface::type_api_BACKEND;

        if ($is_api_admin) {
            $router_filepath = Env::path_BACKEND_REST_API_ROUTER_FILE;
        } else {
            // 检测api路由
            $router_filepath = Env::path_FRONTEND_REST_API_ROUTER_FILE;
        }
        if (file_exists($router_filepath)) {
            $routers = include $router_filepath;
            $method = '::' . strtoupper($this->request->getMethod());
            if (
                isset($routers[$url]) || isset($routers[$url . $method]) || (empty($url) && (isset($routers['index/index']) || isset($routers['index/index' . $method])))
            ) {
                $this->router = $routers[$url] ?? $routers[$url . $method] ?? $routers['index/index'] ?? $routers['index/index' . $method];
                # 缓存路由结果
                $this->router['type'] = 'api';
                $this->cache->set($this->_router_cache_key, $this->router);
                return $this->route();
            }
        }
        // 如果是API后端请求，找不到路由就直接404
        if ($is_api_admin) {
            $this->request->getResponse()->noRouter();
        }
        return false;
    }

    /**
     * @DESC         |方法描述
     *
     * 参数区：
     *
     * @param string $url
     *
     * @return false|void
     * @throws Exception
     * @throws \ReflectionException
     */
    public function Pc(string $url)
    {
        $in = false;
        $url = strtolower($url);
        $is_pc_admin = $this->request_area === \Weline\Framework\Controller\Data\DataInterface::type_pc_BACKEND;
        // 检测api路由区域
        if ($is_pc_admin) {
            $router_filepath = Env::path_BACKEND_PC_ROUTER_FILE;
        } else {
            $router_filepath = Env::path_FRONTEND_PC_ROUTER_FILE;
        }
        if (is_file($router_filepath)) {
            $routers = include $router_filepath;
            $method = '::' . strtoupper($this->request->getMethod());
            if (
                isset($routers[$url]) || isset($routers[$url . $method]) || (empty($url) && (isset($routers['index/index']) || isset($routers['index/index' . $method])))
            ) {
                $this->router = $routers[$url . $method] ?? $routers[$url] ?? $routers['index/index'] ?? $routers['index/index' . $method];
                # 缓存路由结果
                $this->router['type'] = 'pc';
                $this->cache->set($this->_router_cache_key, $this->router);
                return $this->route();
            }
        }
        // 如果是PC后端请求，找不到路由就直接404
        if ($is_pc_admin) {
            $this->request->getResponse()->noRouter();
        }

        return false;
    }

    /**
     * @DESC         |返回开发静态文件
     *
     * 参数区：
     *
     * @param string $url
     *
     * @return mixed
     * @throws Exception
     * @throws \ReflectionException
     */
    public function StaticFile(string &$url): mixed
    {
        $filename = APP_CODE_PATH . trim($url, DS);
        $filename = str_replace('/', DS, $filename);
        // 阻止读取其他文件
        if (!str_contains($filename, \Weline\Framework\View\Data\DataInterface::dir)) {
            $this->request->getResponse()->noRouter();
        }
        if (!is_file($filename)) {
            # 检测vendor目录的组件文件
            $filename = VENDOR_PATH . trim($url, DS);
            if (!is_file($filename)) {
                # 检测vendor目录的组件文件
                $split_array = explode('/', $url);
                $module = array_shift($split_array) . '_' . array_shift($split_array);
                $base_path = Env::getInstance()->getModuleInfo($module)['base_path'] ?? '';
                $filename = $base_path . trim(implode('/', $split_array), DS);
                $filename = str_replace('/', DS, $filename);
            }
        }
        if (is_file($filename)) {
            $filename_arr = explode('.', $filename);
            $file_ext = end($filename_arr);
            if ($file_ext === 'css') {
                $mime_type = 'text/css';
            } elseif ($file_ext === 'js') {
                $mime_type = 'text/javascript';
            } else {
                $fi = new \finfo(FILEINFO_MIME_TYPE);
                $mime_type = $fi->file($filename);
            }
            // 响应头
            header('Cache-Control: max-age=3600');
            header("Content-Type:$mime_type;charset=UTF-8");
            // Handle caching
            $fileModificationTime = gmdate('D, d M Y H:i:s', filemtime($filename)).' GMT';
            $headers = getallheaders();
            if(isset($headers['If-Modified-Since']) && $headers['If-Modified-Since'] == $fileModificationTime) {
                header('HTTP/1.1 304 Not Modified');
                exit();
            }
            header('Last-Modified: '.$fileModificationTime);
            header("X-XSS-Protection: 1; mode=block");
            header('Expires: ' . (PROD ? '10' : '0'));
            header('Cache-Control: must-revalidate');
            header('X-Content-Type-Options: nosniff');
            header('Pragma: public');
            header('Content-Length: ' . filesize($filename));
            readfile($filename);
            return true;
        }
        return false;
    }


    public function getController(array $router): array
    {
        $controller_cache_controller_key = 'controller_cache_key_' . implode('_', $router['class']) . '_controller';
        $controller_cache_method_key = 'controller_cache_key_' . implode('_', $router['class']) . '_method';
        $dispatch = $this->cache->get($controller_cache_controller_key);
        $dispatch_method = $this->cache->get($controller_cache_method_key);
        if ($dispatch && $dispatch_method) {
            return [$dispatch, $dispatch_method];
        } else {
            $class_name = $router['class']['name'] ?? '';
            $method = $router['class']['method'] ?: 'index';
            $this->cache->set($controller_cache_method_key, $method);
            $this->cache->set($controller_cache_controller_key, $class_name);
            return [$class_name, $method];
        }
    }

    /**
     * @throws \ReflectionException
     * @throws Exception
     * @throws \Exception
     */
    public function route()
    {
        # 检测模块状态
        $module = $this->router['module'];
        if (!Env::getInstance()->getModuleStatus($module)) {
            $this->request->getResponse()->noRouter();
        }
        # 页头阻止XSS
        header("X-Frame-Options: SAMEORIGIN");
        header("X-Content-Type-Options: nosniff");
        header("X-XSS-Protection: 1; mode=block");
        # 全页缓存
        $cache_key = $this->cache->buildWithRequestKey('router_route_fpc_cache_key_' . Cookie::getLangLocal());
        if (PROD && $html = $this->cache->get($cache_key)) {
            return $html;
        }
        # 方法体方法和请求方法不匹配时 禁止访问
        if ('' !== $this->router['class']['request_method']) {
            if ($this->router['class']['request_method'] !== $this->request->getMethod()) {
                $this->request->getResponse()->noRouter();
            }
        }
        $this->request->setRouter($this->router);
        list($dispatch, $method) = $this->getController($this->router);
        // 解析注解
        $dispatchReflection = ObjectManager::getReflectionInstance($dispatch);
        $attributes = $dispatchReflection->getAttributes();
        foreach ($attributes as $attribute) {
            $dispatchAttribute = ObjectManager::getInstance($attribute->getName(), $attribute->getArguments());
            if (method_exists($dispatchAttribute, 'execute')) {
                $result = $dispatchAttribute->execute();
                if ($result) {
                    return $result;
                }
            }
        }

        /**@var \Weline\Framework\Controller\Core $dispatch */
//        $dispatch->assign($this->request->getData());
        /**@var EventsManager $eventManager */
        $eventManager = ObjectManager::getInstance(EventsManager::class);
        $eventManager->dispatch('Framework_Router::route_before', ['route' => $this]);
        $dispatch = ObjectManager::getInstance($dispatch);
        $dispatch->__setModuleInfo($this->router);

        # 检测控制器方法
        if (!method_exists($dispatch, $method)) {
            $dispatch_class = $dispatch::class;
            throw new Exception("{$dispatch_class}: 控制器方法 {$method} 不存在!");
        }
        $result = call_user_func([$dispatch, $method], /*...$this->request->getParams()*/);
        # ----------事件：处理url之前 开始------------
        $resultData = new DataObject(['result' => $result, 'route' => $this]);
        $eventManager->dispatch('Framework_Router::route_after', ['data' => $resultData]);

//        file_put_contents(__DIR__.'/'.$cache_key.'.html', $result);
        /** Get output buffer. */
        $this->cache->set($cache_key, $result, 5);
        $this->is_match = true;
        # 最后输出前 保证真实可靠的URL才进行缓存
        if (is_null($this->request->uri_cache_url_path_data)) {
            $this->request->cache->set($this->request->uri_cache_key, $this->request->getUri());
        }
        if (!$this->url_cache_data) {
            $this->cache->set($this->rule_cache_key, $this->request->getRule());
            $this->cache->set($this->url_cache_key, $this->url);
        }
        return $result;
    }
}
