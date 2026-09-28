<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Adapter;

use Weline\Cdn\Api\AdapterInterface;
use Weline\Cdn\Api\OauthCapableProviderInterface;
use Weline\Cdn\Service\CloudflareOAuthService;
use Weline\Framework\Exception\Core;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;

/**
 * Cloudflare CDN适配器
 *
 * 实现 Cloudflare v4 API 的缓存清理和规则管理功能；
 * 一键 OAuth 授权逻辑由本 Provider 自行适配（委托 CloudflareOAuthService）。
 */
class Cloudflare implements AdapterInterface, OauthCapableProviderInterface
{
    /**
     * Cloudflare API基础URL
     */
    private const API_BASE_URL = 'https://api.cloudflare.com/client/v4';

    /**
     * @var array<string, mixed>
     */
    private array $credentials = [];

    /**
     * @inheritDoc
     */
    public function getAdapterCode(): string
    {
        return 'cloudflare';
    }

    /**
     * @inheritDoc
     */
    public function getAdapterName(): string
    {
        return 'Cloudflare';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Cloudflare CDN服务提供商，支持API Token认证、缓存清理和Cache Rules管理';
    }

    /**
     * @inheritDoc
     */
    public function getVersion(): string
    {
        return '1.0.0';
    }

    /**
     * Legacy compatibility shim for older tests/callers.
     *
     * @param array<string, mixed> $credentials
     */
    public function setCredentials(array $credentials): static
    {
        $this->credentials = $credentials;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function purgeEverything(string $zoneId, array $credentials): array
    {
        $url = self::API_BASE_URL . '/zones/' . $zoneId . '/purge_cache';
        
        $response = $this->makeRequest('POST', $url, [
            'purge_everything' => true
        ], $credentials);

        if (($response['success'] ?? null) === true) {
            return [
                'success' => true,
                'message' => __('缓存清理成功')
            ];
        }

        return [
            'success' => false,
            'message' => $response['errors'][0]['message'] ?? __('缓存清理失败')
        ];
    }

    /**
     * @inheritDoc
     */
    public function purgeUrls(string $zoneId, array $urls, array $credentials): array
    {
        return $this->purgeBatches($zoneId, 'files', $urls, $credentials);
    }

    public function purgeHosts(string $zoneId, array $hosts, array $credentials): array
    {
        return $this->purgeBatches($zoneId, 'hosts', $hosts, $credentials);
    }

    public function purgeTags(string $zoneId, array $tags, array $credentials): array
    {
        return $this->purgeBatches($zoneId, 'tags', $tags, $credentials);
    }

    public function purgeCacheKeys(string $zoneId, array $keys, array $credentials): array
    {
        return $this->purgeBatches($zoneId, 'prefixes', $keys, $credentials);
    }

    /** Optional URL-prefix capability; values are host/path without the scheme. */
    public function purgePrefixes(string $zoneId, array $prefixes, array $credentials): array
    {
        return $this->purgeBatches($zoneId, 'prefixes', $prefixes, $credentials);
    }

    /** 100 operations is supported by every Cloudflare plan. */
    private function purgeBatches(string $zoneId, string $field, array $items, array $credentials): array
    {
        if ($items === []) {
            return ['success' => false, 'message' => __('清理列表不能为空'), 'purged_count' => 0];
        }
        $purged = 0;
        $ids = [];
        foreach (array_chunk(array_values($items), 100) as $batch) {
            try {
                $response = $this->makeRequest('POST', self::API_BASE_URL . '/zones/' . rawurlencode($zoneId) . '/purge_cache', [$field => $batch], $credentials);
            } catch (\Throwable $e) {
                return ['success' => false, 'message' => $e->getMessage(), 'purged_count' => $purged, 'requested_count' => count($items), 'purge_ids' => $ids];
            }
            if (($response['success'] ?? null) !== true) {
                return ['success' => false, 'message' => $response['errors'][0]['message'] ?? __('缓存清理失败'), 'purged_count' => $purged, 'requested_count' => count($items), 'purge_ids' => $ids];
            }
            $purged += count($batch);
            if (isset($response['result']['id'])) {
                $ids[] = (string)$response['result']['id'];
            }
        }
        return ['success' => true, 'message' => __('缓存清理成功'), 'purged_count' => $purged, 'requested_count' => count($items), 'purge_ids' => $ids];
    }

    /** Read-only: token validity and optional zone access do not prove Cache Purge permission. */
    public function testConnection(array $credentials, string $zoneId = '', string $domain = ''): array
    {
        try {
            $response = $this->makeRequest('GET', self::API_BASE_URL . '/user/tokens/verify', [], $credentials);
        } catch (Core $e) {
            return [
                'success' => false,
                'message' => $this->mapTokenFailureMessage($e->getMessage()),
                'purge_verified' => false,
            ];
        }
        if (($response['success'] ?? null) !== true || ($response['result']['status'] ?? '') !== 'active') {
            $raw = (string)($response['errors'][0]['message'] ?? '');
            return [
                'success' => false,
                'message' => $this->mapTokenFailureMessage($raw !== '' ? $raw : (string)__('Cloudflare Token 验证失败')),
                'purge_verified' => false,
            ];
        }
        $result = ['success' => true, 'message' => __('Token 有效；清缓存权限需由实际清理结果确认'), 'token_verified' => true, 'zone_verified' => false, 'purge_verified' => false];
        if ($zoneId !== '') {
            try {
                $zone = $this->makeRequest('GET', self::API_BASE_URL . '/zones/' . rawurlencode($zoneId), [], $credentials);
            } catch (Core $e) {
                return [
                    'success' => false,
                    'message' => $this->mapTokenFailureMessage($e->getMessage()),
                    'token_verified' => true,
                    'zone_verified' => false,
                    'purge_verified' => false,
                ];
            }
            $zoneName = strtolower(rtrim(trim((string)($zone['result']['name'] ?? '')), '.'));
            $host = strtolower(rtrim(trim($domain), '.'));
            if (($zone['success'] ?? null) !== true || ($zone['result']['id'] ?? '') !== $zoneId || $zoneName === ''
                || ($host !== '' && $host !== $zoneName && !str_ends_with($host, '.' . $zoneName))) {
                return ['success' => false, 'message' => __('Zone 不可访问或与域名不匹配'), 'token_verified' => true, 'zone_verified' => false, 'purge_verified' => false];
            }
            $result['zone_verified'] = true;
            $result['zone_name'] = $zoneName;
            $result['zone_id'] = $zoneId;
            $result['message'] = __('Token 有效且 Zone 可访问；清缓存权限需由实际清理结果确认');
        }
        return $result;
    }

    /**
     * Cloudflare 原文 "Invalid API Token" 易被误读成 Account/Zone 填错；映射为可操作说明。
     */
    private function mapTokenFailureMessage(string $raw): string
    {
        $normalized = \strtolower(\trim($raw));
        if ($normalized === ''
            || \str_contains($normalized, 'invalid api token')
            || \str_contains($normalized, 'invalid access token')
        ) {
            return (string)__(
                'Cloudflare 拒绝了当前 API Token（Account ID / Zone ID 正确也不能代替 Token）。请到 Cloudflare → My Profile → API Tokens 新建 Token（需 Zone:Read 与 Cache Purge），粘贴到上方「API Token」后先保存再测。不要填 Global API Key。'
            );
        }

        return $raw;
    }

    /**
     * @inheritDoc
     */
    public function getRules(string $zoneId, array $credentials): array
    {
        $url = self::API_BASE_URL . '/zones/' . $zoneId . '/rulesets/phases/http_request_cache_settings/entrypoint';
        
        $response = $this->makeRequest('GET', $url, [], $credentials);

        if (($response['success'] ?? null) === true) {
            $rules = $response['result']['rules'] ?? [];
            return is_array($rules) ? $rules : [];
        }

        // 如果获取失败，返回空数组
        return [];
    }

    /**
     * @inheritDoc
     */
    public function putRules(string $zoneId, array $rules, array $credentials): array
    {
        // Cloudflare Cache Rules使用rulesets API
        $url = self::API_BASE_URL . '/zones/' . $zoneId . '/rulesets/phases/http_request_cache_settings/entrypoint';
        
        // 先获取现有ruleset ID
        $getResponse = $this->makeRequest('GET', $url, [], $credentials);
        
        if (!($getResponse['success'] ?? false)) {
            return [
                'success' => false,
                'message' => __('获取现有规则失败')
            ];
        }

        $rulesetId = $getResponse['result']['id'] ?? null;
        
        // 转换规则格式，确保符合 Cloudflare API 要求
        $formattedRules = $this->formatRulesForApi($rules);
        
        if ($rulesetId) {
            // 更新现有ruleset - PUT 请求需要包含完整的 ruleset 结构
            $updateUrl = self::API_BASE_URL . '/zones/' . $zoneId . '/rulesets/' . $rulesetId;
            
            // 获取现有 ruleset 的完整结构，保留除 rules 外的其他字段
            $existingRuleset = $getResponse['result'] ?? [];
            
            // 构建请求数据，只包含必要的字段
            $requestData = [
                'rules' => $formattedRules
            ];
            
            // 保留其他必要的字段（如果存在）
            if (isset($existingRuleset['kind'])) {
                $requestData['kind'] = $existingRuleset['kind'];
            }
            if (isset($existingRuleset['phase'])) {
                $requestData['phase'] = $existingRuleset['phase'];
            }
            if (isset($existingRuleset['name'])) {
                $requestData['name'] = $existingRuleset['name'];
            }
            
            $response = $this->makeRequest('PUT', $updateUrl, $requestData, $credentials);
        } else {
            // 创建新ruleset - POST 请求需要包含 phase 信息
            $response = $this->makeRequest('POST', $url, [
                'rules' => $formattedRules
            ], $credentials);
        }

        if (($response['success'] ?? null) === true) {
            return [
                'success' => true,
                'message' => __('规则推送成功，共 %{count} 条', ['count' => count($formattedRules)]),
                'data' => $response['result'] ?? null
            ];
        }

        // 收集所有错误信息
        $errorMessages = [];
        if (isset($response['errors']) && is_array($response['errors'])) {
            foreach ($response['errors'] as $error) {
                $errorMessages[] = $error['message'] ?? '未知错误';
            }
        }
        
        $errorMessage = !empty($errorMessages) 
            ? implode('; ', $errorMessages) 
            : __('规则推送失败');
        
        return [
            'success' => false,
            'message' => $errorMessage,
            'errors' => $response['errors'] ?? []
        ];
    }

    /**
     * 将内部规则转为 Cloudflare Cache Rules API 结构。
     *
     * 权威格式（https://developers.cloudflare.com/cache/how-to/cache-rules/create-api/）：
     * action = "set_cache_settings"
     * action_parameters = { cache: true|false, edge_ttl: { mode, default?, status_code_ttl? }, ... }
     *
     * 内部 default-rules / 注释收集仍用简化 action：{ "cache": false } 或
     * { "cache": { "ttl": N, "status_code": [...], "mode"?: "override_origin"|"bypass_by_default"|"respect_origin" } }。
     *
     * @param array $rules 原始规则数组
     * @return array 格式化后的规则数组
     */
    private function formatRulesForApi(array $rules): array
    {
        $formattedRules = [];

        foreach ($rules as $rule) {
            if (!\is_array($rule)) {
                continue;
            }

            $expression = isset($rule['expression']) ? \trim((string)$rule['expression']) : '';
            if ($expression === '') {
                continue;
            }

            // http_request_cache_settings 阶段没有响应头字段；带 http.response.* 的规则会被 API 拒绝。
            if ($this->expressionUsesResponseFields($expression)) {
                continue;
            }

            $formattedRule = [
                'expression' => $expression,
                'enabled' => \array_key_exists('enabled', $rule) ? (bool)$rule['enabled'] : true,
            ];

            if (isset($rule['description'])) {
                $formattedRule['description'] = (string)$rule['description'];
            }

            // 已是 Cloudflare 原生：action 为字符串（如 set_cache_settings）
            if (isset($rule['action']) && \is_string($rule['action']) && $rule['action'] !== '') {
                $formattedRule['action'] = $rule['action'];
                if (isset($rule['action_parameters']) && \is_array($rule['action_parameters'])) {
                    $formattedRule['action_parameters'] = $rule['action_parameters'];
                }
                $formattedRules[] = $formattedRule;
                continue;
            }

            $action = $rule['action'] ?? null;
            if (\is_string($action)) {
                $decoded = \json_decode($action, true);
                $action = (\json_last_error() === JSON_ERROR_NONE && \is_array($decoded)) ? $decoded : null;
            }
            if (!\is_array($action)) {
                continue;
            }

            $actionParameters = $this->normalizeActionParameters($action);
            if ($actionParameters === null) {
                continue;
            }

            $formattedRule['action'] = 'set_cache_settings';
            $formattedRule['action_parameters'] = $actionParameters;
            $formattedRules[] = $formattedRule;
        }

        return $formattedRules;
    }

    /**
     * Cache Rules 请求阶段表达式不得引用响应字段。
     */
    private function expressionUsesResponseFields(string $expression): bool
    {
        return (bool)\preg_match('/http\\.response\\./i', $expression);
    }

    /**
     * 将内部 action 规范化为 Cloudflare action_parameters。
     *
     * @param array $action 内部 action（含 cache 键）
     * @return array<string, mixed>|null
     */
    private function normalizeActionParameters(array $action): ?array
    {
        if (!\array_key_exists('cache', $action)) {
            return null;
        }

        if ($action['cache'] === false) {
            return ['cache' => false];
        }

        if ($action['cache'] === true) {
            return [
                'cache' => true,
                'edge_ttl' => ['mode' => 'bypass_by_default'],
            ];
        }

        if (!\is_array($action['cache'])) {
            return null;
        }

        $cfg = $action['cache'];
        $ttl = null;
        if (isset($cfg['ttl']) && \is_numeric($cfg['ttl'])) {
            $ttl = (int)$cfg['ttl'];
        } elseif (isset($cfg['edge_ttl']) && \is_numeric($cfg['edge_ttl'])) {
            $ttl = (int)$cfg['edge_ttl'];
        }

        $mode = '';
        if (isset($cfg['mode']) && \is_string($cfg['mode'])) {
            $mode = \strtolower(\trim($cfg['mode']));
        } elseif (isset($cfg['edge_ttl_mode']) && \is_string($cfg['edge_ttl_mode'])) {
            $mode = \strtolower(\trim($cfg['edge_ttl_mode']));
        }
        $allowedModes = ['override_origin', 'respect_origin', 'bypass_by_default'];
        if (!\in_array($mode, $allowedModes, true)) {
            $mode = $ttl !== null ? 'override_origin' : 'bypass_by_default';
        }

        $edgeTtl = ['mode' => $mode];
        if ($ttl !== null && $mode !== 'bypass_by_default') {
            $edgeTtl['default'] = $ttl;
        }

        if (isset($cfg['status_code'])) {
            $statusCodes = \is_array($cfg['status_code']) ? $cfg['status_code'] : [$cfg['status_code']];
            $statusCodeTtl = [];
            foreach ($statusCodes as $code) {
                if (!\is_numeric($code)) {
                    continue;
                }
                $entry = ['status_code' => (int)$code];
                if ($ttl !== null) {
                    $entry['value'] = $ttl;
                }
                $statusCodeTtl[] = $entry;
            }
            if ($statusCodeTtl !== []) {
                $edgeTtl['status_code_ttl'] = $statusCodeTtl;
            }
        }

        $params = [
            'cache' => true,
            'edge_ttl' => $edgeTtl,
        ];

        if (isset($cfg['browser_ttl']) && \is_numeric($cfg['browser_ttl'])) {
            $params['browser_ttl'] = [
                'mode' => 'override_origin',
                'default' => (int)$cfg['browser_ttl'],
            ];
        }

        return $params;
    }

    /**
     * @deprecated 保留给旧单测反射；请用 normalizeActionParameters + formatRulesForApi
     * @param array $action
     * @return array|null
     */
    private function normalizeAction(array $action): ?array
    {
        return $this->normalizeActionParameters($action);
    }

    /**
     * @inheritDoc
     */
    public function ensureZone(string $domain, array $credentials): array
    {
        // 搜索Zone
        $url = self::API_BASE_URL . '/zones?name=' . urlencode($domain);
        $response = $this->makeRequest('GET', $url, [], $credentials);

        if (($response['success'] ?? null) === true) {
            $zones = $response['result'] ?? [];
            if (!empty($zones) && isset($zones[0])) {
                return [
                    'zone_id' => (string)($zones[0]['id'] ?? ''),
                    'zone_name' => (string)($zones[0]['name'] ?? $domain)
                ];
            }
        }

        throw new Core(__('Zone不存在: %{1}', [$domain]));
    }
    
    /**
     * @inheritDoc
     */
    public function enableAttackMode(string $zoneId, array $credentials, array $attackData = []): array
    {
        // Cloudflare 的 "Under Attack Mode" 设置
        // API: PATCH /zones/{zone_id}/settings/security_level
        // 值: "under_attack" 表示开启攻击模式
        
        $url = self::API_BASE_URL . '/zones/' . $zoneId . '/settings/security_level';
        
        try {
            $response = $this->makeRequest('PATCH', $url, [
                'value' => 'under_attack'
            ], $credentials);
            
            if (($response['success'] ?? null) === true) {
                // 可选：同时封禁攻击者 IP
                $blockedIps = $this->blockAttackerIps($zoneId, $credentials, $attackData['attacker_ips'] ?? []);
                
                return [
                    'success' => true,
                    'message' => __('Cloudflare 攻击防护模式已开启'),
                    'data' => [
                        'security_level' => 'under_attack',
                        'blocked_ips' => $blockedIps,
                    ]
                ];
            }
            
            return [
                'success' => false,
                'message' => $response['errors'][0]['message'] ?? __('开启攻击防护模式失败')
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => __('开启攻击防护模式异常: %{1}', [$e->getMessage()])
            ];
        }
    }
    
    /**
     * @inheritDoc
     */
    public function disableAttackMode(string $zoneId, array $credentials): array
    {
        // 将安全级别恢复为 "medium"（默认值）
        $url = self::API_BASE_URL . '/zones/' . $zoneId . '/settings/security_level';
        
        try {
            $response = $this->makeRequest('PATCH', $url, [
                'value' => 'medium'
            ], $credentials);
            
            if (($response['success'] ?? null) === true) {
                return [
                    'success' => true,
                    'message' => __('Cloudflare 攻击防护模式已关闭'),
                    'data' => [
                        'security_level' => 'medium',
                    ]
                ];
            }
            
            return [
                'success' => false,
                'message' => $response['errors'][0]['message'] ?? __('关闭攻击防护模式失败')
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => __('关闭攻击防护模式异常: %{1}', [$e->getMessage()])
            ];
        }
    }
    
    /**
     * @inheritDoc
     */
    public function supportsAttackMode(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     *
     * Cloudflare 注入 Cf-Connecting-Ip Header 传递真实客户端 IP
     */
    public function getRealIpHeaderKeys(): array
    {
        return ['HTTP_CF_CONNECTING_IP'];
    }
    
    /**
     * 封禁攻击者 IP（使用 Cloudflare Access Rules）
     * 
     * @param string $zoneId Zone ID
     * @param array $credentials 凭据
     * @param array $ips 攻击者 IP 列表
     * @return array 封禁结果
     */
    private function blockAttackerIps(string $zoneId, array $credentials, array $ips): array
    {
        if (empty($ips)) {
            return ['blocked' => 0, 'failed' => 0];
        }
        
        $blocked = 0;
        $failed = 0;
        
        // 限制封禁 IP 数量，避免 API 限流
        $ipsToBlock = \array_slice($ips, 0, 10);
        
        foreach ($ipsToBlock as $ip) {
            try {
                // 使用 Zone-level Access Rules API
                $url = self::API_BASE_URL . '/zones/' . $zoneId . '/firewall/access_rules/rules';
                
                $response = $this->makeRequest('POST', $url, [
                    'mode' => 'block',
                    'configuration' => [
                        'target' => 'ip',
                        'value' => $ip,
                    ],
                    'notes' => 'Auto-blocked by WLS attack detection at ' . \date('Y-m-d H:i:s'),
                ], $credentials);
                
                if (($response['success'] ?? null) === true) {
                    $blocked++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
            }
        }
        
        return [
            'blocked' => $blocked,
            'failed' => $failed,
            'total' => \count($ipsToBlock),
        ];
    }

    /**
     * @DESC          # 发送API请求
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @param string $method HTTP方法
     * @param string $url 请求URL
     * @param array $data 请求数据
     * @param array $credentials 凭据
     * @return array
     * @throws Core
     */
    private function makeRequest(string $method, string $url, array $data = [], array $credentials = []): array
    {
        if ($credentials === []) {
            $credentials = $this->credentials;
        }

        // 验证凭据
        $apiToken = $credentials['api_token'] ?? '';
        if (empty($apiToken)) {
            throw new Core(__('Cloudflare API Token未配置'));
        }

        // 初始化cURL
        $ch = curl_init($url);
        
        $headers = [
            'Authorization: Bearer ' . $apiToken,
            'Content-Type: application/json'
        ];

        // 配置 SSL 选项
        $sslVerifyPeer = true;
        $sslVerifyHost = 2;
        
        // 检查是否在开发环境或配置了禁用 SSL 验证
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $disableSslVerify = getenv('CDN_DISABLE_SSL_VERIFY') === '1' || 
                           getenv('APP_ENV') === 'development' ||
                           ($isWindows && getenv('APP_ENV') !== 'production');
        
        // 如果启用 SSL 验证，尝试设置 CA 证书路径
        if (!$disableSslVerify) {
            // 常见的 CA 证书包路径
            $caPaths = [
                ini_get('curl.cainfo'),
                ini_get('openssl.cafile'),
                '/etc/ssl/certs/ca-certificates.crt', // Debian/Ubuntu
                '/etc/pki/tls/certs/ca-bundle.crt',   // CentOS/RHEL
                '/usr/local/etc/openssl/cert.pem',    // macOS (Homebrew)
                '/etc/ssl/cert.pem',                  // macOS (系统)
                BP . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'certs' . DIRECTORY_SEPARATOR . 'ca-bundle.crt'
            ];
            
            // Windows 特定路径
            if ($isWindows) {
                $caPaths = array_merge($caPaths, [
                    getenv('WINDIR') . '\\System32\\curl-ca-bundle.crt',
                    getenv('WINDIR') . '\\System32\\ca-bundle.crt',
                    getenv('LOCALAPPDATA') . '\\cacert.pem'
                ]);
            }
            
            $caBundlePath = null;
            foreach ($caPaths as $path) {
                if ($path && file_exists($path)) {
                    $caBundlePath = $path;
                    break;
                }
            }
            
            if ($caBundlePath) {
                curl_setopt($ch, CURLOPT_CAINFO, $caBundlePath);
            } else {
                // 如果找不到 CA 证书包，在非生产环境禁用验证（仅警告）
                if (getenv('APP_ENV') !== 'production') {
                    $sslVerifyPeer = false;
                    $sslVerifyHost = 0;
                    w_log_warning('Warning: CA certificate bundle not found, SSL verification disabled for Cloudflare API requests. ' .
                             'To fix this, set CDN_DISABLE_SSL_VERIFY=1 or configure curl.cainfo in php.ini');
                }
            }
        } else {
            // 开发环境或 Windows 非生产环境禁用 SSL 验证
            $sslVerifyPeer = false;
            $sslVerifyHost = 0;
        }
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => $sslVerifyPeer,
            CURLOPT_SSL_VERIFYHOST => $sslVerifyHost,
            // 与 Framework Http Request 一致：禁用本机 HTTP(S)_PROXY（常见 Clash 残留 127.0.0.1 无端口 → 秒失败）
            CURLOPT_PROXY => '',
            CURLOPT_PROXYUSERPWD => '',
            CURLOPT_PROXYTYPE => CURLPROXY_HTTP,
        ]);

        // 如果是POST或PUT，添加请求体
        if (in_array($method, ['POST', 'PUT', 'PATCH']) && !empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        }

        // 执行请求
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);

        if ($error) {
            throw new Core(__('请求失败: %{1}', [$error]));
        }

        $decodedResponse = json_decode($response, true);
        
        if ($httpCode < 200 || $httpCode >= 300) {
            // 收集所有错误信息
            $errorMessages = [];
            if (isset($decodedResponse['errors']) && is_array($decodedResponse['errors'])) {
                foreach ($decodedResponse['errors'] as $err) {
                    $errorMessages[] = $err['message'] ?? '未知错误';
                }
            }
            $errorMessage = !empty($errorMessages) 
                ? implode('; ', $errorMessages)
                : __('HTTP错误: %{1}', [$httpCode]);
            throw new Core($errorMessage);
        }

        if (!is_array($decodedResponse) || !array_key_exists('success', $decodedResponse)) {
            throw new Core(__('Cloudflare 返回无效响应'));
        }
        return $decodedResponse;
    }

    public function supportsOneClickOauth(): bool
    {
        return true;
    }

    public function isOauthConfigured(): bool
    {
        return $this->oauthService()->isConfigured();
    }

    public function getOauthConnectLabel(): string
    {
        return (string)__('连接或重新授权 Cloudflare');
    }

    public function getOauthConfigUrl(): string
    {
        return $this->oauthSystemConfigUrl('cdn/cloudflare/oauth_client_id');
    }

    public function getOauthCallbackUrl(): string
    {
        $request = ObjectManager::getInstance(Request::class);
        $raw = rtrim(
            (string)$request->getUrlBuilder()->getBackendUrl('cdn/backend/oauth/callback'),
            '?&',
        );

        return Url::withoutStorefrontLocalizationPrefix($raw);
    }

    public function getOauthRequestedScopesLabel(): string
    {
        return $this->oauthService()->requestedScopesLabel();
    }

    public function getOauthCredentialHints(): array
    {
        $service = $this->oauthService();
        if ($service->isConfigured()) {
            return [];
        }

        $callbackUrl = $this->getOauthCallbackUrl();
        $idUrl = $this->oauthSystemConfigUrl('cdn/cloudflare/oauth_client_id');
        $secretUrl = $this->oauthSystemConfigUrl('cdn/cloudflare/oauth_client_secret');

        if ($service->hasIdenticalClientCredentials()) {
            return [
                'tone' => 'danger',
                'kind' => 'identical',
                'title' => (string)__('已保存，但 Client ID 与 Secret 填成一样了'),
                'body' => (string)__('系统已读到两项配置，但内容完全相同（通常是把 Client ID 粘进了 Secret）。这样无法跳转授权；到 Cloudflare 会报 invalid_client。'),
                'action_label' => (string)__('去修正 Client Secret'),
                'action_url' => $secretUrl,
                'callback_url' => $callbackUrl,
            ];
        }

        if ($service->hasMisplacedClientCredentials()) {
            return [
                'tone' => 'danger',
                'kind' => 'misplaced',
                'title' => (string)__('已保存，但 Client ID / Secret 填反了'),
                'body' => (string)__('系统已读到两项配置，因此不是「尚未配置」。对照 Cloudflare「客户端已创建」弹窗：下面「Your Client ID」是 Client ID（32 位十六进制）；上面「您的客户端密钥」是 Secret（常以 cfoc_ 开头）。对调粘贴会导致无法授权。'),
                'action_label' => (string)__('去修正 Client ID / Secret'),
                'action_url' => $idUrl,
                'callback_url' => $callbackUrl,
            ];
        }

        return [
            'tone' => 'warning',
            'kind' => 'empty',
            'title' => (string)__('尚未配置 Cloudflare OAuth'),
            'body' => (string)__('系统配置里还没有 Client ID / Secret。直接点「连接」不会跳转 Cloudflare，只会回到本页。请先填写并保存，再回来授权。'),
            'action_label' => (string)__('打开 Cloudflare OAuth 配置'),
            'action_url' => $idUrl,
            'callback_url' => $callbackUrl,
        ];
    }

    public function startOauthAuthorization(string $callbackUrl, string $returnRoute): string
    {
        return $this->oauthService()->authorizationUrl($callbackUrl, $returnRoute);
    }

    public function completeOauthAuthorization(string $code, string $state, string $callbackUrl): array
    {
        return $this->oauthService()->completeAuthorization($code, $state, $callbackUrl);
    }

    public function consumeOauthFailureState(string $state, string $callbackUrl): array
    {
        return $this->oauthService()->consumeFailureState($state, $callbackUrl);
    }

    private function oauthService(): CloudflareOAuthService
    {
        return ObjectManager::getInstance(CloudflareOAuthService::class);
    }

    private function oauthSystemConfigUrl(string $guideKey): string
    {
        $request = ObjectManager::getInstance(Request::class);

        return (string)$request->getUrlBuilder()->getBackendUrl(
            'cdn/backend/config',
            [
                'guide_key' => $guideKey,
            ],
        );
    }
}
