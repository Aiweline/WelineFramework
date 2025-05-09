<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Http;

use Weline\Framework\App\Exception;
use Weline\Framework\Http\Request\RequestFilter;
use Weline\Framework\Manager\ObjectManager;

class Request extends Request\RequestAbstract implements RequestInterface
{
    private static Request $instance;

    private string $module_name = '';
    private string $request_id = '';

    private bool $check_param = false;
    private array $path_split = [];

    public function __init()
    {
        parent::__init();
        if (is_array($this->getBodyParams())) {
            $this->setData(array_merge($this->getParams(), $this->getBodyParams()));
        }
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
        $path = str_replace($this->getAreaRouter() . '/', '', $path);
        return ltrim($path, '//');
    }

    /**
     * @return string
     */
    public function getModulePath(): string
    {
        return $this->getRouterData('module_path');
    }

    public function getHeader(string $key = ''): array|string|null
    {
        if (empty($key)) {
            return $this->getServer(self::HEADER);
        }

        return $this->getServer('HTTP_' . strtoupper($key));
    }

    public function getParam(string $key, mixed $default = '', string $filter = '')
    {
        if ($result = $this->getData($key)) {
            return $result;
        }
        parse_str($this->getServer('QUERY_STRING'), $params);
        array_shift($params);
        $params = array_merge($params, $_POST);
        $params = array_merge($params, $_GET);
        $data = $params[$key] ?? $default;
        # 如果设置了过滤器，则进行过滤，否则直接使用默认值的类型进行过滤
        if ($filter) {
            $data = RequestFilter::filter($filter, $data);
        } else {
            $data = RequestFilter::filter(gettype($default), $data);
        }
        return $this->checkResult($key, $data);
    }

    public function getParams()
    {
        if ($params = $this->getData('params')) {
            return $params;
        }
        parse_str($this->getServer('QUERY_STRING'), $params);
        array_shift($params);
        $params = array_merge($params, $_POST);
        $params = array_merge($params, $_GET);
        $this->setData('params', $params);
        return $params;
    }

    public function setGet(string $key, mixed $value): static
    {
        $_GET[$key] = $value;
        $this->setData($key, $value);
        return $this;
    }

    public function setPost(string $key, mixed $value): static
    {
        $_POST[$key] = $value;
        $this->setData($key, $value);
        return $this;
    }

    public function getBodyParam($key, mixed $default = null)
    {
        $params = $this->getBodyParams(true);
        $result = $params[$key] ?? $default;
        return $this->checkResult($key, $result);
    }

    public function getBodyParams(bool $array = false)
    {
        $body_params_key = $array ? 'array_body_params' : 'body_params';
        if ($params = $this->getData($body_params_key)) {
            return $params;
        }
        $params = file_get_contents('php://input');
        if (is_int(strpos($this->getContentType(), self::CONTENT_TYPE['json']))) {
            $params = json_decode($params, true);
        }
        if ($array && is_string($params)) {
            $params_ = [];
            foreach (explode('&', $params) as $key => $value) {
                $value = explode('=', $value);
                if (count($value) === 2) {
                    if (str_ends_with($value[0], '%5B%5D')) {
                        $paramName = rtrim($value[0], '%5B%5D');
                        $params_[$paramName][] = $value[1] ?? '';
                    } else {
                        $params_[$value[0]] = $value[1] ?? '';
                    }
                }
            }
            $params = $params_;
        }
        $this->setData($body_params_key, $params);
        return $params;
    }

    public function getPost(string $key = '', mixed $default = null)
    {
        if ('' === $key) {
            return $_POST;
        }
        $result = $_POST[$key] ?? $default;
        if ($default) {
            $result = $this->getDefaultTypeData($result, $default);
        }

        return $this->checkResult($key, $result);
    }

    public function getQuery(string $key = '', mixed $default = null)
    {
        return $this->getGet($key, $default);
    }

    public function getGet(string $key = '', mixed $default = null)
    {
        if ('' === $key) {
            return $_GET;
        }
        $result = $_GET[$key] ?? $default;
        if ($default) {
            $result = $this->getDefaultTypeData($result, $default);
        }
        return $this->checkResult($key, $result);
    }

    public function getGetByPre(string $pre_key, bool $filter_value = false): array
    {
        $data = [];
        foreach ($_GET as $key_ => $item) {
            if ($filter_value and empty($item)) {
                continue;
            }
            if (str_starts_with($key_, $pre_key)) {
                $key_ = str_replace($pre_key, '', $key_);
                $data[$key_] = $item;
            }
        }
        return $data;
    }

    public function setGetByPre(string $pre_key, array $data = []): self
    {
        foreach ($_GET as $g_key => $item) {
            if (str_starts_with($g_key, $pre_key)) {
                unset($_GET[$g_key]);
            }
        }
        foreach ($data as $key => $value) {
            $_GET[$pre_key . $key] = $value;
        }
        return $this;
    }

    public function unsetGetByPrekey(string $pre_key): self
    {
        foreach ($_GET as $g_key => $item) {
            if (str_starts_with($g_key, $pre_key)) {
                unset($_GET[$g_key]);
            }
        }
        return $this;
    }

    public function hasGet(string $key): bool
    {
        return isset($_GET[$key]);
    }

    private function checkResult(string $key, mixed &$result): mixed
    {
        if ($this->check_param) {
            if ($result === null) {
                if (PROD) {
                    $this->_response->redirect(404);
                }
                throw new Exception(__('未提供参数：%1', $key));
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
        if ($key) {
            return $_FILES[$key] ?? null;
        }
        return $_FILES;
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
        switch ($auth_type) {
            case self::auth_TYPE_BEARER:
                return str_replace('Bearer ', '', $this->getHeader('Authorization'));
            case self::auth_TYPE_BASIC_AUTH:
                return ['USER' => $this->getServer('PHP_AUTH_USER'), 'PW' => $this->getServer('PHP_AUTH_PW')];
            default:
                return null;
        }
    }

    public function getApiKey(string $key): string
    {
        return $this->getHeader($key);
    }

    public function getModuleName(): string
    {
        if ($module_name = parent::getModuleName()) {
            return $module_name;
        } else {
            return $this->getRouter()['module'] ?? '';
        }
    }

    public function clientIP()
    {
        if (isset($_SERVER)) {
            if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $realip = $_SERVER['HTTP_X_FORWARDED_FOR'];
            } elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
                $realip = $_SERVER['HTTP_CLIENT_IP'];
            } else {
                $realip = $_SERVER['REMOTE_ADDR'];
            }
        } else {
            if (getenv('HTTP_X_FORWARDED_FOR')) {
                $realip = getenv('HTTP_X_FORWARDED_FOR');
            } elseif (getenv('HTTP_CLIENT_IP')) {
                $realip = getenv('HTTP_CLIENT_IP');
            } else {
                $realip = getenv('REMOTE_ADDR');
            }
        }
        return $realip;
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
    public function getDefaultTypeData(mixed $data, mixed $default): mixed
    {
        if (is_bool($default)) {
            $data = (bool)$data;
        } elseif (is_string($default)) {
            $data = (string)$data;
        } elseif (is_array($default)) {
            $data = (array)$data;
        } elseif (is_int($default) || is_integer($default)) {
            $data = (int)$data;
        } elseif (is_float($default) || is_double($default)) {
            $data = (float)$data;
        }
        return $data;
    }

    public function getUserIpAddress()
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            //来自共享网络的IP
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // 来自代理网络的IP
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return $ip;
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
