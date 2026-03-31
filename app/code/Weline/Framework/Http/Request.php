<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Http;

use Weline\Framework\App\Exception;
use Weline\Framework\Http\Request\FileBag;
use Weline\Framework\Http\Request\ParameterBag;
use Weline\Framework\Http\Request\RequestFilter;
use Weline\Framework\Manager\ObjectManager;

class Request extends Request\RequestAbstract implements RequestInterface
{
    private static Request $instance;

    private string $module_name = '';
    private string $request_id = '';

    private bool $check_param = false;
    private array $path_split = [];
    
    /**
     * 多模块支持：存储请求关联的多个模块
     * @var array
     */
    private array $modules = [];

    /**
     * ParameterBag - 请求参数管理器
     * @var ParameterBag|null
     */
    protected ?ParameterBag $parameterBag = null;

    /**
     * FileBag - 上传文件管理器
     * @var FileBag|null
     */
    protected ?FileBag $fileBag = null;

    public function __init()
    {
        parent::__init();
        
        // 初始化 ParameterBag
        if ($this->parameterBag === null) {
            $this->parameterBag = new ParameterBag();
            $this->parameterBag->initFromGlobals();
        }
        
        // 初始化 FileBag
        if ($this->fileBag === null) {
            $this->fileBag = new FileBag();
            $this->fileBag->initFromGlobals();
        }
        
        // 将 GET+POST+Body 合并写入 _data，供 getParam/getPost/getGet 优先读取（与 WLS parseRawHttp 行为一致）
        $params = $this->getParams();
        $body = $this->getBodyParams();
        $this->setData(\is_array($body) ? \array_merge($params, $body) : $params);
    }
    
    /**
     * 获取 ParameterBag 实例
     * 
     * @return ParameterBag
     */
    public function getParameterBag(): ParameterBag
    {
        if ($this->parameterBag === null) {
            $this->parameterBag = new ParameterBag();
            $this->parameterBag->initFromGlobals();
        }
        return $this->parameterBag;
    }
    
    /**
     * 重置 ParameterBag 实例（WLS 模式下需要在每个请求开始时调用）
     * 
     * @return static
     */
    public function resetParameterBag(): static
    {
        if ($this->parameterBag !== null) {
            $this->parameterBag->reset();
        }
        $this->parameterBag = null;
        return $this;
    }
    
    /**
     * 获取 FileBag 实例
     * 
     * @return FileBag
     */
    public function getFileBag(): FileBag
    {
        if ($this->fileBag === null) {
            $this->fileBag = new FileBag();
            $this->fileBag->initFromGlobals();
        }
        return $this->fileBag;
    }

    function getId()
    {
        if (!$this->request_id) {
            $this->request_id = uniqid('request_id::', true);
        }
        return $this->request_id;
    }

    /**
     * @DESC          # 方法描述
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/12/23 23:11
     * 参数区：
     *
     * @param bool $check_param
     *
     * @return bool
     */
    public function checkParam(bool $check_param = true): bool
    {
        $this->check_param = $check_param;
        return $this->check_param;
    }

    static array $url_paths = [];

    /**
     * WLS：清空静态 URL 路径缓存。须在每请求早期调用，避免上一请求或 parser 前的 getUrlPath
     * 把错误 path 留在 static 中，导致 ACL 用错路由而 302 到 admin。
     */
    public static function clearStaticUrlPathCache(): void
    {
        self::$url_paths = [];
    }

    public function getUrlPath(string $url = ''): string
    {
        if ($url) {
            if (isset(self::$url_paths[$url])) {
                return self::$url_paths[$url];
            }
            self::$url_paths[$url] = parse_url($url)['path'] ?? '';
            return self::$url_paths[$url];
        }
        $url = $this->getUri();
        if (isset(self::$url_paths[$url])) {
            return self::$url_paths[$url];
        }
        self::$url_paths[$url] = $this->parse_url()['path'] ?? '';
        return self::$url_paths[$url];
    }

    public function getRouteUrlPath(string $url = ''): string
    {
        $path = $this->getUrlPath($url);
        if (!$this->isBackend() and !$this->isApiBackend()) {
            return $path;
        }
        $areaRouter = $this->getAreaRouter();
        if ($areaRouter !== '' && $areaRouter !== null) {
            $path = str_replace($areaRouter . '/', '', $path);
        }
        return ltrim($path, '/');
    }

    /**
     * @return string
     */
    public function getModulePath(): string
    {
        return (string)($this->getRouterData('module_path') ?? '');
    }

    public function getHeader(string $key = ''): array|string|null
    {
        if (empty($key)) {
            return $this->getServer(self::HEADER);
        }

        return $this->getServer('HTTP_' . strtoupper($key));
    }

    /**
     * 按 key 获取请求参数（GET/POST/Body 合并）
     *
     * 显式定义以覆盖 DataObject::__call('get', ...) 的歧义行为：
     * __call 会将 get('key') 解析为 getData('') 从而返回整个 _data 数组。
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->getParam($key, $default ?? '');
    }

    /**
     * 按 key 获取 POST 参数的便捷方法
     */
    public function post(string $key = '', mixed $default = null): mixed
    {
        return $this->getPost($key, $default);
    }

    /**
     * 按 key 获取 Body 参数的便捷方法
     */
    public function body(string $key = '', mixed $default = null): mixed
    {
        if ($key === '') {
            return $this->getBodyParams();
        }
        return $this->getBodyParam($key, $default);
    }

    public function getParam(string $key, mixed $default = '', string $filter = '')
    {
        $filterType = $filter ?: gettype($default);

        // 1) 优先从请求对象的 data（WLS parseRawHttp 阶段写入的 GET+POST 合并数据）
        $result = $this->getData($key);
        if ($result !== null) {
            return RequestFilter::filter($filterType, $result);
        }

        // 2) ParameterBag：body > POST > GET，兼容 FPM 和 WLS
        $data = $this->getParameterBag()->get($key, null);
        if ($data !== null) {
            return RequestFilter::filter($filterType, $data);
        }

        // 3) 回退到默认值
        $data = $default;
        return $this->checkResult($key, $data);
    }

    public function getParams()
    {
        if ($params = $this->getData('params')) {
            return $params;
        }
        
        // 使用 ParameterBag 获取所有参数
        $params = $this->getParameterBag()->all();
        
        $this->setData('params', $params);
        return $params;
    }

    public function setGet(string $key, mixed $value): static
    {
        $this->getParameterBag()->setQuery($key, $value);
        $this->setData($key, $value);
        return $this;
    }

    public function setPost(string $key, mixed $value): static
    {
        $this->getParameterBag()->setRequest($key, $value);
        $this->setData($key, $value);
        return $this;
    }

    public function getBodyParam($key, mixed $default = null)
    {
        $result = $this->getParameterBag()->getBody($key, $default);
        return $this->checkResult($key, $result);
    }

    public function getBodyParams(bool $array = false)
    {
        $body_params_key = $array ? 'array_body_params' : 'body_params';
        if ($params = $this->getData($body_params_key)) {
            return $params;
        }
        
        // 使用 ParameterBag 获取 Body 参数
        $params = $this->getParameterBag()->getBody();
        
        // 如果不需要数组格式且解析后的参数为空，返回原始请求体字符串
        // 使用 ParameterBag::getRawBody() 统一获取（FPM 从 php://input，WLS 从注入的 rawBody）
        if (!$array && empty($params)) {
            $params = $this->getParameterBag()->getRawBody();
        }
        
        $this->setData($body_params_key, $params);
        return $params;
    }

    /**
     * 按 key 获取 POST/Body 参数（与 getParam 一致：优先从请求 _data 取，再回退到 ParameterBag）
     * 确保 WLS parseRawHttp 写入的合并数据、以及 FPM 下 __init 合并的 GET+POST+Body 可被正确读取。
     */
    public function getPost(string $key = '', mixed $default = null)
    {
        if ('' === $key) {
            return $this->getParameterBag()->getRequest();
        }
        // 1) 优先从请求 _data 取（WLS parseRawHttp / FPM __init 合并数据）
        $result = $this->getData($key);
        if ($result !== null) {
            if ($default !== null) {
                $result = $this->getDefaultTypeData($result, $default);
            }
            return $this->checkResult($key, $result);
        }
        // 2) 回退到 ParameterBag（POST + Body）
        $result = $this->getParameterBag()->getRequest($key, $default);
        if ($default !== null) {
            $result = $this->getDefaultTypeData($result, $default);
        }
        return $this->checkResult($key, $result);
    }

    public function getQuery(string $key = '', mixed $default = null)
    {
        return $this->getGet($key, $default);
    }

    /**
     * 按 key 获取 GET 参数（与 getParam 一致：优先从请求 _data 取，再回退到 ParameterBag）
     */
    public function getGet(string $key = '', mixed $default = null)
    {
        if ('' === $key) {
            return $this->getParameterBag()->getQuery();
        }
        // 1) 优先从请求 _data 取（WLS parseRawHttp / FPM __init 合并数据）
        $result = $this->getData($key);
        if ($result !== null) {
            if ($default !== null) {
                $result = $this->getDefaultTypeData($result, $default);
            }
            return $this->checkResult($key, $result);
        }
        // 2) 回退到 ParameterBag（GET）
        $result = $this->getParameterBag()->getQuery($key, $default);
        if ($default !== null) {
            $result = $this->getDefaultTypeData($result, $default);
        }
        return $this->checkResult($key, $result);
    }

    public function getGetByPre(string $pre_key, bool $filter_value = false): array
    {
        return $this->getParameterBag()->getQueryByPrefix($pre_key, $filter_value);
    }

    public function setGetByPre(string $pre_key, array $data = []): self
    {
        $this->getParameterBag()->removeQueryByPrefix($pre_key);
        $this->getParameterBag()->setQueryByPrefix($pre_key, $data);
        return $this;
    }

    public function unsetGetByPrekey(string $pre_key): self
    {
        $this->getParameterBag()->removeQueryByPrefix($pre_key);
        return $this;
    }

    public function hasGet(string $key): bool
    {
        return $this->getParameterBag()->hasQuery($key);
    }

    private function checkResult(string $key, mixed &$result): mixed
    {
        if ($this->check_param) {
            if ($result === null) {
                if (PROD) {
                    $this->getResponse()->redirect(404);
                }
                throw new Exception(__('未提供参数：%{1}', $key));
            }
        }
        return $result;
    }

    public function isPost(): bool
    {
        return $this->getMethod() === self::POST;
    }

    public function getFile(string $key = '')
    {
        return $this->getFileBag()->get($key);
    }

    public function isGet(bool $set_get = false): bool
    {
        if ($set_get) {
            $this->setServer('REQUEST_METHOD', self::GET);
        }
        return $this->getMethod() === self::GET;
    }

    public function isPut(): bool
    {
        return $this->getMethod() === self::PUT;
    }

    public function isDelete(): bool
    {
        return $this->getMethod() === self::DELETE;
    }

    public function getContentType(): string
    {
        return $this->getServer('CONTENT_TYPE');
    }

    public function getReferer(): string
    {
        return $this->getServer('HTTP_REFERER');
    }

    public function getAuth(string $auth_type = 'bearer')
    {
        $serverBag = $this->getServerBag();
        
        switch ($auth_type) {
            case self::auth_TYPE_BEARER:
                return $serverBag->getBearerToken() ?? '';
            case self::auth_TYPE_BASIC_AUTH:
                $basicAuth = $serverBag->getBasicAuth();
                return $basicAuth ? ['USER' => $basicAuth['user'], 'PW' => $basicAuth['password']] : ['USER' => '', 'PW' => ''];
            default:
                return null;
        }
    }

    public function getApiKey(string $key): string
    {
        $header = $this->getHeader($key);
        return \is_string($header) ? $header : '';
    }

    public function getModuleName(): string
    {
        if ($module_name = parent::getModuleName()) {
            return $module_name;
        } else {
            return $this->getRouter()['module'] ?? '';
        }
    }

    /**
     * 添加模块到请求关联的模块列表
     * 
     * @param string $module_name 模块名（如 Weline_I18n 或 I18n）
     * @return static
     */
    public function addModule(string $module_name): static
    {
        if (!empty($module_name) && !in_array($module_name, $this->modules, true)) {
            $this->modules[] = $module_name;
        }
        return $this;
    }

    /**
     * 批量添加模块
     * 
     * @param array $modules 模块名数组
     * @return static
     */
    public function addModules(array $modules): static
    {
        foreach ($modules as $module_name) {
            $this->addModule($module_name);
        }
        return $this;
    }

    /**
     * 设置请求关联的模块列表（替换现有模块）
     * 
     * @param array $modules 模块名数组
     * @return static
     */
    public function setModules(array $modules): static
    {
        $this->modules = [];
        foreach ($modules as $module_name) {
            $this->addModule($module_name);
        }
        return $this;
    }

    /**
     * 获取请求关联的所有模块
     * 
     * @return array 模块名数组
     */
    public function getModules(): array
    {
        // 如果 modules 为空，返回包含当前模块的数组（如果有）
        if (empty($this->modules)) {
            $current_module = $this->getModuleName();
            if (!empty($current_module)) {
                return [$current_module];
            }
            return [];
        }
        return $this->modules;
    }

    /**
     * 检查模块是否在请求关联的模块列表中
     * 
     * @param string $module_name 模块名
     * @return bool
     */
    public function hasModule(string $module_name): bool
    {
        return in_array($module_name, $this->modules, true);
    }

    /**
     * 从请求关联的模块列表中移除模块
     * 
     * @param string $module_name 模块名
     * @return static
     */
    public function removeModule(string $module_name): static
    {
        $key = array_search($module_name, $this->modules, true);
        if ($key !== false) {
            unset($this->modules[$key]);
            $this->modules = array_values($this->modules); // 重新索引
        }
        return $this;
    }

    /**
     * 清空请求关联的模块列表
     * 
     * @return static
     */
    public function clearModules(): static
    {
        $this->modules = [];
        return $this;
    }

    public function clientIP()
    {
        return $this->getServerBag()->getClientIp();
    }

    /**
     * @DESC          # 设置规则数据
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/4/28 19:30
     * 参数区：
     *
     * @param string|array $key
     * @param mixed $value
     *
     * @return $this
     */
    public function setRule(string|array $key, mixed $value = null): static
    {
        if (is_array($key)) {
            $this->setData('rule', $key);
        } else {
            $rule = $this->getRule();
            $rule[$key] = $value;
            $this->setData('rule', $rule);
        }
        return $this;
    }

    /**
     * @DESC          # 获取规则数据
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/4/28 19:30
     * 参数区：
     *
     * @param string $key
     *
     * @return array|null|string
     */
    public function getRule(string $key = ''): array|null|string
    {
        if ($key) {
            return $this->getData('rule')[$key] ?? null;
        } else {
            return $this->getData('rule');
        }
    }

    /**
     * @DESC          # 获取Url构建器
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/9/21 22:15
     * 参数区：
     * @return Url
     * @throws \ReflectionException
     * @throws \Weline\Framework\App\Exception
     */
    public function getUrlBuilder(): Url
    {
        return ObjectManager::getInstance(Url::class);
    }

    /**
     * @DESC          # 获取默认值类型的数据
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/10/7 12:12
     * 参数区：
     *
     * @param mixed $data
     * @param mixed $default
     *
     * @return array|bool|int|mixed|string
     */
    /**
     * 按 default 类型转换 data；当 default 为 array 且取到的是字符串时，尝试 JSON 解析（兼容 JSON body / FormData 里 JSON 字符串）。
     */
    public function getDefaultTypeData(mixed $data, mixed $default): mixed
    {
        if (is_bool($default)) {
            $data = (bool)$data;
        } elseif (is_string($default)) {
            $data = (string)$data;
        } elseif (is_array($default)) {
            if (\is_string($data) && $data !== '') {
                $decoded = \json_decode($data, true);
                $data = \is_array($decoded) ? $decoded : (array)$data;
            } else {
                $data = (array)$data;
            }
        } elseif (is_int($default) || is_integer($default)) {
            $data = (int)$data;
        } elseif (is_float($default) || is_double($default)) {
            $data = (float)$data;
        }
        return $data;
    }

    public function getUserIpAddress()
    {
        return $this->getServerBag()->getClientIp();
    }

    public function getPathSplit(): array
    {
        if ($this->path_split) {
            return $this->path_split;
        }
        $this->path_split = explode('/', $this->getUrlPath());
        return $this->path_split;
    }
}
