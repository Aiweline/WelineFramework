<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\TranslationService\Provider;

use Weline\Framework\App\Exception;
use Weline\TranslationService\Api\ProviderInterface;
use Weline\TranslationService\Model\TranslationProvider;

/**
 * 翻译渠道适配器抽象类
 * 
 * 提供通用的实现，子类只需实现具体的API调用逻辑
 */
abstract class AbstractProvider implements ProviderInterface
{
    /**
     * 语言代码映射（ISO 639-1到各服务商格式）
     * 
     * @var array
     */
    protected array $languageMap = [];

    /**
     * 标准化语言代码（ISO 639-1格式）
     * 
     * @param string $languageCode 语言代码（可能是BCP 47格式，如zh_CN）
     * @return string ISO 639-1格式（如zh）
     */
    protected function normalizeLanguageCode(string $languageCode): string
    {
        // 如果是BCP 47格式（如zh_CN、en_US），提取语言部分
        if (strpos($languageCode, '_') !== false) {
            $languageCode = explode('_', $languageCode)[0];
        }
        
        // 转换为小写
        $languageCode = strtolower($languageCode);
        
        // 如果有映射，使用映射后的代码
        if (isset($this->languageMap[$languageCode])) {
            return $this->languageMap[$languageCode];
        }
        
        return $languageCode;
    }

    /**
     * 发送HTTP请求
     * 
     * @param string $url 请求URL
     * @param array $data 请求数据
     * @param array $headers 请求头
     * @param string $method 请求方法
     * @return array 响应数据
     * @throws Exception
     */
    protected function sendRequest(
        string $url,
        array $data = [],
        array $headers = [],
        string $method = 'POST'
    ): array {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        // 设置请求方法
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? json_encode($data) : $data);
            }
        } elseif ($method === 'GET') {
            if (!empty($data)) {
                $url .= '?' . http_build_query($data);
                curl_setopt($ch, CURLOPT_URL, $url);
            }
        }

        // 设置请求头
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);

        if ($error) {
            throw new Exception(__('请求失败：%{1}', [$error]));
        }

        if ($httpCode !== 200) {
            throw new Exception(__('请求失败，HTTP状态码：%{1}', [$httpCode]));
        }

        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception(__('响应解析失败：%{1}', [json_last_error_msg()]));
        }

        return $result;
    }

    /**
     * 检查是否支持该语言
     * 
     * @param TranslationProvider $provider
     * @param string $languageCode
     * @return bool
     */
    public function supportsLanguage(TranslationProvider $provider, string $languageCode): bool
    {
        return $provider->supportsLanguage($languageCode);
    }

    /**
     * 批量翻译（默认实现：循环调用单个翻译）
     * 
     * 子类可以重写此方法以实现更高效的批量翻译
     * 
     * @param TranslationProvider $provider
     * @param array $texts
     * @param string $targetLanguage
     * @param string $sourceLanguage
     * @param array $options
     * @return array
     * @throws Exception
     */
    public function batchTranslate(
        TranslationProvider $provider,
        array $texts,
        string $targetLanguage,
        string $sourceLanguage = 'auto',
        array $options = []
    ): array {
        $results = [];
        foreach ($texts as $text) {
            $result = $this->translate($provider, $text, $targetLanguage, $sourceLanguage, $options);
            $results[] = $result['translated_text'] ?? $text;
        }
        return $results;
    }
}

