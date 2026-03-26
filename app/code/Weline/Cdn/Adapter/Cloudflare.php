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
use Weline\Framework\Exception\Core;

/**
 * Cloudflare CDN适配器
 * 
 * 实现 Cloudflare v4 API 的缓存清理和规则管理功能
 */
class Cloudflare implements AdapterInterface
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

        if ($response['success'] ?? false) {
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
        if (empty($urls)) {
            return [
                'success' => false,
                'message' => __('URL列表不能为空')
            ];
        }

        $url = self::API_BASE_URL . '/zones/' . $zoneId . '/purge_cache';
        
        $response = $this->makeRequest('POST', $url, [
            'files' => $urls
        ], $credentials);

        if ($response['success'] ?? false) {
            return [
                'success' => true,
                'message' => __('成功清理 %{count} 个URL', ['count' => count($urls)])
            ];
        }

        return [
            'success' => false,
            'message' => $response['errors'][0]['message'] ?? __('URL清理失败')
        ];
    }

    /**
     * @inheritDoc
     */
    public function purgeHosts(string $zoneId, array $hosts, array $credentials): array
    {
        if (empty($hosts)) {
            return [
                'success' => false,
                'message' => __('Host列表不能为空')
            ];
        }

        $url = self::API_BASE_URL . '/zones/' . $zoneId . '/purge_cache';
        
        $response = $this->makeRequest('POST', $url, [
            'hosts' => $hosts
        ], $credentials);

        if ($response['success'] ?? false) {
            return [
                'success' => true,
                'message' => __('成功清理 %{count} 个Host', ['count' => count($hosts)])
            ];
        }

        return [
            'success' => false,
            'message' => $response['errors'][0]['message'] ?? __('Host清理失败')
        ];
    }

    /**
     * @inheritDoc
     */
    public function purgeTags(string $zoneId, array $tags, array $credentials): array
    {
        if (empty($tags)) {
            return [
                'success' => false,
                'message' => __('Tag列表不能为空')
            ];
        }

        $url = self::API_BASE_URL . '/zones/' . $zoneId . '/purge_cache';
        
        $response = $this->makeRequest('POST', $url, [
            'tags' => $tags
        ], $credentials);

        if ($response['success'] ?? false) {
            return [
                'success' => true,
                'message' => __('成功清理 %{count} 个Tag', ['count' => count($tags)])
            ];
        }

        return [
            'success' => false,
            'message' => $response['errors'][0]['message'] ?? __('Tag清理失败')
        ];
    }

    /**
     * @inheritDoc
     */
    public function purgeCacheKeys(string $zoneId, array $keys, array $credentials): array
    {
        if (empty($keys)) {
            return [
                'success' => false,
                'message' => __('Cache Key列表不能为空')
            ];
        }

        $url = self::API_BASE_URL . '/zones/' . $zoneId . '/purge_cache';
        
        $response = $this->makeRequest('POST', $url, [
            'prefixes' => $keys
        ], $credentials);

        if ($response['success'] ?? false) {
            return [
                'success' => true,
                'message' => __('成功清理 %{count} 个Cache Key', ['count' => count($keys)])
            ];
        }

        return [
            'success' => false,
            'message' => $response['errors'][0]['message'] ?? __('Cache Key清理失败')
        ];
    }

    /**
     * @inheritDoc
     */
    public function getRules(string $zoneId, array $credentials): array
    {
        $url = self::API_BASE_URL . '/zones/' . $zoneId . '/rulesets/phases/http_request_cache_settings/entrypoint';
        
        $response = $this->makeRequest('GET', $url, [], $credentials);

        if ($response['success'] ?? false) {
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

        if ($response['success'] ?? false) {
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
     * 格式化规则以符合 Cloudflare API 要求
     * 
     * @param array $rules 原始规则数组
     * @return array 格式化后的规则数组
     */
    private function formatRulesForApi(array $rules): array
    {
        $formattedRules = [];
        
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            
            $formattedRule = [];
            
            // expression 字段（必需）
            if (isset($rule['expression'])) {
                $formattedRule['expression'] = (string)$rule['expression'];
            } else {
                // 没有 expression，跳过该规则
                continue;
            }
            
            // action 字段处理
            // Cloudflare Cache Rules API 要求 action 必须是对象
            // 格式：{ "cache": {...} } 或 { "cache": false }
            if (isset($rule['action'])) {
                $action = $rule['action'];
                
                if (is_array($action)) {
                    // action 已经是数组/对象
                    // 验证并规范化 action 结构
                    $normalizedAction = $this->normalizeAction($action);
                    if ($normalizedAction !== null) {
                        $formattedRule['action'] = $normalizedAction;
                    } else {
                        // action 格式无效，跳过该规则
                        continue;
                    }
                } elseif (is_string($action)) {
                    // 如果 action 是字符串，尝试解析为 JSON
                    $decoded = json_decode($action, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $normalizedAction = $this->normalizeAction($decoded);
                        if ($normalizedAction !== null) {
                            $formattedRule['action'] = $normalizedAction;
                        } else {
                            continue;
                        }
                    } else {
                        // 如果解析失败，跳过该规则
                        continue;
                    }
                } else {
                    // 其他类型，跳过
                    continue;
                }
            } else {
                // 没有 action 字段，跳过
                continue;
            }
            
            // description 字段（可选）
            if (isset($rule['description'])) {
                $formattedRule['description'] = (string)$rule['description'];
            }
            
            // enabled 字段（可选，默认为 true）
            if (isset($rule['enabled'])) {
                $formattedRule['enabled'] = (bool)$rule['enabled'];
            } else {
                $formattedRule['enabled'] = true;
            }
            
            // 确保至少包含 expression 和 action
            if (isset($formattedRule['expression']) && isset($formattedRule['action'])) {
                $formattedRules[] = $formattedRule;
            }
        }
        
        return $formattedRules;
    }
    
    /**
     * 规范化 action 结构以符合 Cloudflare API 要求
     * 
     * @param array $action 原始 action 数组
     * @return array|null 规范化后的 action，如果格式无效则返回 null
     */
    private function normalizeAction(array $action): ?array
    {
        // Cloudflare Cache Rules API 的 action 格式：
        // { "cache": {...} } 或 { "cache": false }
        
        // 如果 action 已经是正确的格式（包含 cache 键）
        if (isset($action['cache'])) {
            // 如果 cache 是 false，直接返回
            if ($action['cache'] === false) {
                return ['cache' => false];
            }
            
            // 如果 cache 是数组/对象，验证并规范化
            if (is_array($action['cache'])) {
                $cacheConfig = [];
                
                // status_code 字段（可选）
                if (isset($action['cache']['status_code'])) {
                    $statusCode = $action['cache']['status_code'];
                    if (is_array($statusCode)) {
                        // 确保所有元素都是整数
                        $cacheConfig['status_code'] = array_map('intval', $statusCode);
                    } elseif (is_numeric($statusCode)) {
                        $cacheConfig['status_code'] = [(int)$statusCode];
                    }
                }
                
                // ttl 字段（可选）
                if (isset($action['cache']['ttl'])) {
                    $ttl = $action['cache']['ttl'];
                    if (is_numeric($ttl)) {
                        $cacheConfig['ttl'] = (int)$ttl;
                    }
                }
                
                // edge_ttl 字段（可选）
                if (isset($action['cache']['edge_ttl'])) {
                    $edgeTtl = $action['cache']['edge_ttl'];
                    if (is_numeric($edgeTtl)) {
                        $cacheConfig['edge_ttl'] = (int)$edgeTtl;
                    }
                }
                
                // browser_ttl 字段（可选）
                if (isset($action['cache']['browser_ttl'])) {
                    $browserTtl = $action['cache']['browser_ttl'];
                    if (is_numeric($browserTtl)) {
                        $cacheConfig['browser_ttl'] = (int)$browserTtl;
                    }
                }
                
                // serve_stale 字段（可选）
                if (isset($action['cache']['serve_stale'])) {
                    $cacheConfig['serve_stale'] = (bool)$action['cache']['serve_stale'];
                }
                
                // 如果 cacheConfig 不为空，返回规范化后的 action
                if (!empty($cacheConfig)) {
                    return ['cache' => $cacheConfig];
                }
                
                // 如果 cacheConfig 为空，返回 cache: false
                return ['cache' => false];
            }
            
            // cache 不是数组也不是 false，格式无效
            return null;
        }
        
        // action 不包含 cache 键，格式无效
        return null;
    }

    /**
     * @inheritDoc
     */
    public function ensureZone(string $domain, array $credentials): array
    {
        // 搜索Zone
        $url = self::API_BASE_URL . '/zones?name=' . urlencode($domain);
        $response = $this->makeRequest('GET', $url, [], $credentials);

        if ($response['success'] ?? false) {
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
            
            if ($response['success'] ?? false) {
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
            
            if ($response['success'] ?? false) {
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
                
                if ($response['success'] ?? false) {
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
            CURLOPT_SSL_VERIFYPEER => $sslVerifyPeer,
            CURLOPT_SSL_VERIFYHOST => $sslVerifyHost
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
        
        if ($httpCode >= 400) {
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

        return is_array($decodedResponse) ? $decodedResponse : [];
    }
}

