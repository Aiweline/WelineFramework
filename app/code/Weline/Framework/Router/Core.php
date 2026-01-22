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
use Weline\Framework\Router\Cache\ProcessUrlCache;

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

    private ?ProcessUrlCache $processUrlCache = null;

    protected array $router;
    protected string $url;
    /**缓存建*/
    protected string $_router_cache_key;
    protected string $url_cache_key;
    protected string $rule_cache_key;
    protected string $unified_cache_key;

    /**缓存结果*/
    protected mixed $rule_cache_data = null;
    protected mixed $url_cache_data = null;

    private array $routerGeneratedGetParams = [];

    private const RULE_CACHE_RULE_KEY = 'rule';
    private const RULE_CACHE_PARAMS_KEY = 'generated_get_params';
    
    // 性能优化：缓存统一缓存数据，避免重复读取
    // 注意：cache->get() 可能返回 false，所以使用 mixed 类型
    private mixed $unifiedCacheData = null;
    
    // 统一缓存结构键名（已移至 RouterCache 类，请使用 RouterCache::UNIFIED_CACHE_*_KEY）

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
        if ($this->processUrlCache === null) {
            $this->processUrlCache = new ProcessUrlCache();
        }

        if (empty($this->request_area)) {
            $this->request_area = $this->request->getRequestArea();
        }

        if (empty($this->area_router)) {
            $this->area_router = $this->request->getAreaRouter();
        }
        if (empty($this->is_admin)) {
            // 优先使用全局变量 WELINE_IS_BACKEND（在 App.php 的 URL 解析阶段已设置）
            if (isset($_SERVER['WELINE_IS_BACKEND'])) {
                $this->is_admin = (bool)$_SERVER['WELINE_IS_BACKEND'];
            } else {
                // 回退到旧的判断方式
                $this->is_admin = is_int(strpos(strtolower($this->request_area), \Weline\Framework\Router\DataInterface::area_BACKEND));
            }
        }
        $this->routerGeneratedGetParams = [];
        // 读取url
        // 使用统一的缓存键生成方法，自动包含域名信息
        // 对于统一缓存键（全页缓存），使用 WELINE_FULL_REQUEST_URI（包含协议、域名、端口、路径、查询参数等完整信息）
        // 对于其他缓存键，使用 REQUEST_URI（纯路径，用于路由匹配）
        $uri = $_SERVER['REQUEST_URI'] ?? $this->request->getUri();
        $method = $this->request->getMethod() ?: 'GET';
        
        // 规范化 URI（去除查询参数），确保缓存键的一致性
        $uri = RouterCache::normalizeUri($uri);
        
        $this->url_cache_key = RouterCache::buildUrlCacheKey($uri, $method, $this->request);
        $this->rule_cache_key = RouterCache::buildRuleCacheKey($uri, $method, $this->request);
        $this->_router_cache_key = RouterCache::buildRouterStartCacheKey($uri, $method, $this->request);
        // 统一缓存键使用完整URI（buildUnifiedRequestCacheKey 内部会获取 WELINE_FULL_REQUEST_URI）
        // 注意：$uri 参数已废弃，buildUnifiedRequestCacheKey 内部会直接使用 WELINE_FULL_REQUEST_URI
        $this->unified_cache_key = RouterCache::buildUnifiedRequestCacheKey('', $method, $this->request);
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
        # ----------事件：路由开始前 开始------------
        // 性能优化：使用静态变量缓存 EventsManager 实例
        static $eventManager = null;
        if ($eventManager === null) {
            $eventManager = ObjectManager::getInstance(EventsManager::class);
        }
        $eventData = ['router' => $this];
        $eventManager->dispatch('Weline_Framework_Router::before_start', $eventData);
        # ----------事件：路由开始前 结束------------
        
        # 获取URL
        $this->url = $url = $this->processUrl();
        
        // 性能优化：复用已读取的统一缓存数据
        if ($this->unifiedCacheData === null) {
            $cached = $this->cache->get($this->unified_cache_key);
            // 将 false 转换为 null，保持类型一致性
            $this->unifiedCacheData = ($cached === false) ? null : $cached;
        }
        
        // 优先从统一缓存中读取 router
        if (is_array($this->unifiedCacheData) && isset($this->unifiedCacheData[RouterCache::UNIFIED_CACHE_ROUTER_KEY]) && !empty($this->unifiedCacheData[RouterCache::UNIFIED_CACHE_ROUTER_KEY])) {
            $this->router = $this->unifiedCacheData[RouterCache::UNIFIED_CACHE_ROUTER_KEY];
            return $this->route();
        }
        
        // 回退到旧的缓存方式（兼容性）
        $router = $this->cache->get($this->_router_cache_key);
        if ($router) {
            $this->router = $router;
            return $this->route();
        }
        # 后台接口请求
        switch ($this->request_area) {
            case \Weline\Framework\Controller\Data\DataInterface::type_api_BACKEND:
            case \Weline\Framework\Controller\Data\DataInterface::type_api_REST_FRONTEND:
                // API
                if (($api_result = $this->Api($url)) || $this->is_match) {
                    return $api_result;
                }
                $this->request->getResponse()->noRouter();
                break;
            case \Weline\Framework\Controller\Data\DataInterface::type_pc_FRONTEND:
            case \Weline\Framework\Controller\Data\DataInterface::type_pc_BACKEND:
                if (($pc_result = $this->Pc($url)) || $this->is_match) {
                    $result = $pc_result;
                    return $result;
                }
                break;
            default:
                try {
                    $static = $this->StaticFile($url, true);
                    if ($static) {
                        exit();
                    }
                } catch (\ReflectionException|Exception $e) {
                    $this->request->getResponse()->noRouter();
                }
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
        // 后端请求不缓存，直接跳过缓存读取
        if ($this->is_admin) {
            $this->routerGeneratedGetParams = [];
            $url = $this->request->getUrlPath();
            if ($this->is_admin || (\Weline\Framework\Controller\Data\DataInterface::type_api_REST_FRONTEND === $this->request_area)) {
                $url = str_replace($this->area_router, '', $url);
            }
            $url = str_replace('//', '/', $url);
            # ----------事件：处理url之前 开始------------
            /**@var EventsManager $eventManager */
            $eventManager = ObjectManager::getInstance(EventsManager::class);
            /** @var DataObject $routerData */
            $routerData = new DataObject(['path' => $url, 'rule' => new DataObject()]);
            $originalGet = $_GET;
            $eventManager->dispatch('Weline_Framework_Router::process_uri_before', $routerData);
            $pathData = $routerData->getData('path');
            $url = is_string($pathData) ? $pathData : (string)($pathData ?? '');
            $ruleData = $routerData->getData('rule');
            if (!($ruleData instanceof DataObject)) {
                $ruleDataArray = is_array($ruleData) ? $ruleData : [];
                $ruleData = new DataObject($ruleDataArray);
                $routerData->setData('rule', $ruleData);
            }
            /** @var DataObject $ruleData */
            $rule = $ruleData->getData();
            $this->routerGeneratedGetParams = $this->collectRouterGeneratedGetParams($originalGet);
            if (!empty($this->routerGeneratedGetParams)) {
                $this->applyRouterGeneratedGetParams();
            }

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
            return $url;
        }
        
        // 性能优化：复用已读取的统一缓存数据
        if ($this->unifiedCacheData === null) {
            $cached = $this->cache->get($this->unified_cache_key);
            // 将 false 转换为 null，保持类型一致性
            $this->unifiedCacheData = ($cached === false) ? null : $cached;
        }
        
        // 优先尝试读取统一缓存（减少 IO 操作）
        if (is_array($this->unifiedCacheData) && !empty($this->unifiedCacheData)) {
            $unifiedCache = $this->unifiedCacheData;
            // 从统一缓存中提取数据
            $url = $unifiedCache[RouterCache::UNIFIED_CACHE_URL_KEY] ?? null;
            $ruleFromCache = $unifiedCache[RouterCache::UNIFIED_CACHE_RULE_KEY] ?? [];
            $cachedGeneratedGetParams = $unifiedCache[RouterCache::UNIFIED_CACHE_PARAMS_KEY] ?? [];
            
            // 如果统一缓存中有路由信息，也设置到 router 属性
            if (isset($unifiedCache[RouterCache::UNIFIED_CACHE_ROUTER_KEY])) {
                $this->router = $unifiedCache[RouterCache::UNIFIED_CACHE_ROUTER_KEY];
            }
            
            // 验证缓存的有效性
            if (PROD && $url && !empty($ruleFromCache)) {
                $this->url_cache_data = $url;
                $this->rule_cache_data = $ruleFromCache;
                $this->routerGeneratedGetParams = $cachedGeneratedGetParams;
                if (!empty($this->routerGeneratedGetParams)) {
                    $this->applyRouterGeneratedGetParams();
                }
                # 将规则设置到请求类
                $this->request->setRule($ruleFromCache);
                $this->request->setData($ruleFromCache);
                return $url;
            }
        }
        
        // 尝试从 ProcessUrlCache 读取缓存（可通过 env 配置禁用：cache.status.process_url_cache = 0）
        $processUrlCacheKey = $this->url_cache_key;
        $processUrlCacheData = $this->processUrlCache->getProcessedUrl($processUrlCacheKey);
        if ($processUrlCacheData !== null) {
            // 从 ProcessUrlCache 获取缓存数据
            $url = $processUrlCacheData['url'];
            $ruleFromCache = $processUrlCacheData['rule'];
            $cachedGeneratedGetParams = $processUrlCacheData['generated_get_params'];
            $this->url_cache_data = $url;
            $this->rule_cache_data = $ruleFromCache;
            $this->routerGeneratedGetParams = $cachedGeneratedGetParams;
            if (!empty($this->routerGeneratedGetParams)) {
                $this->applyRouterGeneratedGetParams();
            }
            # 将规则设置到请求类
            $this->request->setRule($ruleFromCache);
            $this->request->setData($ruleFromCache);
        } else {
            // 回退到旧的缓存方式（兼容性）
            $url = $this->cache->get($this->url_cache_key);
            # 如果后缀是静态文件后缀 .css,.js,.jpg,.png,.jpeg,.gif,.svg,.ico,.woff,.woff2,.eot,.ttf,.otf,.ttf2,.woff3,.mp4,.mp3,.m3u8,.webp
            $isStaticFile = $this->isStaticFile();
            if ($isStaticFile) {
                try {
                    $static = $this->StaticFile($url, true);
                    if ($static) {
                        exit();
                    }
                } catch (\ReflectionException|Exception $e) {
                    $this->request->getResponse()->noRouter();
                }
            }
            $ruleCache = $this->cache->get($this->rule_cache_key);
            [$ruleFromCache, $cachedGeneratedGetParams] = $this->normalizeRuleCache($ruleCache);
            // 修复：验证缓存的有效性，确保 rule 不为空且包含必要信息
            if (PROD && $url && !empty($ruleFromCache)) {
                $this->url_cache_data = $url;
                $this->rule_cache_data = $ruleFromCache;
                $this->routerGeneratedGetParams = $cachedGeneratedGetParams;
                if (!empty($this->routerGeneratedGetParams)) {
                    $this->applyRouterGeneratedGetParams();
                }
                # 将规则设置到请求类
                $this->request->setRule($ruleFromCache);
                $this->request->setData($ruleFromCache);
            } else {
                $this->routerGeneratedGetParams = [];
                $url = $this->request->getUrlPath();
                if ($this->is_admin || (\Weline\Framework\Controller\Data\DataInterface::type_api_REST_FRONTEND === $this->request_area)) {
                    $url = str_replace($this->area_router, '', $url);
                }
                $url = str_replace('//', '/', $url);
                # ----------事件：处理url之前 开始------------
                /**@var EventsManager $eventManager */
                $eventManager = ObjectManager::getInstance(EventsManager::class);
                /** @var DataObject $routerData */
                $routerData = new DataObject(['path' => $url, 'rule' => new DataObject()]);
                $originalGet = $_GET;
                $eventManager->dispatch('Weline_Framework_Router::process_uri_before', $routerData);
                $pathData = $routerData->getData('path');
                $url = is_string($pathData) ? $pathData : (string)($pathData ?? '');
                $ruleData = $routerData->getData('rule');
                if (!($ruleData instanceof DataObject)) {
                    $ruleDataArray = is_array($ruleData) ? $ruleData : [];
                    $ruleData = new DataObject($ruleDataArray);
                    $routerData->setData('rule', $ruleData);
                }
                /** @var DataObject $ruleData */
                $rule = $ruleData->getData();

                $this->routerGeneratedGetParams = $this->collectRouterGeneratedGetParams($originalGet);
                if (!empty($this->routerGeneratedGetParams)) {
                    $this->applyRouterGeneratedGetParams();
                }

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
                
                // 保存到 ProcessUrlCache（可通过 env 配置禁用：cache.status.process_url_cache = 0）
                $this->processUrlCache->setProcessedUrl(
                    $processUrlCacheKey,
                    $url,
                    $rule,
                    $this->routerGeneratedGetParams,
                    !empty($rule)
                );
            }
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
                // 优先处理没有请求方法后缀的路由（如 save 而不是 save::POST），这样可以避免需要强制使用 postSave 这样的命名
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
                // 优先处理没有请求方法后缀的路由（如 save 而不是 save::POST），这样可以避免需要强制使用 postSave 这样的命名
                $this->router = $routers[$url] ?? $routers[$url . $method] ?? $routers['index/index'] ?? $routers['index/index' . $method];
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
     * @param bool $is_media
     * @return mixed
     * @throws Exception
     * @throws \ReflectionException
     */
    public function StaticFile(string &$url, bool $is_media = false): mixed
    {
        # 卸载Cookie
        Cookie::static_file();
        if ($is_media) {
            $filename = BP . trim($url, DS);
            $filename = str_replace('/', DS, $filename);
            # 修复静态资源路径\\ 或者 // 等错误路径修复
            $filename = str_replace(DS . DS, DS, $filename);
        } else {
            $filename = APP_CODE_PATH . trim($url, DS);
            $filename = str_replace('/', DS, $filename);
            # 修复静态资源路径\\ 或者 // 等错误路径修复
            $filename = str_replace(DS . DS, DS, $filename);
        }

        // 阻止读取其他文件
        if (!$is_media && !str_contains($filename, \Weline\Framework\View\Data\DataInterface::dir)) {
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
            // Handle caching
            $fileModificationTime = gmdate('D, d M Y H:i:s', filemtime($filename)) . ' GMT';
            $headers = getallheaders();
            if (isset($headers['If-Modified-Since']) && $headers['If-Modified-Since'] == $fileModificationTime) {
                header('HTTP/1.1 304 Not Modified');
                exit();
            }
            $this->header_cache($fileModificationTime, $filename);

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
            $this->header_response($mime_type);
//            header('Content-Length: ' . filesize($filename));
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
        # 检查headers already sent 是否已发送
        # 页头阻止XSS
        $this->header_xss();

        # 全页缓存 - 后端请求不缓存
        $cache_key = null;
        // 检查全页缓存是否启用（检查 router_cache 和 frontend_cache 配置）
        // 使用静态方法 Env::get()，使用点号分隔符访问嵌套配置
        $routerCacheEnabled = Env::get('cache.status.router_cache', 1);
        $frontendCacheEnabled = Env::get('cache.status.frontend_cache', 1);
        if (!$this->is_admin && $routerCacheEnabled && $frontendCacheEnabled) {
            // 性能优化：复用已读取的统一缓存数据
            if ($this->unifiedCacheData === null) {
                $this->unifiedCacheData = $this->cache->get($this->unified_cache_key);
            }
            
            // 优先从统一缓存中读取
            if (is_array($this->unifiedCacheData) && isset($this->unifiedCacheData[RouterCache::UNIFIED_CACHE_FPC_KEY]) && !empty($this->unifiedCacheData[RouterCache::UNIFIED_CACHE_FPC_KEY])) {
                $unifiedCache = $this->unifiedCacheData;
                // 恢复响应头（先清除已存在的响应头，避免重复）
                if (isset($unifiedCache[RouterCache::UNIFIED_CACHE_HEADERS_KEY]) && is_array($unifiedCache[RouterCache::UNIFIED_CACHE_HEADERS_KEY]) && !headers_sent()) {
                    foreach ($unifiedCache[RouterCache::UNIFIED_CACHE_HEADERS_KEY] as $header) {
                        // 解析响应头名称
                        if (str_contains($header, ':')) {
                            $headerName = trim(explode(':', $header, 2)[0]);
                            // 先移除已存在的同名响应头，避免重复
                            header_remove($headerName);
                        }
                        // 设置响应头
                        header($header, true); // true 表示替换已存在的同名 header
                    }
                }
                // 添加缓存命中标志 header（使用框架独有的标识）
                header('X-Weline-FPC: HIT');
                return $unifiedCache[RouterCache::UNIFIED_CACHE_FPC_KEY];
            }
            
            // 回退到旧的缓存方式（兼容性）
            $cache_key = $this->cache->buildWithRequestKey('router_route_fpc_cache_key_' . Cookie::getLangLocal());
            if (PROD && $html = $this->cache->get($cache_key)) {
                // 添加缓存命中标志 header（使用框架独有的标识）
                header('X-Weline-FPC: HIT');
                return $html;
            }
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
        $eventData = ['route' => $this];
        $eventManager->dispatch('Weline_Framework_Router::route_before', $eventData);
        $dispatch = ObjectManager::getInstance((string)$dispatch);
        $dispatch->__setModuleInfo($this->router);
        # 检测控制器方法
        if (!method_exists($dispatch, $method)) {
            $dispatch_class = $dispatch::class;
            throw new Exception(__('%{1}: 控制器方法 %{2} 不存在!', [$dispatch_class, $method]));
        }
        // 开启输出缓冲区以捕获控制器输出
        ob_start();
        
        try {
            $result = call_user_func([$dispatch, $method], /*...$this->request->getParams()*/);
            # ----------事件：处理url之前 开始------------
            $resultData = new DataObject(['result' => $result, 'route' => $this]);
            $eventData = ['data' => $resultData];
            $eventManager->dispatch('Weline_Framework_Router::route_after', $eventData);
            // 获取输出缓冲区内容（控制器可能直接输出而不是返回）
            $output = ob_get_clean();
            // 如果控制器返回了结果，优先使用返回值；否则使用输出缓冲区内容
            $fpcHtml = !empty($result) ? (is_string($result) ? $result : $output) : $output;
            
            // 捕获响应头（在控制器执行后）
            $responseHeaders = headers_list();
        } catch (\Exception $e) {
            // 异常情况下清理输出缓冲区
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            throw $e;
        }

//        file_put_contents(__DIR__.'/'.$cache_key.'.html', $result);
        /** Get output buffer. */
        // 只在前端请求时保存旧的缓存键（兼容性）
        if ($cache_key !== null) {
            $this->cache->set($cache_key, $fpcHtml, 5);
        }
        $this->is_match = true;
        # 最后输出前 保证真实可靠的URL才进行缓存
        if (is_null($this->request->uri_cache_url_path_data)) {
            $this->request->cache->set($this->request->uri_cache_key, $this->request->getUri());
        }
        
        // 后端请求不缓存，只缓存前端请求
        // 检查全页缓存是否启用（检查 router_cache 和 frontend_cache 配置）
        // 使用静态方法 Env::get()，使用点号分隔符访问嵌套配置
        $routerCacheEnabled = Env::get('cache.status.router_cache', 1);
        $frontendCacheEnabled = Env::get('cache.status.frontend_cache', 1);
        if (!$this->is_admin && $routerCacheEnabled && $frontendCacheEnabled && !empty($fpcHtml)) {
            // 构建统一缓存结构，包含所有请求相关数据
            $unifiedCacheData = [
                \Weline\Framework\Router\Cache\RouterCache::UNIFIED_CACHE_URL_KEY => $this->url,
                \Weline\Framework\Router\Cache\RouterCache::UNIFIED_CACHE_RULE_KEY => $this->request->getRule(),
                \Weline\Framework\Router\Cache\RouterCache::UNIFIED_CACHE_ROUTER_KEY => $this->router,
                \Weline\Framework\Router\Cache\RouterCache::UNIFIED_CACHE_PARAMS_KEY => $this->routerGeneratedGetParams,
                \Weline\Framework\Router\Cache\RouterCache::UNIFIED_CACHE_FPC_KEY => $fpcHtml, // 全页缓存 HTML
                \Weline\Framework\Router\Cache\RouterCache::UNIFIED_CACHE_HEADERS_KEY => $responseHeaders, // 全页缓存响应头
            ];
            
            // 保存统一缓存（使用较长的过期时间，因为包含全页缓存）
            // 确保使用正确的缓存键（基于 WELINE_FULL_REQUEST_URI）
            // 在保存前重新生成缓存键，确保与读取时一致
            $saveCacheKey = RouterCache::buildUnifiedRequestCacheKey('', $this->request->getMethod() ?: 'GET', $this->request);
            $this->cache->set($saveCacheKey, $unifiedCacheData, 3600);
        }
        // 兼容性：如果 url_cache_data 为空，也保存到旧的缓存键
        if (!$this->url_cache_data) {
            $ruleCachePayload = [
                self::RULE_CACHE_RULE_KEY => $this->request->getRule(),
                self::RULE_CACHE_PARAMS_KEY => $this->routerGeneratedGetParams,
            ];
            $this->cache->set($this->rule_cache_key, $ruleCachePayload);
            $this->cache->set($this->url_cache_key, $this->url);
        }
        // 返回结果（如果控制器返回了值）或输出缓冲区内容
        return !empty($result) ? $result : $fpcHtml;
    }

    /**
     * @return void
     */
    public function header_xss(): void
    {
        header("X-Frame-Options: SAMEORIGIN");
        header("X-Content-Type-Options: nosniff");
        header("X-XSS-Protection: 1; mode=block");
    }

    /**
     * @param string|null $mime_type
     * @return void
     */
    public function header_response(?string $mime_type): void
    {
        header('Cache-Control: max-age=3600');
        header("Content-Type:$mime_type;charset=UTF-8");
    }

    /**
     * @param string $fileModificationTime
     * @param array|string $filename
     * @return void
     */
    public function header_cache(string $fileModificationTime, array|string $filename): void
    {
        header('Last-Modified: ' . $fileModificationTime);
        header("X-XSS-Protection: 1; mode=block");
        header('Expires: ' . (PROD ? '10' : '0'));
        header('Cache-Control: must-revalidate');
        header('X-Content-Type-Options: nosniff');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filename));
    }

    private function isStaticFile(): bool
    {
        if(!$_SERVER['WELINE_PARSER_URL']){
            return false;
        }
        $arrs = $this->request->getPathSplit();
        $last = end($arrs);
        if (str_contains($last, '.') and preg_match('/\.(jpg|jpeg|png|webp|gif|css|js|ico|woff|woff2|txt|pdf|doc|docx|xls|xlsx|ppt|pptx)$/', $last)) {
            return true;
        }
        return false;
    }

    private function collectRouterGeneratedGetParams(array $originalGet): array
    {
        $generated = [];
        foreach ($_GET as $key => $value) {
            if (!array_key_exists($key, $originalGet) || $originalGet[$key] !== $value) {
                $generated[$key] = $value;
            }
        }
        return $generated;
    }

    private function applyRouterGeneratedGetParams(): void
    {
        foreach ($this->routerGeneratedGetParams as $paramKey => $paramValue) {
            $this->request->setGet($paramKey, $paramValue);
        }
    }

    /**
     * @param mixed $cached
     * @return array{0: array, 1: array}
     */
    private function normalizeRuleCache(mixed $cached): array
    {
        if (is_array($cached) && array_key_exists(self::RULE_CACHE_RULE_KEY, $cached)) {
            $rule = $cached[self::RULE_CACHE_RULE_KEY] ?? [];
            $params = $cached[self::RULE_CACHE_PARAMS_KEY] ?? [];
            return [is_array($rule) ? $rule : [], is_array($params) ? $params : []];
        }
        return [is_array($cached) ? $cached : [], []];
    }
}
