<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Http\Request;

use Weline\Framework\App\Debug;
use Weline\Framework\App\State;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Controller\Data\DataInterface;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Http\Response;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;

abstract class RequestAbstract extends RequestFilter
{
    /**缓存专区*/
    public string $uri_cache_key = '';
    public string $uri_cache_url_path_data = '';
    public const HEADER = 'header';

    public const MOBILE_DEVICE_HEADERS = [
        'nokia', 'sony', 'ericsson', 'mot', 'samsung', 'htc',
        'sgh', 'lg', 'sharp', 'sie-', 'philips', 'panasonic',
        'alcatel', 'lenovo', 'iphone', 'ipod', 'blackberry',
        'meizu', 'android', 'netfront', 'symbian', 'ucweb',
        'windowsce', 'palm', 'operamini', 'operamobi', 'openwave',
        'nexusone', 'cldc', 'midp', 'wap', 'mobile',
    ];

    private string $area_router = State::area_frontend;
    private string $uri = '';
    private string $origin_uri = '';

    /**
     * @var CachePoolInterface|null
     */
    public ?CachePoolInterface $cache = null;

    private array $parse_url = [];

    /**
     * @var \Weline\Framework\Http\Response
     */
    public ?\Weline\Framework\Http\Response $_response = null;

    /**
     * ServerBag - 服务器变量管理器
     * @var ServerBag|null
     */
    protected ?ServerBag $serverBag = null;

    public function __init()
    {
        // 初始化 ServerBag
        if ($this->serverBag === null) {
            $this->serverBag = new ServerBag();
            $this->serverBag->initFromGlobals();
        }
        
        # FIXME 兼容$_GET将"."替换成"_"的情况，暂不清楚$_POST情况，可能第一层键名也会有此情况
        $query_str = $this->getServer('QUERY_STRING');
        if (str_contains($query_str ?? '', '.')) {
            $query_str = str_replace('&amp;', '&', $query_str);
            $query_str_arr = explode('&', $query_str);
            foreach ($query_str_arr as $item) {
                if (str_contains($item, '.')) {
                    $item = explode('=', $item);
                    if (str_contains($item[0], '.')) {
                        $_GET[$item[0]] = $item[1];
                        unset($_GET[str_replace('.', '_', $item[0])]);
                    }
                }
            }
        }
        if (empty($this->cache)) {
            $this->cache = w_cache('request');
        }
        // 使用统一的缓存键生成方法，自动包含域名信息
        // 如果 uri_cache_key 为空或不包含域名信息（旧格式），则重新生成
        $uri = $this->getServer('REQUEST_URI') ?? '';
        $method = $this->getMethod() ?: 'GET';
        $expected_cache_key = KeyBuilder::buildRouteKey($uri, $method);
        
        // 检查是否需要重新生成缓存键（旧格式不包含域名信息）
        if (empty($this->uri_cache_key)) {
            $this->uri_cache_key = $expected_cache_key;
        } else {
            // 检查缓存键是否包含域名信息
            $domain_key = KeyBuilder::getDomainKey();
            $host = $this->getServer('HTTP_HOST') ?? '';
            if (!str_contains($this->uri_cache_key, $domain_key) && !str_contains($this->uri_cache_key, $host)) {
                $this->uri_cache_key = $expected_cache_key;
            }
        }
        if (empty($this->_response)) {
            $this->_response = $this->getResponse();
        }
    }

    public function parse_url(string $url = ''): bool|int|array|string|null
    {
        if (empty($url)) {
            if (empty($this->parse_url)) {
                $this->parse_url = parse_url(rtrim($this->getUri(), '/'));
            }
            return $this->parse_url;
        } else {
            return parse_url(rtrim($url, '/'));
        }
    }

    /**
     * @DESC         |设置原始路由
     *
     * 参数区：
     *
     * @param array $router
     *
     * @return \Weline\Framework\Http\Request\RequestAbstract
     */
    public function setRouter(array $router): RequestAbstract
    {
        return $this->setData('router', $router);
    }


    /**
     * @DESC         |获取原始路由
     *
     * 参数区：
     *
     * @return array
     */
    public function getRouter(): array
    {
        return $this->getData('router') ?? [];
    }

    /**
     * @DESC         |获取原始路由
     *
     * 参数区：
     *
     * @return string|null
     */
    public function getRouterData(string $key): mixed
    {
        return $this->getData('router/' . $key);
    }

    /**
     * @DESC         |获取模块名
     *
     * 参数区：
     *
     * @return string
     */
    public function getModuleName(): string
    {
        return $this->router['name'] ?? '';
    }

    /**
     * @DESC         |获取请求区域
     *
     * 参数区：
     *
     * @return string
     */
    public function getRequestArea(): string
    {
        // WLS 模式：先清除旧的区域标志，避免跨请求状态污染
        // 这是关键修复：确保每次调用都重新计算区域，不受上一个请求的影响
        $this->unsetData('backend');
        $this->unsetData('api_frontend');
        $this->unsetData('api_backend');
        
        switch ($this->getServer('WELINE_AREA')) {
            case 'backend':
            case 'admin':  // 兼容旧值
                $area = DataInterface::type_pc_BACKEND;
                $this->setBackend();
                break;
            case 'rest_frontend':
            case 'api':  // 兼容旧值
                $area = DataInterface::type_api_REST_FRONTEND;
                $this->setApiFrontend();
                break;
            case 'rest_backend':
            case 'api_admin':  // 兼容旧值
                $area = DataInterface::type_api_BACKEND;
                $this->setApiBackend();
                break;
            default:
                $area = DataInterface::type_pc_FRONTEND;
                break;
        }
        /**@var EventsManager $eventManager */
        $eventManager = ObjectManager::getInstance(EventsManager::class);
        $eventData = ['area' => $area, 'path' => $this->area_router];
        $eventManager->dispatch('Weline_Framework_Http::process_area', $eventData);
        return $area;
    }

    /**
     * @DESC         |获取
     *
     * 参数区：
     *
     * @return mixed|string
     */
    public function getAreaRouter(): mixed
    {
        $areaRoute = $this->getServer('WELINE_AREA_ROUTE');
        return ($areaRoute !== '' && $areaRoute !== null) ? $areaRoute : $this->area_router;
    }

    /**
     * @DESC          # 是否后端请求
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/14 23:20
     * 参数区：
     * @return RequestAbstract
     */
    public function setBackend(): static
    {
        return $this->setData('backend', true);
    }

    /**
     * @DESC          # 是否后端请求
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/14 23:20
     * 参数区：
     * @return bool
     */
    public function isBackend(): bool
    {
        return $this->getData('backend') ?: false;
    }

    public function setApiFrontend(): static
    {
        return $this->setData('api_frontend', true);
    }

    public function isApiFrontend(): bool
    {
        return (bool)$this->getData('api_frontend');
    }


    public function setApiBackend(): static
    {
        return $this->setData('api_backend', true);
    }

    public function isApiBackend(): bool
    {
        return $this->getData('api_backend') ?: false;
    }

    /**
     * @DESC         |获取服务器变量
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param string $key
     *
     * @return string|array
     */
    public function getServer(string $key = ''): string|array
    {
        $filter = RequestFilter::getInstance();
        $filter->init();
        
        // 确保 ServerBag 已初始化
        if ($this->serverBag === null) {
            $this->serverBag = new ServerBag();
        }
        
        // WLS 模式下，每次调用都需要检查是否需要重新初始化
        // initFromGlobals() 内部会检查请求 ID 是否变化
        $this->serverBag->initFromGlobals();
        
        if ($key) {
            switch ($key) {
                case self::HEADER:
                    // 获取所有 HTTP Headers
                    return $this->serverBag->getHeaders();
                default:
                    return $this->serverBag->get($key, '');
            }
        }
        
        return $this->serverBag->all();
    }
    
    /**
     * 获取 ServerBag 实例
     * 
     * @return ServerBag
     */
    public function getServerBag(): ServerBag
    {
        if ($this->serverBag === null) {
            $this->serverBag = new ServerBag();
        }
        // WLS 模式下，每次调用都需要检查是否需要重新初始化
        $this->serverBag->initFromGlobals();
        return $this->serverBag;
    }

    /**
     * @DESC         |设置服务器变量
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param string $key
     * @param string $value
     * @param bool $reload
     * @return RequestAbstract
     */
    public function setServer(string $key, string $value, bool $reload = false): static
    {
        // 确保 ServerBag 已初始化
        if ($this->serverBag === null) {
            $this->serverBag = new ServerBag();
            $this->serverBag->initFromGlobals();
        }
        
        $this->serverBag->set($key, $value);
        return $this;
    }

    /**
     * @DESC         |请求方法
     *
     * 参数区：
     *
     * @return string
     */
    public function getMethod(): string
    {
        return $this->getServerBag()->getMethod();
    }

    /**
     * @DESC         |设置请求方法
     *
     * 参数区：
     *
     * @param string $method
     * @return string
     */
    public function setMethod(string $method): string
    {
        return $this->setServer('REQUEST_METHOD', $method);
    }

    /**
     * @DESC         |是否手机设备
     *
     * 参数区：
     *
     * @return bool
     */
    public function isMobile(): bool
    {
        $serverBag = $this->getServerBag();
        
        //如果有HTTP_X_WAP_PROFILE则一定是移动设备
        if ($serverBag->has('HTTP_X_WAP_PROFILE')) {
            return true;
        }
        //如via信息有wap一定是移动设备，但是部分服务商会屏蔽该信息
        $via = $serverBag->get('HTTP_VIA', '');
        if ($via) {
            //找不到为flase,否则为true
            return stristr($via, 'wap') ? true : false;
        }
        //判断手机发送的客户端标志,兼容性有待提高
        $userAgent = $serverBag->getUserAgent();
        if ($userAgent) {
            //从HTTP_USER_AGENT中查找手机浏览器的关键字
            if (preg_match(
                '/(' . implode('|', self::MOBILE_DEVICE_HEADERS) . ')/i',
                strtolower($userAgent)
            )) {
                return true;
            }
        }
        //协议法，因为有可能不准确，放到最后判断
        $accept = $serverBag->get('HTTP_ACCEPT', '');
        if ($accept) {
            //如果只支持wml并且不支持html那一定是app
            //如果支持wml和html但是wml在html之前则是app
            if ((strpos($accept, 'vnd.wap.wml') !== false)
                && (
                    strpos($accept, 'text/html') === false ||
                    (strpos($accept, 'vnd.wap.wml')
                        < strpos($accept, 'text/html'))
                )) {
                return true;
            }
        }

        return false;
    }

    /**
     * 失效 URI 缓存
     * 
     * WLS 模式下，Url::parser() 会修改 $_SERVER['REQUEST_URI']（去除区域、货币、语言前缀）。
     * 如果在 parser 之前 getUri() 已被调用并缓存了原始 URI，需要清除缓存。
     * 
     * @return static
     */
    public function invalidateUriCache(): static
    {
        $this->uri = '';
        $this->origin_uri = '';
        $this->uri_cache_key = '';
        $this->parse_url = [];
        return $this;
    }
    
    public function getUri(): string
    {
        if ($this->uri !== '') {
            return $this->uri;
        }
        $uri = $this->getServer('REQUEST_URI') ?? '';
        $uri = KeyBuilder::normalizeUri($uri);

        // 使用统一的缓存键生成方法，确保 uri_cache_key 包含域名信息
        if (empty($this->uri_cache_key)) {
            $method = $this->getMethod() ?: 'GET';
            $this->uri_cache_key = KeyBuilder::buildRouteKey($uri, $method);
        } else {
            // 检查缓存键是否包含域名信息（旧格式可能不包含）
            $domain_key = KeyBuilder::getDomainKey();
            $host = $this->getServer('HTTP_HOST') ?? '';
            if (!str_contains($this->uri_cache_key, $domain_key) && !str_contains($this->uri_cache_key, $host)) {
                $method = $this->getMethod() ?: 'GET';
                $this->uri_cache_key = KeyBuilder::buildRouteKey($uri, $method);
            }
        }

        // 只有在 uri_cache_key 已正确设置且包含域名信息时才查找缓存
        if (!empty($this->uri_cache_key) && $this->cache) {
            $url_path = $this->cache->get($this->uri_cache_key);
            if ($url_path) {
                $this->uri_cache_url_path_data = $url_path;
                $this->setServer('REQUEST_URI', $uri);
                return $url_path;
            }
        }
        $this->uri = $uri;
        return $uri;
    }

        public function getOriginUri(): string
    {
        if ($this->origin_uri !== '') {
            return $this->origin_uri;
        }
        $origin_uri = $this->getServer('WELINE_ORIGIN_REQUEST_URI');
        if ($origin_uri) {
            $this->origin_uri = rtrim($origin_uri, '/');
            return $this->origin_uri;
        }
        $origin_uri = $this->getServer('REQUEST_URI');
        return $origin_uri;
    }

    /**
     * @DESC          # 获取请求的module路由路径
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/22 20:24
     * 参数区：
     * @return string
     */
    public function getModuleUrlPath(): string
    {
        $url_exp = $this->parse_url();
        return array_shift($url_exp);
    }

    public function getOriginBaseUrl(): string
    {
        $uri = $this->getOriginUri();
        $url_exp = explode('?', $uri);
        return $this->getBaseHost() . array_shift($url_exp);
    }

    public function getBaseUrl(): string
    {
        $uri = $this->getUri();
        $url_exp = explode('?', $uri);
        return $this->getBaseHost() . array_shift($url_exp);
    }

    public function getFullUrl(): string
    {
        return $this->getServer('REQUEST_SCHEME') . '://' . $this->getServer('SERVER_NAME') . $this->getServer('REQUEST_URI');
    }

    public function getBaseUri(): string
    {
        $uri = $this->getUri();
        $url_exp = explode('?', $uri);
        return $this->getBaseHost() . array_shift($url_exp);
    }

    public function getFirstUrlPath(): string
    {
        $uri = $this->getUri();
        $url_exp = explode('?', $uri);
        return trim(array_shift($url_exp), '/');
    }

    /**
     * 获取当前请求的协议（http 或 https）
     * 
     * @return string 'http' 或 'https'
     */
    public function getSsl(): string
    {
        return self::detectScheme();
    }
    
    /**
     * 静态方法：检测当前请求的协议
     * 可在任何地方调用，无需实例化 Request 对象
     * 
     * @return string 'http' 或 'https'
     */
    public static function detectScheme(): string
    {
        // REQUEST_SCHEME
        if (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https') {
            return 'https';
        }
        
        // HTTPS 头
        if (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] === '1')) {
            return 'https';
        }
        
        // 代理头 X-Forwarded-Proto
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            return 'https';
        }
        
        // Weline 自定义代理头
        if (isset($_SERVER['HTTP_WELINE_ORIGINAL_SCHEME']) && $_SERVER['HTTP_WELINE_ORIGINAL_SCHEME'] === 'https') {
            return 'https';
        }
        
        // 端口 443
        if (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] === '443') {
            return 'https';
        }
        
        return 'http';
    }
    
    /**
     * 判断当前请求是否为 HTTPS
     * 
     * @return bool
     */
    public function isSecure(): bool
    {
        return self::detectScheme() === 'https';
    }

    public function getBaseHost(): string
    {
        $currentScheme = $this->getSsl();
        
        // 直接从 $_SERVER 读取（ServerBag 可能在 Url::parser() 前初始化，值已过时）
        $currentPort = $_SERVER['SERVER_PORT'] ?? '';
        $isNonStandardPort = ($currentPort !== '' && $currentPort != 80 && $currentPort != 443);
        
        // WELINE_WEBSITE_URL 由 Url::parser() → processUrlParse() 写入 $_SERVER
        // 它包含 scheme://host[:port][/sub_path]
        // URL 生成时始终参考当前请求的端口（WLS 非标准端口如 9981 必须带上），避免生成错误链接
        $websiteUrl = $_SERVER['WELINE_WEBSITE_URL'] ?? '';
        if ($websiteUrl !== '') {
            $parsed = \parse_url($websiteUrl);
            $hostPart = $parsed['host'] ?? 'localhost';
            $pathPart = $parsed['path'] ?? '';

            $portSuffix = $isNonStandardPort ? ':' . $currentPort : '';
            return $currentScheme . '://' . $hostPart . $portSuffix . $pathPart;
        }
        
        // 优先使用 HTTP_HOST（客户端发送的 Host 头通常带端口，如 localhost:9981），保证生成的后台 URL 携带当前后端端口
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        if (\str_contains($host, ':')) {
            return $currentScheme . '://' . $host;
        }
        return $currentScheme . '://' . $host . ($isNonStandardPort ? ':' . $currentPort : '');
    }

    public function getPrePath(): string
    {
        if ($this->getAreaRouter() == '') {
            return $this->getBaseHost() . '/';
        }
        return $this->getBaseHost() . '/' . $this->getServer('WELINE_AREA') . '/';
    }

    /**
     * 获取用于展示的基础 URL（带端口，仅含路径）
     *
     * 用于页面访问地址（pageUrlDisplay）、复制链接、预览等展示场景。
     * 始终跟随当前请求的 scheme、host、port、path，端口在非标准情况下必须带上。
     *
     * @return string 如 http://localhost:9981/foo 或 https://example.com:8443/xxx
     */
    public function getDisplayBaseUrl(): string
    {
        $scheme = $this->getSsl();
        // 优先使用 HTTP_HOST（客户端 Host 头通常带端口），保证生成的 URL 携带当前请求端口
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $port = $_SERVER['SERVER_PORT'] ?? '';
        $withPort = false;
        if (\str_contains($host, ':')) {
            $withPort = false;
        } elseif ($port !== '') {
            $withPort = !(
                ($scheme === 'http' && $port == 80) ||
                ($scheme === 'https' && $port == 443)
            );
        }
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $basePath = '';
        if (!empty($requestUri)) {
            $basePath = parse_url($requestUri, PHP_URL_PATH);
            if (!empty($scriptName) && strpos($basePath, $scriptName) === 0) {
                $basePath = substr($basePath, strlen($scriptName));
            }
            $basePath = trim($basePath, '/');
        }
        $url = $scheme . '://' . $host;
        if ($withPort) {
            $url .= ':' . $port;
        }
        if ($basePath !== '') {
            $url = rtrim($url, '/') . '/' . $basePath;
        }
        return rtrim($url, '/');
    }

    /**
     * @DESC         |返回响应类
     *
     * 参数区：
     *
     * @return Response
     */
    public function getResponse(): Response
    {
        return $this->_response = ObjectManager::getInstance(\Weline\Framework\Http\Response::class);
    }

    public function isAjax(): bool
    {
        $serverBag = $this->getServerBag();
        
        if ($serverBag->isAjax()) {
            return true;
        }
        return isset($_GET['isAjax']) || isset($_POST['isAjax']);
    }

    public function isIframe(): bool
    {
        $serverBag = $this->getServerBag();
        
        if ($serverBag->get('HTTP_SEC_FETCH_DEST') == 'iframe') {
            return true;
        }
        if ($serverBag->get('Sec-Fetch-Dest') == 'iframe') {
            return true;
        }
        return isset($_GET['isIframe']) || isset($_POST['isIframe']);
    }
}
