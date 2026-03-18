<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Server\Service;

use Weline\Framework\App\Env;

/**
 * 内存缓存规则管理器
 * 
 * 加载并解析缓存规则配置，判断请求是否应该缓存
 * 
 * @package Weline_Server
 */
class MemoryCacheRuleManager
{
    /**
     * 单例实例
     * 
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * 表达式解析器
     * 
     * @var MemoryCacheExpressionParser
     */
    private MemoryCacheExpressionParser $expressionParser;

    /**
     * 缓存规则
     * 
     * @var array
     */
    private array $rules = [];

    /**
     * 规则是否已加载
     * 
     * @var bool
     */
    private bool $rulesLoaded = false;

    /**
     * 内置绕过路径模式（始终不缓存）
     * 
     * @var array<string>
     */
    private const BYPASS_PATH_PATTERNS = [
        '^/_wls/',           // WLS 内部 API（健康检查等）
        '^/admin/',          // 后台
        '^/backend/',        // 后台
        '^/api/',            // API 接口
        '^/customer/',       // 客户账户相关
        '^/checkout/',       // 结账流程
        '^/cart/',           // 购物车
    ];

    /**
     * 内置绕过请求头（包含这些头的请求不缓存）
     * 
     * @var array<string>
     */
    private const BYPASS_HEADERS = [
        'authorization',     // 认证头
    ];

    /**
     * 默认 TTL（秒）
     * 
     * @var int
     */
    private int $defaultTtl = 3600;

    /**
     * 私有构造函数
     */
    private function __construct()
    {
        $this->expressionParser = new MemoryCacheExpressionParser();
    }

    /**
     * 获取单例实例
     * 
     * @return self
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * 判断请求是否应该缓存
     * 
     * @param string $rawRequest 原始 HTTP 请求
     * @return bool
     */
    public function shouldCache(string $rawRequest): bool
    {
        $this->loadRulesIfNeeded();
        
        // 解析请求
        $this->expressionParser->buildContextFromRequest($rawRequest);
        $context = $this->expressionParser->getContext();
        
        // 1. 检查 HTTP 方法（只缓存 GET 和 HEAD）
        $method = $context['http.request.method'] ?? 'GET';
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return false;
        }

        // 2. 检查内置绕过路径
        $uri = $context['http.request.uri.path'] ?? '/';
        foreach (self::BYPASS_PATH_PATTERNS as $pattern) {
            if (preg_match('/' . $pattern . '/', $uri)) {
                return false;
            }
        }

        // 3. 检查内置绕过请求头
        $headers = $context['http.request.headers'] ?? [];
        foreach (self::BYPASS_HEADERS as $headerName) {
            if (isset($headers[$headerName]) && !empty($headers[$headerName])) {
                return false;
            }
        }

        // 4. 检查 Cache-Control: no-cache
        $cacheControl = $headers['cache-control'] ?? '';
        if (str_contains($cacheControl, 'no-cache') || str_contains($cacheControl, 'no-store')) {
            return false;
        }

        // 5. 遍历自定义规则（按优先级排序）
        foreach ($this->rules as $rule) {
            // 跳过禁用的规则
            if (isset($rule['enabled']) && $rule['enabled'] === false) {
                continue;
            }

            $expression = $rule['expression'] ?? '';
            $action = $rule['action'] ?? 'cache';
            
            // 如果表达式匹配
            if (!empty($expression) && $this->expressionParser->evaluate($expression)) {
                // 支持简化格式（action 为字符串）和嵌套格式（action 为对象）
                if (is_string($action)) {
                    if ($action === 'bypass') {
                        return false; // 明确不缓存
                    }
                    if ($action === 'cache') {
                        return true; // 明确缓存
                    }
                } elseif (is_array($action)) {
                    // 嵌套格式
                    if (isset($action['cache'])) {
                        if ($action['cache'] === false) {
                            return false;
                        }
                        return true;
                    }
                }
            }
        }

        // 默认：缓存 GET/HEAD 请求
        return true;
    }

    /**
     * 获取请求的缓存 TTL
     * 
     * @param string $rawRequest 原始 HTTP 请求
     * @return int TTL（秒）
     */
    public function getCacheTtl(string $rawRequest): int
    {
        $this->loadRulesIfNeeded();
        
        // 解析请求
        $this->expressionParser->buildContextFromRequest($rawRequest);
        
        // 遍历规则查找匹配的 TTL
        foreach ($this->rules as $rule) {
            // 跳过禁用的规则
            if (isset($rule['enabled']) && $rule['enabled'] === false) {
                continue;
            }

            $expression = $rule['expression'] ?? '';
            $action = $rule['action'] ?? 'cache';
            
            if (!empty($expression) && $this->expressionParser->evaluate($expression)) {
                // 支持简化格式（ttl 为顶层字段）
                if (isset($rule['ttl'])) {
                    return (int) $rule['ttl'];
                }
                // 嵌套格式
                if (is_array($action) && isset($action['cache']['ttl'])) {
                    return (int) $action['cache']['ttl'];
                }
            }
        }

        return $this->defaultTtl;
    }

    /**
     * 获取请求应该响应的状态码
     * 
     * @param string $rawRequest 原始 HTTP 请求
     * @return array 允许缓存的状态码
     */
    public function getCacheableStatusCodes(string $rawRequest): array
    {
        $this->loadRulesIfNeeded();
        
        // 解析请求
        $this->expressionParser->buildContextFromRequest($rawRequest);
        
        // 遍历规则查找匹配的状态码
        foreach ($this->rules as $rule) {
            // 跳过禁用的规则
            if (isset($rule['enabled']) && $rule['enabled'] === false) {
                continue;
            }

            $expression = $rule['expression'] ?? '';
            $action = $rule['action'] ?? 'cache';
            
            if (!empty($expression) && $this->expressionParser->evaluate($expression)) {
                // 支持简化格式（cacheable_status_codes 为顶层字段）
                if (isset($rule['cacheable_status_codes'])) {
                    return (array) $rule['cacheable_status_codes'];
                }
                // 嵌套格式
                if (is_array($action) && isset($action['cache']['status_code'])) {
                    return (array) $action['cache']['status_code'];
                }
            }
        }

        // 默认只缓存 200 响应
        return [200];
    }

    /**
     * 加载规则（如果尚未加载）
     * 
     * @return void
     */
    private function loadRulesIfNeeded(): void
    {
        if ($this->rulesLoaded) {
            return;
        }

        $this->rules = $this->loadRules();
        $this->rulesLoaded = true;

        // 加载默认 TTL 配置
        $this->defaultTtl = (int)Env::get('wls.memory_cache.default_ttl', 3600);
    }

    /**
     * CDN 后台推送的规则文件路径（优先）
     */
    public const CDN_RULES_FILE = 'var/server/memory-cache-rules.json';

    /**
     * 规则更新标记文件路径
     */
    public const RULES_UPDATE_FLAG = 'var/server/rules-update.flag';

    /**
     * 上次规则文件修改时间（用于检测变化）
     * 
     * @var int
     */
    private int $lastRulesFileMtime = 0;

    /**
     * 加载规则配置
     * 
     * 优先级：
     * 1. CDN 后台推送的规则（var/server/memory-cache-rules.json）
     * 2. env.php 配置的规则文件
     * 3. 默认规则文件（etc/memory-cache-rules.json）
     * 
     * @return array
     */
    public function loadRules(): array
    {
        $rules = [];
        $rulesFile = null;
        
        // 1. 优先从 CDN 后台推送的规则文件加载
        $cdnRulesFile = Env::VAR_DIR . 'server' . DIRECTORY_SEPARATOR . 'memory-cache-rules.json';
        if (file_exists($cdnRulesFile)) {
            $rulesFile = $cdnRulesFile;
        }
        
        // 2. 尝试从 env.php 加载规则文件路径
        if ($rulesFile === null) {
            $envRulesFile = Env::get('wls.memory_cache.rules_file', '');
            if (!empty($envRulesFile)) {
                // 相对路径转绝对路径
                if (!str_starts_with($envRulesFile, '/') && !preg_match('/^[A-Za-z]:/', $envRulesFile)) {
                    $envRulesFile = BP . DIRECTORY_SEPARATOR . $envRulesFile;
                }
                if (file_exists($envRulesFile)) {
                    $rulesFile = $envRulesFile;
                }
            }
        }
        
        // 3. 回退到默认规则文件
        if ($rulesFile === null) {
            $defaultRulesFile = BP . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'code' 
                . DIRECTORY_SEPARATOR . 'Weline' . DIRECTORY_SEPARATOR . 'Server' 
                . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'memory-cache-rules.json';
            if (file_exists($defaultRulesFile)) {
                $rulesFile = $defaultRulesFile;
            }
        }

        // 4. 加载规则文件
        if ($rulesFile !== null && file_exists($rulesFile)) {
            $this->lastRulesFileMtime = filemtime($rulesFile) ?: 0;
            $content = file_get_contents($rulesFile);
            if ($content !== false) {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    // 支持新格式（包含 rules 键）和旧格式（直接数组）
                    if (isset($decoded['rules']) && is_array($decoded['rules'])) {
                        $rules = $decoded['rules'];
                    } else {
                        $rules = $decoded;
                    }
                }
            }
        }

        // 5. 按优先级排序（priority 越大越优先，类 Cloudflare）
        usort($rules, function ($a, $b) {
            $priorityA = $a['priority'] ?? 0;
            $priorityB = $b['priority'] ?? 0;
            return $priorityB <=> $priorityA; // 降序：数值大的优先
        });

        return $rules;
    }

    /**
     * 获取规则更新标记文件路径
     *
     * @return string
     */
    public static function getRulesUpdateFlagPath(): string
    {
        return Env::VAR_DIR . 'server' . DIRECTORY_SEPARATOR . 'rules-update.flag';
    }

    /**
     * 获取 CDN 后台推送的规则文件路径
     *
     * @return string
     */
    public static function getCdnRulesFilePath(): string
    {
        return Env::VAR_DIR . 'server' . DIRECTORY_SEPARATOR . 'memory-cache-rules.json';
    }

    /**
     * 获取上次规则文件修改时间
     *
     * @return int
     */
    public function getLastRulesFileMtime(): int
    {
        return $this->lastRulesFileMtime;
    }

    /**
     * 更新规则
     * 
     * @param array $rules 新规则
     * @return void
     */
    public function updateRules(array $rules): void
    {
        $this->rules = $rules;
        $this->rulesLoaded = true;

        // 按优先级排序
        usort($this->rules, function ($a, $b) {
            $priorityA = $a['priority'] ?? 100;
            $priorityB = $b['priority'] ?? 100;
            return $priorityA <=> $priorityB;
        });
    }

    /**
     * 添加规则
     * 
     * @param array $rule 规则
     * @return void
     */
    public function addRule(array $rule): void
    {
        $this->loadRulesIfNeeded();
        $this->rules[] = $rule;

        // 重新排序
        usort($this->rules, function ($a, $b) {
            $priorityA = $a['priority'] ?? 100;
            $priorityB = $b['priority'] ?? 100;
            return $priorityA <=> $priorityB;
        });
    }

    /**
     * 获取当前规则
     * 
     * @return array
     */
    public function getRules(): array
    {
        $this->loadRulesIfNeeded();
        return $this->rules;
    }

    /**
     * 设置默认 TTL
     * 
     * @param int $ttl TTL（秒）
     * @return void
     */
    public function setDefaultTtl(int $ttl): void
    {
        $this->defaultTtl = $ttl;
    }

    /**
     * 获取默认 TTL
     * 
     * @return int
     */
    public function getDefaultTtl(): int
    {
        return $this->defaultTtl;
    }

    /**
     * 重新加载规则
     * 
     * @return void
     */
    public function reload(): void
    {
        $this->rulesLoaded = false;
        $this->loadRulesIfNeeded();
    }

    /**
     * 检查请求是否匹配内置绕过规则
     * 
     * @param string $uri URI 路径
     * @return bool
     */
    public function matchesBypassPattern(string $uri): bool
    {
        foreach (self::BYPASS_PATH_PATTERNS as $pattern) {
            if (preg_match('/' . $pattern . '/', $uri)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 获取内置绕过路径模式
     * 
     * @return array
     */
    public function getBypassPathPatterns(): array
    {
        return self::BYPASS_PATH_PATTERNS;
    }

    /**
     * 判断是否启用内存缓存
     * 
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return (bool)Env::get('wls.memory_cache.enabled', false);
    }

    /**
     * 获取最大缓存大小
     * 
     * @return int
     */
    public static function getMaxSize(): int
    {
        return (int)Env::get('wls.memory_cache.max_size', 104857600); // 默认 100MB
    }
}
