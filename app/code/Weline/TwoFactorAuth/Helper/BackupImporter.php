<?php

declare(strict_types=1);

namespace Weline\TwoFactorAuth\Helper;

/**
 * 备份文件导入助手
 * 支持多种验证器的备份格式
 * 
 * @package Weline\TwoFactorAuth\Helper
 */
class BackupImporter
{
    /**
     * 解析备份文件内容
     * 
     * @param string $content 文件内容
     * @param string $format 格式（auto/json/uri_list/google_export）
     * @return array 解析后的账户列表
     */
    public static function parse(string $content, string $format = 'auto'): array
    {
        if ($format === 'auto') {
            $format = self::detectFormat($content);
        }

        return match ($format) {
            'json' => self::parseJson($content),
            'uri_list' => self::parseUriList($content),
            'google_export' => self::parseGoogleExport($content),
            'aegis' => self::parseAegisJson($content),
            default => []
        };
    }

    /**
     * 自动检测备份格式
     * 
     * @param string $content 文件内容
     * @return string 格式类型
     */
    private static function detectFormat(string $content): string
    {
        $content = trim($content);

        // Google Authenticator 导出格式
        if (str_starts_with($content, 'otpauth-migration://')) {
            return 'google_export';
        }

        // JSON 格式
        if (str_starts_with($content, '{') || str_starts_with($content, '[')) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                // Aegis Authenticator 格式 - 检查是否有db.entries结构
                if (isset($decoded['db']['entries']) && is_array($decoded['db']['entries'])) {
                    return 'aegis';
                }
                // 也检查是否有type字段且为totp（Aegis格式的特征）
                if (isset($decoded['type']) && $decoded['type'] === 'totp' && isset($decoded['db'])) {
                    return 'aegis';
                }
                return 'json';
            }
        }

        // URI 列表格式
        if (str_contains($content, 'otpauth://')) {
            return 'uri_list';
        }

        return 'unknown';
    }

    /**
     * 解析 JSON 格式备份
     * 支持多种JSON格式
     * 
     * @param string $content JSON内容
     * @return array
     */
    private static function parseJson(string $content): array
    {
        $data = json_decode($content, true);
        if (!is_array($data)) {
            return [];
        }

        $accounts = [];

        // 处理数组格式
        if (isset($data[0])) {
            foreach ($data as $item) {
                $account = self::normalizeAccount($item);
                if ($account) {
                    $accounts[] = $account;
                }
            }
        } else {
            // 处理单个对象
            $account = self::normalizeAccount($data);
            if ($account) {
                $accounts[] = $account;
            }
        }

        return $accounts;
    }

    /**
     * 解析 Aegis Authenticator JSON格式
     * 
     * @param string $content JSON内容
     * @return array
     */
    private static function parseAegisJson(string $content): array
    {
        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['db']['entries'])) {
            return [];
        }

        $accounts = [];
        foreach ($data['db']['entries'] as $entry) {
            if ($entry['type'] === 'totp' && isset($entry['info'])) {
                $accounts[] = [
                    'issuer' => $entry['issuer'] ?? 'Unknown',
                    'account' => $entry['name'] ?? '',
                    'secret' => $entry['info']['secret'] ?? '',
                    'digits' => $entry['info']['digits'] ?? 6,
                    'period' => $entry['info']['period'] ?? 30,
                    'algorithm' => $entry['info']['algo'] ?? 'SHA1',
                ];
            }
        }

        return $accounts;
    }

    /**
     * 解析 URI 列表格式
     * 每行一个 otpauth:// URI
     * 
     * @param string $content 文本内容
     * @return array
     */
    private static function parseUriList(string $content): array
    {
        $lines = explode("\n", $content);
        $accounts = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || !str_starts_with($line, 'otpauth://')) {
                continue;
            }

            $account = self::parseOtpAuthUri($line);
            if ($account) {
                $accounts[] = $account;
            }
        }

        return $accounts;
    }

    /**
     * 解析 Google Authenticator 导出格式
     * 格式：otpauth-migration://offline?data=base64data
     * 
     * @param string $content URI内容
     * @return array
     */
    private static function parseGoogleExport(string $content): array
    {
        // Google导出格式使用Protocol Buffers编码
        // 这里提供简化版解析
        // 实际使用中建议用户导出为标准URI格式

        // 提取data参数
        if (!preg_match('/otpauth-migration:\/\/offline\?data=([^&\s]+)/', $content, $matches)) {
            return [];
        }

        $base64Data = $matches[1];
        try {
            $decoded = base64_decode($base64Data, true);
            if ($decoded === false) {
                return [];
            }

            // 简化的Protocol Buffers解析
            // 实际格式较复杂，这里仅做示例
            return self::parseProtobufData($decoded);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 简化的Protobuf数据解析
     * 
     * @param string $data 二进制数据
     * @return array
     */
    private static function parseProtobufData(string $data): array
    {
        // 这是一个简化版本
        // 实际的Google导出格式需要完整的Protobuf解析
        // 建议用户使用标准的otpauth:// URI格式导入
        
        // 返回空数组，并在前端提示用户使用其他格式
        return [];
    }

    /**
     * 解析 otpauth:// URI
     * 
     * @param string $uri otpauth URI
     * @return array|null
     */
    private static function parseOtpAuthUri(string $uri): ?array
    {
        $match = preg_match('/otpauth:\/\/(totp|hotp)\/([^?]+)\?(.+)/', $uri, $matches);
        if (!$match) {
            return null;
        }

        $type = $matches[1];
        $label = urldecode($matches[2]);
        parse_str($matches[3], $params);

        // 解析标签
        $issuer = '';
        $account = $label;
        if (str_contains($label, ':')) {
            [$issuer, $account] = explode(':', $label, 2);
            $issuer = trim($issuer);
            $account = trim($account);
        }

        // 如果有issuer参数，优先使用
        if (isset($params['issuer'])) {
            $issuer = $params['issuer'];
        }

        if (empty($params['secret'])) {
            return null;
        }

        return [
            'type' => $type,
            'issuer' => $issuer ?: 'Unknown',
            'account' => $account,
            'secret' => $params['secret'],
            'digits' => (int)($params['digits'] ?? 6),
            'period' => (int)($params['period'] ?? 30),
            'algorithm' => strtoupper($params['algorithm'] ?? 'SHA1'),
            'counter' => isset($params['counter']) ? (int)$params['counter'] : null,
        ];
    }

    /**
     * 标准化账户数据
     * 
     * @param array $item 原始数据
     * @return array|null
     */
    private static function normalizeAccount(array $item): ?array
    {
        // 如果包含URI，优先解析URI
        if (isset($item['uri']) && str_starts_with($item['uri'], 'otpauth://')) {
            return self::parseOtpAuthUri($item['uri']);
        }

        // 直接从字段提取
        if (!isset($item['secret']) || empty($item['secret'])) {
            return null;
        }

        return [
            'type' => $item['type'] ?? 'totp',
            'issuer' => $item['issuer'] ?? $item['label'] ?? 'Unknown',
            'account' => $item['account'] ?? $item['name'] ?? '',
            'secret' => $item['secret'],
            'digits' => (int)($item['digits'] ?? 6),
            'period' => (int)($item['period'] ?? 30),
            'algorithm' => strtoupper($item['algorithm'] ?? $item['algo'] ?? 'SHA1'),
        ];
    }

    /**
     * 导出账户为JSON格式
     * 
     * @param array $accounts 账户列表
     * @return string JSON字符串
     */
    public static function exportToJson(array $accounts): string
    {
        $exportData = [];
        
        foreach ($accounts as $account) {
            $exportData[] = [
                'issuer' => $account['issuer'] ?? '',
                'account' => $account['account'] ?? '',
                'secret' => $account['secret'] ?? '',
                'digits' => $account['digits'] ?? 6,
                'period' => $account['period'] ?? 30,
                'algorithm' => $account['algorithm'] ?? 'SHA1',
                'type' => 'totp',
            ];
        }

        return json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * 导出账户为URI列表格式
     * 
     * @param array $accounts 账户列表
     * @return string URI列表字符串
     */
    public static function exportToUriList(array $accounts): string
    {
        $uris = [];
        
        foreach ($accounts as $account) {
            $issuer = $account['issuer'] ?? 'Unknown';
            $accountName = $account['account'] ?? '';
            $secret = $account['secret'] ?? '';
            
            if (empty($secret)) {
                continue;
            }

            $params = [
                'secret' => $secret,
                'issuer' => $issuer,
                'algorithm' => $account['algorithm'] ?? 'SHA1',
                'digits' => $account['digits'] ?? 6,
                'period' => $account['period'] ?? 30,
            ];

            $uri = sprintf(
                'otpauth://totp/%s:%s?%s',
                rawurlencode($issuer),
                rawurlencode($accountName),
                http_build_query($params)
            );

            $uris[] = $uri;
        }

        return implode("\n", $uris);
    }

    /**
     * 导出为Aegis Authenticator格式
     * 
     * @param array $accounts 账户列表
     * @return string Aegis JSON格式
     */
    public static function exportToAegis(array $accounts): string
    {
        $entries = [];
        
        foreach ($accounts as $account) {
            $entries[] = [
                'type' => 'totp',
                'uuid' => self::generateUUID(),
                'name' => $account['account'] ?? '',
                'issuer' => $account['issuer'] ?? '',
                'note' => '',
                'icon' => null,
                'info' => [
                    'secret' => $account['secret'] ?? '',
                    'algo' => strtoupper($account['algorithm'] ?? 'SHA1'),
                    'digits' => (int)($account['digits'] ?? 6),
                    'period' => (int)($account['period'] ?? 30),
                ]
            ];
        }

        $aegisData = [
            'type' => 'totp',
            'version' => 1,
            'db' => [
                'version' => 2,
                'entries' => $entries
            ]
        ];

        return json_encode($aegisData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * 导出为andOTP格式
     * 
     * @param array $accounts 账户列表
     * @return string andOTP JSON格式
     */
    public static function exportToAndOTP(array $accounts): string
    {
        $entries = [];
        
        foreach ($accounts as $account) {
            $entries[] = [
                'secret' => $account['secret'] ?? '',
                'issuer' => $account['issuer'] ?? '',
                'label' => $account['account'] ?? '',
                'digits' => (int)($account['digits'] ?? 6),
                'type' => 'TOTP',
                'algorithm' => strtoupper($account['algorithm'] ?? 'SHA1'),
                'thumbnail' => 'Default',
                'last_used' => 0,
                'used_frequency' => 0,
                'period' => (int)($account['period'] ?? 30),
                'tags' => []
            ];
        }

        return json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * 导出为2FAS格式
     * 
     * @param array $accounts 账户列表
     * @return string 2FAS JSON格式
     */
    public static function exportTo2FAS(array $accounts): string
    {
        $services = [];
        
        foreach ($accounts as $account) {
            $services[] = [
                'otp' => [
                    'account' => $account['account'] ?? '',
                    'digits' => (int)($account['digits'] ?? 6),
                    'period' => (int)($account['period'] ?? 30),
                    'algorithm' => strtoupper($account['algorithm'] ?? 'SHA1'),
                    'secret' => $account['secret'] ?? '',
                    'issuer' => $account['issuer'] ?? ''
                ],
                'type' => 'totp',
                'name' => $account['issuer'] ?? 'Unknown',
                'icon' => null,
                'order' => [
                    'position' => count($services)
                ]
            ];
        }

        $data = [
            'version' => 2,
            'services' => $services,
            'groups' => []
        ];

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * 生成UUID
     * 
     * @return string UUID
     */
    private static function generateUUID(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * 获取所有支持的导出格式
     * 
     * @return array
     */
    public static function getExportFormats(): array
    {
        return [
            [
                'id' => 'weline',
                'name' => 'Weline验证器',
                'description' => '本应用的标准JSON格式',
                'extension' => 'json',
                'compatible' => ['Weline TwoFactorAuth']
            ],
            [
                'id' => 'aegis',
                'name' => 'Aegis Authenticator',
                'description' => 'Aegis应用可直接导入的JSON格式',
                'extension' => 'json',
                'compatible' => ['Aegis Authenticator (Android)']
            ],
            [
                'id' => 'andotp',
                'name' => 'andOTP',
                'description' => 'andOTP应用可导入的JSON格式',
                'extension' => 'json',
                'compatible' => ['andOTP (Android)']
            ],
            [
                'id' => '2fas',
                'name' => '2FAS',
                'description' => '2FAS应用可导入的JSON格式',
                'extension' => 'json',
                'compatible' => ['2FAS Authenticator']
            ],
            [
                'id' => 'uri_list',
                'name' => 'URI列表（通用）',
                'description' => '每行一个otpauth链接，兼容性最好',
                'extension' => 'txt',
                'compatible' => ['所有支持手动添加的验证器']
            ],
        ];
    }

    /**
     * 验证账户数据
     * 
     * @param array $account 账户数据
     * @return bool
     */
    public static function validateAccount(array $account): bool
    {
        // 必需字段
        if (empty($account['secret'])) {
            return false;
        }

        // 验证Base32格式
        $secret = str_replace([' ', '-'], '', $account['secret']);
        if (!preg_match('/^[A-Z2-7]+=*$/i', $secret)) {
            return false;
        }

        // 验证数字字段
        if (isset($account['digits']) && ($account['digits'] < 6 || $account['digits'] > 8)) {
            return false;
        }

        if (isset($account['period']) && ($account['period'] < 10 || $account['period'] > 120)) {
            return false;
        }

        return true;
    }

    /**
     * 获取支持的格式列表
     * 
     * @return array
     */
    public static function getSupportedFormats(): array
    {
        return [
            [
                'id' => 'json',
                'name' => 'JSON格式',
                'description' => '标准JSON格式备份文件',
                'extensions' => ['json'],
                'example' => '[{"issuer":"Example","account":"user@example.com","secret":"JBSWY3DPEHPK3PXP"}]'
            ],
            [
                'id' => 'uri_list',
                'name' => 'URI列表',
                'description' => '每行一个otpauth://链接',
                'extensions' => ['txt'],
                'example' => "otpauth://totp/Example:user@example.com?secret=JBSWY3DPEHPK3PXP\notpauth://totp/Another:test@test.com?secret=ABCDEFGHIJK"
            ],
            [
                'id' => 'aegis',
                'name' => 'Aegis Authenticator',
                'description' => 'Aegis应用的JSON导出',
                'extensions' => ['json'],
                'example' => '{"type":"totp","db":{"entries":[...]}}'
            ],
            [
                'id' => 'google_export',
                'name' => 'Google Authenticator导出',
                'description' => 'Google验证器的导出链接（需转换为URI格式）',
                'extensions' => ['txt'],
                'example' => 'otpauth-migration://offline?data=...'
            ]
        ];
    }
}

