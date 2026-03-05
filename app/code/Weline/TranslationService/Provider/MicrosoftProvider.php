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
 * Microsoft翻译适配器
 * 
 * 使用Microsoft Translator API
 * 文档：https://docs.microsoft.com/azure/cognitive-services/translator/
 */
class MicrosoftProvider extends AbstractProvider
{
    /**
     * 获取渠道代码
     */
    public function getProviderCode(): string
    {
        return 'microsoft';
    }

    /**
     * 获取渠道名称
     */
    public function getProviderName(): string
    {
        return 'Microsoft翻译';
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
            throw new Exception(__('Microsoft翻译API密钥未配置'));
        }

        $endpoint = $provider->getData(TranslationProvider::schema_fields_API_ENDPOINT) 
            ?: 'https://api.cognitive.microsofttranslator.com/translate';

        // 标准化语言代码
        $targetLang = $this->normalizeLanguageCode($targetLanguage);
        $sourceLang = $sourceLanguage === 'auto' ? null : $this->normalizeLanguageCode($sourceLanguage);

        // 构建请求URL
        $url = $endpoint . '?api-version=3.0&to=' . urlencode($targetLang);
        if ($sourceLang) {
            $url .= '&from=' . urlencode($sourceLang);
        }

        // 构建请求数据
        $data = [
            ['Text' => $text]
        ];

        // 发送请求
        $response = $this->sendRequest($url, $data, [
            'Ocp-Apim-Subscription-Key: ' . $apiKey,
            'Content-Type: application/json',
        ]);

        // 解析响应
        if (!isset($response[0]['translations'][0]['text'])) {
            throw new Exception(__('Microsoft翻译响应格式错误'));
        }

        $translatedText = $response[0]['translations'][0]['text'];
        $detectedSourceLang = $response[0]['detectedLanguage']['language'] ?? $sourceLang ?? 'auto';

        return [
            'translated_text' => $translatedText,
            'source_language' => $detectedSourceLang,
            'target_language' => $targetLang,
            'character_count' => mb_strlen($text, 'UTF-8'),
            'response_time' => 0,
        ];
    }

    /**
     * 检测语言
     */
    public function detectLanguage(TranslationProvider $provider, string $text): string
    {
        $apiKey = $provider->getData(TranslationProvider::schema_fields_API_KEY);
        if (empty($apiKey)) {
            throw new Exception(__('Microsoft翻译API密钥未配置'));
        }

        $endpoint = 'https://api.cognitive.microsofttranslator.com/detect?api-version=3.0';

        $data = [
            ['Text' => $text]
        ];

        $response = $this->sendRequest($endpoint, $data, [
            'Ocp-Apim-Subscription-Key: ' . $apiKey,
            'Content-Type: application/json',
        ]);

        if (!isset($response[0]['language'])) {
            throw new Exception(__('Microsoft翻译语言检测失败'));
        }

        return $response[0]['language'];
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

