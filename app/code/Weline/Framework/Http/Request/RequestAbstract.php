<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Http\Request;

use Weline\Framework\App\Debug;
use Weline\Framework\App\Env;
use Weline\Framework\App\State;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Controller\Data\DataInterface;
use Weline\Framework\Context;
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
            $queryParams = Context::current()->query();
            foreach ($query_str_arr as $item) {
                if (str_contains($item, '.')) {
                    $item = explode('=', $item);
                    if (str_contains($item[0], '.')) {
                        $queryParams[$item[0]] = $item[1] ?? '';
                        unset($queryParams[str_replace('.', '_', $item[0])]);
                    }
                }
            }
            Context::current()->set('input.query', $queryParams);
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
        $welineArea = (string)$this->getServer('WELINE_AREA');
        if ($welineArea === 'backend') {
            return '';
        }

        $server = $this->getServer();
        if (\is_array($server) && \array_key_exists('WELINE_AREA_ROUTE', $server)) {
            return (string)($server['WELINE_AREA_ROUTE'] ?? '');
        }

        $areaRoute = $this->getServer('WELINE_AREA_ROUTE');
        if ($areaRoute !== null) {
            return (string)$areaRoute;
        }

        return $this->area_router;
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
        $serverFlag = $this->getServer('WELINE_IS_BACKEND');
        if ($serverFlag !== null) {
            return (bool)$serverFlag;
        }

        $area = (string)($this->getServer('WELINE_AREA') ?? '');
        if ($area !== '') {
            return $area === 'backend' || $area === 'rest_backend';
        }

        return (bool)$this->getData('backend');
    }

    public function setApiFrontend(): static
    {
        return $this->setData('api_frontend', true);
    }

    public function isApiFrontend(): bool
    {
        $area = (string)($this->getServer('WELINE_AREA') ?? '');
        if ($area !== '') {
            return $area === 'rest_frontend';
        }

        return (bool)$this->getData('api_frontend');
    }


    public function setApiBackend(): static
    {
        return $this->setData('api_backend', true);
    }

    public function isApiBackend(): bool
    {
        $area = (string)($this->getServer('WELINE_AREA') ?? '');
        if ($area !== '') {
            return $area === 'rest_backend';
        }

        return (bool)$this->getData('api_backend');
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
        return $this->getBaseHost() . $this->normalizeBaseUrlPath((string) array_shift($url_exp));
    }

    public function getBaseUrl(): string
    {
        $uri = $this->getUri();
        $url_exp = explode('?', $uri);
        return $this->getBaseHost() . $this->normalizeBaseUrlPath((string) array_shift($url_exp));
    }

    public function getFullUrl(): string
    {
        return $this->getServer('REQUEST_SCHEME') . '://' . $this->getServer('SERVER_NAME') . $this->getServer('REQUEST_URI');
    }

    public function getBaseUri(): string
    {
        $uri = $this->getUri();
        $url_exp = explode('?', $uri);
        return $this->getBaseHost() . $this->normalizeBaseUrlPath((string) array_shift($url_exp));
    }

    private function normalizeBaseUrlPath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        return str_starts_with($path, '/') ? $path : '/' . ltrim($path, '/');
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
        // 按优先级检查各种协议来源
        $sources = [
            \w_env('request.scheme'),
            \w_env('server.https'),
            \w_env('http_x_forwarded_proto'),
            \w_env('http_weline_original_scheme'),
            \w_env('server.server_port'),
        ];

        foreach ($sources as $value) {
            if ($value === 'https' || $value === 'on' || $value === '1' || $value === '443') {
                return 'https';
            }
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

    /**
     * 净化 website_url 中的 path，避免 REST 区域前缀或跨请求路由残留进入 getBaseHost()。
     *
     * 若 path 被污染（例如 /api/CNY/zh_Hans_CN），window.url / getFrontendUrl 会在所有前台链接前带上 /api/。
     */
    protected function sanitizeWebsiteUrlPathForBaseHost(string $pathPart): string
    {
        if ($pathPart === '' || $pathPart === '/') {
            return '';
        }

        $normalizedPath = rtrim($pathPart, '/');
        if ($normalizedPath === '') {
            return '';
        }

        $segments = array_values(array_filter(explode('/', trim($normalizedPath, '/')), static function ($segment) {
            return $segment !== '';
        }));
        while ($segments !== []) {
            $segment = (string)$segments[0];
            $isCurrencySegment = State::isAllowedCurrencyCode(strtoupper($segment));
            $isLanguageSegment = (bool)preg_match('/^[a-z]{2}_[a-z0-9]+(?:_[a-z0-9]+)?$/i', $segment);
            if (!$isCurrencySegment && !$isLanguageSegment) {
                break;
            }
            array_shift($segments);
        }

        if ($segments === []) {
            return '';
        }

        $normalizedPath = '/' . implode('/', $segments);

        if (str_contains($normalizedPath, '/')) {
            $currentUri = rtrim((string)($this->getUri() ?? '/'), '/') ?: '/';
            if (!str_starts_with($currentUri, $normalizedPath)) {
                return '';
            }
        }

        $firstSegment = strtok(ltrim($normalizedPath, '/'), '/');
        if ($firstSegment !== false && $firstSegment !== '' && Env::isAreaRoutePathSegment((string)$firstSegment)) {
            return '';
        }

        return $normalizedPath;
    }

    public function getBaseHost(): string
    {
        $currentScheme = $this->getSsl();

        // 透传模式：优先信任 HTTP_HOST（客户端原始 Host）
        $currentPort = '';
        $host = \w_env('server.http_host', \w_env('server.server_name', 'localhost'));
        if (\str_contains($host, ':')) {
            $hostParts = \explode(':', $host, 2);
            $currentPort = (string)($hostParts[1] ?? '');
        }

        // WELINE_WEBSITE_URL 由 Url::parser() → processUrlParse() 写入 $_SERVER
        // 它包含 scheme://host[:port][/sub_path]
        // URL 生成时始终参考当前请求的端口（WLS 非标准端口如 9981 必须带上），避免生成错误链接
        $websiteUrl = \w_env('website_url', '');
        if ($websiteUrl !== '') {
            $parsed = \parse_url($websiteUrl);
            $hostPart = $parsed['host'] ?? 'localhost';
            $pathPart = $this->sanitizeWebsiteUrlPathForBaseHost((string)($parsed['path'] ?? ''));
            if ($currentPort === '' && isset($parsed['port'])) {
                $currentPort = (string)$parsed['port'];
            }
            if ($currentPort === '') {
                $currentPort = $currentScheme === 'https' ? '443' : '80';
            }
            $isNonStandardPort = $currentPort !== '' && !(($currentScheme === 'https' && $currentPort === '443') || ($currentScheme !== 'https' && $currentPort === '80'));

            $portSuffix = $isNonStandardPort ? ':' . $currentPort : '';
            return $currentScheme . '://' . $hostPart . $portSuffix . $pathPart;
        }
        if ($currentPort === '') {
            $currentPort = $currentScheme === 'https' ? '443' : '80';
        }
        $isNonStandardPort = $currentPort !== '' && !(($currentScheme === 'https' && $currentPort === '443') || ($currentScheme !== 'https' && $currentPort === '80'));
        
        // 优先使用 HTTP_HOST（客户端发送的 Host 头通常带端口，如 localhost:9981），保证生成的后台 URL 携带当前后端端口
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
        $host = (string) ($this->getServer('HTTP_HOST') ?: $this->getServer('SERVER_NAME') ?: 'localhost');
        $port = (string) ($this->getServer('SERVER_PORT') ?: '');
        $withPort = false;
        if (\str_contains($host, ':')) {
            $withPort = false;
        } elseif ($port !== '') {
            $withPort = !(
                ($scheme === 'http' && $port == 80) ||
                ($scheme === 'https' && $port == 443)
            );
        }
        $requestUri = (string) ($this->getServer('REQUEST_URI') ?: '');
        $scriptName = (string) ($this->getServer('SCRIPT_NAME') ?: '');
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
        if (!$this->_response instanceof Response) {
            $this->_response = new Response(true);
            ObjectManager::setInstance(\Weline\Framework\Http\Response::class, $this->_response);
        }
        return $this->_response;
    }

    public function setResponse(Response $response): static
    {
        $this->_response = $response;
        ObjectManager::setInstance(\Weline\Framework\Http\Response::class, $response);

        return $this;
    }

    public function resetResponse(): static
    {
        $this->_response = null;
        ObjectManager::removeInstance(\Weline\Framework\Http\Response::class);

        return $this;
    }

    public function isAjax(): bool
    {
        $serverBag = $this->getServerBag();
        
        if ($serverBag->isAjax()) {
            return true;
        }
        return \w_env_get('isAjax') !== null || \w_env_post('isAjax') !== null;
    }

    public function isDocumentNavigationRequest(): bool
    {
        $method = strtoupper((string)$this->getMethod());
        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }

        if ($this->isAjax() || $this->isIframe()) {
            return false;
        }

        $fetchDest = strtolower(trim((string)$this->requestHeaderValue('Sec-Fetch-Dest')));
        if ($fetchDest !== '' && $fetchDest !== 'document') {
            return false;
        }

        $fetchMode = strtolower(trim((string)$this->requestHeaderValue('Sec-Fetch-Mode')));
        if ($fetchMode !== '' && $fetchMode !== 'navigate') {
            return false;
        }

        $accept = strtolower(trim((string)$this->requestHeaderValue('Accept')));
        if ($accept === '') {
            return true;
        }
        if (str_contains($accept, 'text/html') || str_contains($accept, 'application/xhtml+xml')) {
            return true;
        }
        if ($accept === '*/*') {
            return true;
        }

        return !str_contains($accept, 'application/json')
            && !str_contains($accept, 'text/event-stream')
            && !str_contains($accept, 'application/xml')
            && !str_contains($accept, 'text/xml');
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
        return \w_env_get('isIframe') !== null || \w_env_post('isIframe') !== null;
    }

    private function requestHeaderValue(string $name): string
    {
        $serverBag = $this->getServerBag();
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $serverBag->get($serverKey, '');
        if ($value === '') {
            $value = $serverBag->get($name, '');
        }
        if ($value === '') {
            $value = $serverBag->get(str_replace('-', '_', $name), '');
        }

        return is_array($value) ? implode(',', $value) : (string)$value;
    }
}
