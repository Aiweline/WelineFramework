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
 * 有道翻译适配器
 * 
 * 使用有道翻译API
 * 文档：https://ai.youdao.com/DOCSIRMA/html/自然语言翻译/API文档/文本翻译服务/文本翻译服务-API文档.html
 */
class YoudaoProvider extends AbstractProvider
{
    /**
     * 获取渠道代码
     */
    public function getProviderCode(): string
    {
        return 'youdao';
    }

    /**
     * 获取渠道名称
     */
    public function getProviderName(): string
    {
        return '有道翻译';
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
        $apiSecret = $provider->getData(TranslationProvider::fields_API_SECRET);
        
        if (empty($apiKey) || empty($apiSecret)) {
            throw new Exception(__('有道翻译API密钥未配置'));
        }

        $endpoint = $provider->getData(TranslationProvider::fields_API_ENDPOINT) 
            ?: 'https://openapi.youdao.com/api';

        // 标准化语言代码
        $targetLang = $this->normalizeLanguageCode($targetLanguage);
        $sourceLang = $sourceLanguage === 'auto' ? 'auto' : $this->normalizeLanguageCode($sourceLanguage);

        // 生成签名
        $salt = time();
        $signStr = $apiKey . $text . $salt . $apiSecret;
        $sign = md5($signStr);

        // 构建请求数据
        $data = [
            'q' => $text,
            'from' => $sourceLang,
            'to' => $targetLang,
            'appKey' => $apiKey,
            'salt' => $salt,
            'sign' => $sign,
        ];

        // 发送请求
        $response = $this->sendRequest($endpoint, $data, [], 'GET');

        // 检查错误
        if (isset($response['errorCode']) && $response['errorCode'] !== '0') {
            throw new Exception(__('有道翻译错误：%{1}', [$response['errorCode']]));
        }

        // 解析响应
        if (!isset($response['translation'][0])) {
            throw new Exception(__('有道翻译响应格式错误'));
        }

        $translatedText = $response['translation'][0];
        $detectedSourceLang = $response['l'] ?? $sourceLang;

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
        // 有道翻译API在翻译时会自动检测语言
        try {
            $result = $this->translate($provider, $text, 'en', 'auto');
            return $result['source_language'];
        } catch (\Exception $e) {
            throw new Exception(__('有道翻译语言检测失败：%{1}', [$e->getMessage()]));
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

