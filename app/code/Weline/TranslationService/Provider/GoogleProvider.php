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
use Weline\TranslationService\Model\TranslationProvider;

/**
 * Google翻译适配器
 * 
 * 使用Google Cloud Translation API
 * 文档：https://cloud.google.com/translate/docs
 */
class GoogleProvider extends AbstractProvider
{
    /**
     * 获取渠道代码
     */
    public function getProviderCode(): string
    {
        return 'google';
    }

    /**
     * 获取渠道名称
     */
    public function getProviderName(): string
    {
        return 'Google翻译';
    }

    /**
     * 翻译文本
     */
    public function translate(
        TranslationProvider $provider,
        string $text,
        string $targetLanguage,
        string $sourceLanguage = 'auto',
        array $options = []
    ): array {
        $apiKey = $provider->getData(TranslationProvider::schema_fields_API_KEY);
        if (empty($apiKey)) {
            throw new Exception(__('Google翻译API密钥未配置'));
        }

        $endpoint = $provider->getData(TranslationProvider::schema_fields_API_ENDPOINT) 
            ?: 'https://translation.googleapis.com/language/translate/v2';

        // 标准化语言代码
        $targetLang = $this->normalizeLanguageCode($targetLanguage);
        $sourceLang = $sourceLanguage === 'auto' ? null : $this->normalizeLanguageCode($sourceLanguage);

        // 构建请求数据
        $data = [
            'q' => $text,
            'target' => $targetLang,
        ];

        if ($sourceLang) {
            $data['source'] = $sourceLang;
        }

        // 构建请求URL
        $url = $endpoint . '?key=' . urlencode($apiKey);

        // 发送请求
        $response = $this->sendRequest($url, $data, [
            'Content-Type: application/json',
        ]);

        // 解析响应
        if (!isset($response['data']['translations'][0]['translatedText'])) {
            throw new Exception(__('Google翻译响应格式错误'));
        }

        $translatedText = $response['data']['translations'][0]['translatedText'];
        $detectedSourceLang = $response['data']['translations'][0]['detectedSourceLanguage'] ?? $sourceLang ?? 'auto';

        return [
            'translated_text' => $translatedText,
            'source_language' => $detectedSourceLang,
            'target_language' => $targetLang,
            'character_count' => mb_strlen($text, 'UTF-8'),
            'response_time' => 0, // 由调用方计算
        ];
    }

    /**
     * 检测语言
     */
    public function detectLanguage(TranslationProvider $provider, string $text): string
    {
        $apiKey = $provider->getData(TranslationProvider::schema_fields_API_KEY);
        if (empty($apiKey)) {
            throw new Exception(__('Google翻译API密钥未配置'));
        }

        $endpoint = 'https://translation.googleapis.com/language/translate/v2/detect';
        $url = $endpoint . '?key=' . urlencode($apiKey);

        $data = ['q' => $text];

        $response = $this->sendRequest($url, $data, [
            'Content-Type: application/json',
        ]);

        if (!isset($response['data']['detections'][0][0]['language'])) {
            throw new Exception(__('Google翻译语言检测失败'));
        }

        return $response['data']['detections'][0][0]['language'];
    }

    /**
     * 测试连接
     */
    public function testConnection(TranslationProvider $provider): bool
    {
        try {
            $this->translate($provider, 'Hello', 'zh', 'en');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}

