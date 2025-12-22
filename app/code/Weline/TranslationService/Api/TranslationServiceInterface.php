<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\TranslationService\Api;

/**
 * 翻译服务接口
 * 
 * 为其他模块提供统一的翻译服务调用接口
 */
interface TranslationServiceInterface
{
    /**
     * 翻译文本
     * 
     * @param string $text 要翻译的文本
     * @param string $targetLanguage 目标语言代码（ISO 639-1或BCP 47）
     * @param string $sourceLanguage 源语言代码（可选，auto表示自动检测）
     * @param string|null $providerCode 指定渠道代码（可选，不指定则自动选择）
     * @param array $options 额外选项（如module_name等）
     * @return string 翻译后的文本
     * @throws \Exception
     */
    public function translate(
        string $text,
        string $targetLanguage,
        string $sourceLanguage = 'auto',
        ?string $providerCode = null,
        array $options = []
    ): string;

    /**
     * 批量翻译
     * 
     * @param array $texts 要翻译的文本数组
     * @param string $targetLanguage 目标语言代码
     * @param string $sourceLanguage 源语言代码
     * @param string|null $providerCode 指定渠道代码
     * @param array $options 额外选项
     * @return array 翻译结果数组
     * @throws \Exception
     */
    public function batchTranslate(
        array $texts,
        string $targetLanguage,
        string $sourceLanguage = 'auto',
        ?string $providerCode = null,
        array $options = []
    ): array;

    /**
     * 检测语言
     * 
     * @param string $text 要检测的文本
     * @param string|null $providerCode 指定渠道代码
     * @return string 语言代码（ISO 639-1格式）
     * @throws \Exception
     */
    public function detectLanguage(string $text, ?string $providerCode = null): string;

    /**
     * 检查是否支持该语言
     * 
     * @param string $languageCode 语言代码
     * @param string|null $providerCode 指定渠道代码
     * @return bool
     */
    public function supportsLanguage(string $languageCode, ?string $providerCode = null): bool;

    /**
     * 获取所有可用的翻译渠道
     * 
     * @return array 渠道代码数组
     */
    public function getAvailableProviders(): array;
}

