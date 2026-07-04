<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Http\Request;

use Weline\Framework\Env\WelineEnv;

/**
 * ParameterBag - 请求参数管理类
 * 
 * 封装 GET/POST/Body 参数的访问，遵循单一职责原则。
 * 提供统一的参数访问接口，避免直接访问超全局变量。
 * 
 * @since PHP 8.4
 */
class ParameterBag
{
    /**
     * GET 参数存储
     */
    private array $query = [];
    
    /**
     * POST 参数存储
     */
    private array $request = [];
    
    /**
     * Body 参数存储（JSON/表单）
     */
    private array $body = [];
    
    /**
     * 原始请求体字符串
     * FPM 模式：从 php://input 读取
     * WLS 模式：由 WlsRequest 直接注入
     */
    private string $rawBody = '';
    
    /**
     * 合并后的参数缓存
     */
    private ?array $allParams = null;
    
    /**
     * 是否已初始化
     */
    private bool $initialized = false;
    
    /**
     * 构造函数
     * 
     * @param array $query GET 参数（可选，默认从 $_GET 获取）
     * @param array $request POST 参数（可选，默认从 $_POST 获取）
     * @param array $body Body 参数（可选，默认从 php://input 获取）
     */
    public function __construct(
        array $query = [],
        array $request = [],
        array $body = []
    ) {
        $this->query = $query;
        $this->request = $request;
        $this->body = $body;
    }
    
    /**
     * 从超全局变量初始化
     * 
     * @return static
     */
    public function initFromGlobals(): static
    {
        if ($this->initialized) {
            return $this;
        }
        
        $this->query = WelineEnv::getGet() ?? [];
        $this->request = WelineEnv::getPost() ?? [];
        $this->body = $this->parseBodyParams();
        $this->initialized = true;
        $this->allParams = null; // 清除缓存
        
        return $this;
    }
    
    /**
     * 解析 Body 参数（与 WlsRequest 支持的协议对齐，FPM 下 php://input）
     *
     * 支持：application/json、text/json、+json、application/x-www-form-urlencoded、
     * multipart/form-data（由 PHP 填 $_POST，body 可不解析）、text/plain、空或未知类型（智能嗅探）。
     *
     * @return array
     */
    private function parseBodyParams(): array
    {
        $rawBody = $this->rawBody;
        if ($rawBody === '') {
            $rawBody = (string)\w_env('request.body', '');
            if ($rawBody === '') {
                $rawBody = file_get_contents('php://input') ?: '';
            }
            $this->rawBody = $rawBody;
        }
        if ($rawBody === '') {
            return [];
        }

        $contentType = \w_env('server.content_type', '');
        $ct = \strtolower(\trim((string) \explode(';', $contentType, 2)[0]));

        // ── JSON 系列：application/json, text/json, *+json ──
        if ($ct === 'application/json' || $ct === 'text/json' || \str_ends_with($ct, '+json')) {
            $decoded = \json_decode($rawBody, true);
            return \is_array($decoded) ? $decoded : [];
        }

        // ── URL-encoded ──
        if ($ct === 'application/x-www-form-urlencoded') {
            \parse_str($rawBody, $params);
            return $params;
        }

        // ── multipart/form-data：PHP 已填 $_POST，body 不再解析 ──
        if ($ct === 'multipart/form-data') {
            return [];
        }

        // ── text/plain、空、未知类型：智能嗅探 JSON → URL-encoded → 通用 & 解析 ──
        return $this->parseBodyParamsFallback($rawBody);
    }

    /**
     * 未知/未声明 Content-Type 时的兜底解析（与 WlsRequest::parsePlainText 逻辑一致）
     */
    private function parseBodyParamsFallback(string $rawBody): array
    {
        $trimmed = \ltrim($rawBody);
        if (($trimmed[0] ?? '') === '{' || ($trimmed[0] ?? '') === '[') {
            $data = \json_decode($rawBody, true);
            if (\is_array($data)) {
                return $data;
            }
        }
        if (\str_contains($rawBody, '=') && !\str_contains($rawBody, "\n")) {
            \parse_str($rawBody, $params);
            if ($params !== []) {
                return $params;
            }
        }
        $params = [];
        foreach (\explode('&', $rawBody) as $pair) {
            $parts = \explode('=', $pair, 2);
            if (\count($parts) === 2) {
                $key = \urldecode($parts[0]);
                $value = \urldecode($parts[1]);
                if (\str_ends_with($key, '[]')) {
                    $key = \rtrim($key, '[]');
                    $params[$key][] = $value;
                } else {
                    $params[$key] = $value;
                }
            }
        }
        return $params;
    }
    
    // ==================== GET 参数操作 ====================
    
    /**
     * 获取 GET 参数
     * 
     * @param string $key 参数名，空字符串返回所有
     * @param mixed $default 默认值
     * @return mixed
     */
    public function getQuery(string $key = '', mixed $default = null): mixed
    {
        if ($key === '') {
            return $this->query;
        }
        return $this->query[$key] ?? $default;
    }
    
    /**
     * 设置 GET 参数
     * 
     * @param string $key 参数名
     * @param mixed $value 参数值
     * @return static
     */
    public function setQuery(string $key, mixed $value): static
    {
        $this->query[$key] = $value;
        WelineEnv::setGet($key, $value);
        $this->allParams = null;
        return $this;
    }
    
    /**
     * 检查 GET 参数是否存在
     * 
     * @param string $key 参数名
     * @return bool
     */
    public function hasQuery(string $key): bool
    {
        return isset($this->query[$key]);
    }
    
    /**
     * 删除 GET 参数
     * 
     * @param string $key 参数名
     * @return static
     */
    public function removeQuery(string $key): static
    {
        unset($this->query[$key]);
        \w_env_set("get.{$key}", null);
        $this->allParams = null;
        return $this;
        unset($this->query[$key]);
        \w_env_set("get.{$key}", null); // 同步清除 WelineEnv
        $this->allParams = null;
        return $this;
    }
    
    /**
     * 按前缀获取 GET 参数
     * 
     * @param string $prefix 前缀
     * @param bool $filterEmpty 是否过滤空值
     * @return array
     */
    public function getQueryByPrefix(string $prefix, bool $filterEmpty = false): array
    {
        $result = [];
        foreach ($this->query as $key => $value) {
            if ($filterEmpty && empty($value)) {
                continue;
            }
            if (str_starts_with($key, $prefix)) {
                $newKey = substr($key, strlen($prefix));
                $result[$newKey] = $value;
            }
        }
        return $result;
    }
    
    /**
     * 按前缀设置 GET 参数
     * 
     * @param string $prefix 前缀
     * @param array $data 参数数据
     * @return static
     */
    public function setQueryByPrefix(string $prefix, array $data): static
    {
        foreach (array_keys($this->query) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->query[$key]);
                \w_env_set("get.{$key}", null);
            }
        }
        foreach ($data as $key => $value) {
            $fullKey = $prefix . $key;
            $this->query[$fullKey] = $value;
            \w_env_set("get.{$fullKey}", $value);
        }
        $this->allParams = null;
        return $this;
        // 先删除所有带该前缀的参数
        foreach (array_keys($this->query) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->query[$key]);
                \w_env_set("get.{$key}", null);
            }
        }
        // 添加新参数
        foreach ($data as $key => $value) {
            $fullKey = $prefix . $key;
            $this->query[$fullKey] = $value;
            \w_env_set("get.{$fullKey}", $value);
        }
        $this->allParams = null;
        return $this;
    }
    
    /**
     * 按前缀删除 GET 参数
     * 
     * @param string $prefix 前缀
     * @return static
     */
    public function removeQueryByPrefix(string $prefix): static
    {
        foreach (array_keys($this->query) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->query[$key]);
                \w_env_set("get.{$key}", null);
            }
        }
        $this->allParams = null;
        return $this;
        foreach (array_keys($this->query) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->query[$key]);
                \w_env_set("get.{$key}", null);
            }
        }
        $this->allParams = null;
        return $this;
    }
    
    // ==================== POST 参数操作 ====================
    
    /**
     * 获取 POST 参数
     * 
     * 当 POST（$_POST / parsedPostParams）中找不到指定 key 时，
     * 自动回退到 Body 参数（JSON / 其他格式的请求体解析结果）。
     * 这确保了 WLS 模式下 JSON body 的数据能通过 getPost() 正常读取，
     * 与 FPM 模式下 multipart/form-data 的行为一致。
     * 
     * @param string $key 参数名，空字符串返回所有（POST + Body 合并）
     * @param mixed $default 默认值
     * @return mixed
     */
    public function getRequest(string $key = '', mixed $default = null): mixed
    {
        if ($key === '') {
            // 返回 POST 与 Body 的合并结果（POST 优先）
            return $this->request !== [] ? \array_merge($this->body, $this->request) : $this->body;
        }
        return $this->request[$key] ?? $this->body[$key] ?? $default;
    }
    
    /**
     * 设置 POST 参数
     * 
     * @param string $key 参数名
     * @param mixed $value 参数值
     * @return static
     */
    public function setRequest(string $key, mixed $value): static
    {
        $this->request[$key] = $value;
        \w_env_set("post.{$key}", $value);
        $this->allParams = null;
        return $this;
        $this->request[$key] = $value;
        \w_env_set("post.{$key}", $value); // 同步到 WelineEnv 支持 Fiber 隔离
        $this->allParams = null;
        return $this;
    }
    
    /**
     * 检查 POST 参数是否存在（含 Body 回退）
     * 
     * @param string $key 参数名
     * @return bool
     */
    public function hasRequest(string $key): bool
    {
        return isset($this->request[$key]) || isset($this->body[$key]);
    }
    
    // ==================== Body 参数操作 ====================
    
    /**
     * 获取 Body 参数
     * 
     * @param string $key 参数名，空字符串返回所有
     * @param mixed $default 默认值
     * @return mixed
     */
    public function getBody(string $key = '', mixed $default = null): mixed
    {
        if ($key === '') {
            return $this->body;
        }
        return $this->body[$key] ?? $default;
    }
    
    /**
     * 检查 Body 参数是否存在
     * 
     * @param string $key 参数名
     * @return bool
     */
    public function hasBody(string $key): bool
    {
        return isset($this->body[$key]);
    }
    
    /**
     * 设置 Body 参数（WLS 模式下由 WlsRequest 直接注入已解析的数据）
     * 
     * @param array $body 已解析的 Body 参数
     * @return static
     */
    public function setBody(array $body): static
    {
        $this->body = $body;
        $this->allParams = null;
        return $this;
    }
    
    /**
     * 设置原始请求体字符串
     * WLS 模式下由 WlsRequest 注入，替代 php://input
     * 
     * @param string $rawBody 原始请求体
     * @return static
     */
    public function setRawBody(string $rawBody): static
    {
        $this->rawBody = $rawBody;
        return $this;
    }
    
    /**
     * 获取原始请求体字符串
     * 优先返回已注入的 rawBody（WLS 模式），回退到 php://input（FPM 模式）
     * 
     * @return string
     */
    public function getRawBody(): string
    {
        if ($this->rawBody !== '') {
            return $this->rawBody;
        }
        // FPM 模式回退
        $this->rawBody = file_get_contents('php://input') ?: '';
        return $this->rawBody;
    }
    
    // ==================== 合并参数操作 ====================
    
    /**
     * 获取所有参数（合并 GET/POST/Body）
     * 
     * @return array
     */
    public function all(): array
    {
        if ($this->allParams !== null) {
            return $this->allParams;
        }
        
        // 合并顺序：GET < POST < Body（后者覆盖前者）
        $this->allParams = array_merge($this->query, $this->request, $this->body);
        return $this->allParams;
    }
    
    /**
     * 获取单个参数（从所有来源查找）
     * 
     * @param string $key 参数名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        // 优先级：Body > POST > GET
        return $this->body[$key] 
            ?? $this->request[$key] 
            ?? $this->query[$key] 
            ?? $default;
    }
    
    /**
     * 检查参数是否存在（从所有来源查找）
     * 
     * @param string $key 参数名
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($this->body[$key]) 
            || isset($this->request[$key]) 
            || isset($this->query[$key]);
    }
    
    // ==================== 类型转换辅助 ====================
    
    /**
     * 获取整数参数
     * 
     * @param string $key 参数名
     * @param int $default 默认值
     * @return int
     */
    public function getInt(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }
    
    /**
     * 获取浮点数参数
     * 
     * @param string $key 参数名
     * @param float $default 默认值
     * @return float
     */
    public function getFloat(string $key, float $default = 0.0): float
    {
        return (float) $this->get($key, $default);
    }
    
    /**
     * 获取布尔参数
     * 
     * @param string $key 参数名
     * @param bool $default 默认值
     * @return bool
     */
    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
        }
        return (bool) $value;
    }
    
    /**
     * 获取字符串参数
     * 
     * @param string $key 参数名
     * @param string $default 默认值
     * @return string
     */
    public function getString(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);
        return is_string($value) ? $value : (string) $value;
    }
    
    /**
     * 获取数组参数
     * 
     * @param string $key 参数名
     * @param array $default 默认值
     * @return array
     */
    public function getArray(string $key, array $default = []): array
    {
        $value = $this->get($key, $default);
        return is_array($value) ? $value : $default;
    }
    
    // ==================== 重置和清理 ====================
    
    /**
     * 重置所有参数
     * 
     * @return static
     */
    public function reset(): static
    {
        $this->query = [];
        $this->request = [];
        $this->body = [];
        $this->rawBody = '';
        $this->allParams = null;
        $this->initialized = false;
        return $this;
    }
    
    /**
     * 替换所有参数
     * 
     * @param array $query GET 参数
     * @param array $request POST 参数
     * @param array $body Body 参数
     * @return static
     */
    public function replace(array $query = [], array $request = [], array $body = []): static
    {
        $this->query = $query;
        $this->request = $request;
        $this->body = $body;
        $this->allParams = null;
        return $this;
    }
}
