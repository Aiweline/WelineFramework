<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Currency\Api;

use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;

/**
 * 汇率API服务实现
 * 
 * 实现exchangerate-api.com免费API
 * API文档：https://www.exchangerate-api.com/docs/free
 */
class ExchangeRateApi implements ExchangeRateApiInterface
{
    /**
     * API基础URL
     */
    private const API_BASE_URL = 'https://api.exchangerate-api.com/v4/latest/';

    /**
     * API密钥（可选，免费版不需要）
     */
    private ?string $apiKey = null;

    /**
     * 请求超时时间（秒）
     */
    private int $timeout = 30;

    /**
     * 最大重试次数
     */
    private int $maxRetries = 3;

    /**
     * 重试延迟（秒）
     */
    private array $retryDelays = [1, 2, 4];

    /**
     * 支持的货币列表（常用货币）
     */
    private array $supportedCurrencies = [
        'USD', 'EUR', 'GBP', 'JPY', 'CNY', 'AUD', 'CAD', 'CHF', 'HKD', 'SGD',
        'NZD', 'KRW', 'INR', 'BRL', 'MXN', 'RUB', 'ZAR', 'SEK', 'NOK', 'DKK',
        'PLN', 'THB', 'MYR', 'IDR', 'PHP', 'VND', 'TRY', 'AED', 'SAR', 'ILS'
    ];

    /**
     * 货币信息映射（包含符号、位置等）
     */
    private array $currencyInfoMap = [
        'USD' => ['symbol' => '$', 'position' => 'left', 'icon' => '$', 'thousand_separator' => ',', 'decimal_separator' => '.'],
        'EUR' => ['symbol' => '€', 'position' => 'right', 'icon' => '€', 'thousand_separator' => '.', 'decimal_separator' => ','],
        'GBP' => ['symbol' => '£', 'position' => 'left', 'icon' => '£', 'thousand_separator' => ',', 'decimal_separator' => '.'],
        'JPY' => ['symbol' => '¥', 'position' => 'left', 'icon' => '¥', 'thousand_separator' => ',', 'decimal_separator' => '.'],
        'CNY' => ['symbol' => '￥', 'position' => 'left', 'icon' => '￥', 'thousand_separator' => ',', 'decimal_separator' => '.'],
        'AUD' => ['symbol' => 'A$', 'position' => 'left', 'icon' => 'A$', 'thousand_separator' => ',', 'decimal_separator' => '.'],
        'CAD' => ['symbol' => 'C$', 'position' => 'left', 'icon' => 'C$', 'thousand_separator' => ',', 'decimal_separator' => '.'],
        'CHF' => ['symbol' => 'CHF', 'position' => 'left', 'icon' => 'CHF', 'thousand_separator' => "'", 'decimal_separator' => '.'],
        'HKD' => ['symbol' => 'HK$', 'position' => 'left', 'icon' => 'HK$', 'thousand_separator' => ',', 'decimal_separator' => '.'],
        'SGD' => ['symbol' => 'S$', 'position' => 'left', 'icon' => 'S$', 'thousand_separator' => ',', 'decimal_separator' => '.'],
    ];

    /**
     * 构造函数
     * 
     * @param string|null $apiKey API密钥（可选）
     */
    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * 获取实时汇率
     * 
     * @param string $baseCurrency 基准货币代码
     * @param array $targetCurrencies 目标货币代码数组
     * @return array 返回汇率数组
     * @throws \Exception
     */
    public function getExchangeRates(string $baseCurrency, array $targetCurrencies = []): array
    {
        $baseCurrency = strtoupper($baseCurrency);
        
        // 构建API URL
        $url = self::API_BASE_URL . $baseCurrency;
        
        // 发送HTTP请求（带重试机制）
        $response = $this->makeRequest($url);
        
        if (!isset($response['rates']) || !is_array($response['rates'])) {
            throw new Exception(__('API返回数据格式错误'));
        }
        
        $rates = $response['rates'];
        
        // 如果指定了目标货币，只返回这些货币的汇率
        if (!empty($targetCurrencies)) {
            $targetCurrencies = array_map('strtoupper', $targetCurrencies);
            $rates = array_intersect_key($rates, array_flip($targetCurrencies));
        }
        
        return $rates;
    }

    /**
     * 获取单个货币的汇率
     * 
     * @param string $baseCurrency 基准货币代码
     * @param string $targetCurrency 目标货币代码
     * @return float 汇率值
     * @throws \Exception
     */
    public function getExchangeRate(string $baseCurrency, string $targetCurrency): float
    {
        $rates = $this->getExchangeRates($baseCurrency, [$targetCurrency]);
        
        $targetCurrency = strtoupper($targetCurrency);
        
        if (!isset($rates[$targetCurrency])) {
            throw new Exception(__('无法获取货币 %{1} 的汇率', $targetCurrency));
        }
        
        return (float)$rates[$targetCurrency];
    }

    /**
     * 获取支持的货币列表
     * 
     * @return array 货币代码数组
     */
    public function getSupportedCurrencies(): array
    {
        return $this->supportedCurrencies;
    }

    /**
     * 获取货币详细信息
     * 
     * @param string $currencyCode 货币代码
     * @return array|null 货币信息数组
     */
    public function getCurrencyInfo(string $currencyCode): ?array
    {
        $currencyCode = strtoupper($currencyCode);
        
        if (isset($this->currencyInfoMap[$currencyCode])) {
            $info = $this->currencyInfoMap[$currencyCode];
            $info['code'] = $currencyCode;
            return $info;
        }
        
        return null;
    }

    /**
     * 测试API连接
     * 
     * @return bool 连接成功返回true
     * @throws \Exception 连接失败时抛出异常，包含详细错误信息
     */
    public function testConnection(): bool
    {
        try {
            // 测试获取USD到EUR的汇率（这是最常用的货币对，API应该支持）
            $rate = $this->getExchangeRate('USD', 'EUR');
            
            // 验证汇率是否合理（应该在0.5到2.0之间）
            if ($rate <= 0 || $rate > 10) {
                throw new Exception(__('API返回的汇率数据异常: %{1}', $rate));
            }
            
            return true;
        } catch (\Exception $e) {
            // 重新抛出异常，让调用者能够获取详细错误信息
            throw $e;
        }
    }

    /**
     * 获取API提供者名称
     * 
     * @return string
     */
    public function getProviderName(): string
    {
        return 'exchangerate-api';
    }

    /**
     * 发送HTTP请求（带重试机制）
     * 
     * @param string $url 请求URL
     * @return array 响应数据
     * @throws \Exception
     */
    private function makeRequest(string $url): array
    {
        $lastException = null;
        
        for ($attempt = 0; $attempt < $this->maxRetries; $attempt++) {
            try {
                // 初始化cURL
                $ch = curl_init();
                
                // 设置cURL选项
                // 检查是否在开发环境（本地开发环境通常没有CA证书）
                $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
                
                // 判断是否为开发环境
                $isDev = (defined('DEV') && DEV) ||
                         (defined('DEBUG') && DEBUG) ||
                         getenv('APP_ENV') === 'development' || 
                         getenv('deploy') === 'dev' ||
                         (defined('ENV') && ENV === 'dev') ||
                         (isset($_ENV['deploy']) && $_ENV['deploy'] === 'dev') ||
                         (isset($_SERVER['HTTP_HOST']) && (
                             strpos($_SERVER['HTTP_HOST'], 'localhost') !== false ||
                             strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false ||
                             strpos($_SERVER['HTTP_HOST'], '::1') !== false
                         ));
                
                // 本地开发环境或明确禁用SSL验证时，跳过SSL验证
                // Windows环境默认禁用，其他环境如果是开发环境也禁用
                $disableSslVerify = getenv('CURRENCY_DISABLE_SSL_VERIFY') === '1' ||
                                   $isWindows ||
                                   $isDev;
                
                $curlOptions = [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => $this->timeout,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                    CURLOPT_USERAGENT => 'Weline Currency Module/1.0',
                ];
                
                // SSL验证设置
                if ($disableSslVerify) {
                    // 开发环境或Windows环境禁用SSL验证
                    $curlOptions[CURLOPT_SSL_VERIFYPEER] = false;
                    $curlOptions[CURLOPT_SSL_VERIFYHOST] = 0;
                } else {
                    // 生产环境启用SSL验证，尝试设置CA证书路径
                    $curlOptions[CURLOPT_SSL_VERIFYPEER] = true;
                    $curlOptions[CURLOPT_SSL_VERIFYHOST] = 2;
                    
                    // 尝试查找CA证书包
                    $caPaths = [
                        ini_get('curl.cainfo'),
                        ini_get('openssl.cafile'),
                        '/etc/ssl/certs/ca-certificates.crt', // Debian/Ubuntu
                        '/etc/pki/tls/certs/ca-bundle.crt',   // CentOS/RHEL
                    ];
                    
                    if ($isWindows) {
                        $caPaths = array_merge($caPaths, [
                            getenv('WINDIR') . '\\System32\\curl-ca-bundle.crt',
                            getenv('WINDIR') . '\\System32\\ca-bundle.crt',
                        ]);
                    }
                    
                    foreach ($caPaths as $caPath) {
                        if ($caPath && file_exists($caPath)) {
                            $curlOptions[CURLOPT_CAINFO] = $caPath;
                            break;
                        }
                    }
                }
                
                curl_setopt_array($ch, $curlOptions);
                
                // 执行请求
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                
                curl_close($ch);
                
                // 检查HTTP错误
                if ($response === false || !empty($error)) {
                    throw new Exception(__('API请求失败: %{1}', $error ?: 'Unknown error'));
                }
                
                // 检查HTTP状态码
                if ($httpCode !== 200) {
                    throw new Exception(__('API返回错误状态码: %{1}', $httpCode));
                }
                
                // 解析JSON响应
                $data = json_decode($response, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception(__('API返回数据解析失败: %{1}', json_last_error_msg()));
                }
                
                return $data;
                
            } catch (\Exception $e) {
                $lastException = $e;
                
                // 如果不是最后一次尝试，等待后重试
                if ($attempt < $this->maxRetries - 1) {
                    $delay = $this->retryDelays[$attempt] ?? 1;
                    sleep($delay);
                }
            }
        }
        
        // 所有重试都失败，抛出最后一个异常
        throw new Exception(__('API请求失败，已重试 %{1} 次: %{2}', [$this->maxRetries, $lastException->getMessage()]));
    }
}

