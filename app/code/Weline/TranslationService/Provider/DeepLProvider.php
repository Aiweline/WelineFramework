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
 * DeepL翻译适配器
 * 
 * 使用DeepL API
 * 文档：https://www.deepl.com/docs-api
 */
class DeepLProvider extends AbstractProvider
{
    /**
     * 获取渠道代码
     */
    public function getProviderCode(): string
    {
        return 'deepl';
    }

    /**
     * 获取渠道名称
     */
    public function getProviderName(): string
    {
        return 'DeepL翻译';
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
        $apiKey = $provider->getData(TranslationProvider::fields_API_KEY);
        if (empty($apiKey)) {
            throw new Exception(__('DeepL翻译API密钥未配置'));
        }

        $endpoint = $provider->getData(TranslationProvider::fields_API_ENDPOINT) 
            ?: 'https://api-free.deepl.com/v2/translate';

        // DeepL使用大写语言代码（如EN、ZH）
        $targetLang = strtoupper($this->normalizeLanguageCode($targetLanguage));
        $sourceLang = $sourceLanguage === 'auto' ? null : strtoupper($this->normalizeLanguageCode($sourceLanguage));

        // 构建请求数据
        $data = [
            'text' => $text,
            'target_lang' => $targetLang,
        ];

        if ($sourceLang) {
            $data['source_lang'] = $sourceLang;
        }

        // 发送请求
        $response = $this->sendRequest($endpoint, $data, [
            'Authorization: DeepL-Auth-Key ' . $apiKey,
            'Content-Type: application/json',
        ]);

        // 解析响应
        if (!isset($response['translations'][0]['text'])) {
            throw new Exception(__('DeepL翻译响应格式错误'));
        }

        $translatedText = $response['translations'][0]['text'];
        $detectedSourceLang = $response['translations'][0]['detected_source_language'] ?? $sourceLang ?? 'auto';

        return [
            'translated_text' => $translatedText,
            'source_language' => strtolower($detectedSourceLang),
            'target_language' => strtolower($targetLang),
            'character_count' => mb_strlen($text, 'UTF-8'),
            'response_time' => 0,
        ];
    }

    /**
     * 检测语言
     */
    public function detectLanguage(TranslationProvider $provider, string $text): string
    {
        // DeepL在翻译时会自动检测语言
        try {
            $result = $this->translate($provider, $text, 'en', 'auto');
            return $result['source_language'];
        } catch (\Exception $e) {
            throw new Exception(__('DeepL翻译语言检测失败：%{1}', [$e->getMessage()]));
        }
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

