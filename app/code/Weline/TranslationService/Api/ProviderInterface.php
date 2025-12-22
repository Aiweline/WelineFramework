<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\TranslationService\Api;

use Weline\TranslationService\Model\TranslationProvider;

/**
 * 翻译渠道提供者接口
 * 
 * 所有翻译渠道适配器必须实现此接口
 */
interface ProviderInterface
{
    /**
     * 翻译文本
     * 
     * @param TranslationProvider $provider 渠道配置
     * @param string $text 要翻译的文本
     * @param string $targetLanguage 目标语言代码（ISO 639-1或BCP 47）
     * @param string $sourceLanguage 源语言代码（ISO 639-1或BCP 47，可选，auto表示自动检测）
     * @param array $options 额外选项
     * @return array 返回数组包含：translated_text, source_language, target_language, character_count, response_time
     * @throws \Exception
     */
    public function translate(
        TranslationProvider $provider,
        string $text,
        string $targetLanguage,
        string $sourceLanguage = 'auto',
        array $options = []
    ): array;

    /**
     * 批量翻译
     * 
     * @param TranslationProvider $provider 渠道配置
     * @param array $texts 要翻译的文本数组
     * @param string $targetLanguage 目标语言代码
     * @param string $sourceLanguage 源语言代码
     * @param array $options 额外选项
     * @return array 返回翻译结果数组
     * @throws \Exception
     */
    public function batchTranslate(
        TranslationProvider $provider,
        array $texts,
        string $targetLanguage,
        string $sourceLanguage = 'auto',
        array $options = []
    ): array;

    /**
     * 检测语言
     * 
     * @param TranslationProvider $provider 渠道配置
     * @param string $text 要检测的文本
     * @return string 返回语言代码
     * @throws \Exception
     */
    public function detectLanguage(TranslationProvider $provider, string $text): string;

    /**
     * 检查是否支持该语言
     * 
     * @param TranslationProvider $provider 渠道配置
     * @param string $languageCode 语言代码
     * @return bool
     */
    public function supportsLanguage(TranslationProvider $provider, string $languageCode): bool;

    /**
     * 获取渠道代码
     * 
     * @return string
     */
    public function getProviderCode(): string;

    /**
     * 获取渠道名称
     * 
     * @return string
     */
    public function getProviderName(): string;

    /**
     * 测试连接
     * 
     * @param TranslationProvider $provider 渠道配置
     * @return bool
     * @throws \Exception
     */
    public function testConnection(TranslationProvider $provider): bool;
}

