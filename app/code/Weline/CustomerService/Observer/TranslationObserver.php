<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\CustomerService\Observer;

use Weline\Ai\Service\AiService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * 翻译事件观察者
 * 使用 AI 服务进行实时翻译
 */
class TranslationObserver implements ObserverInterface
{
    private AiService $aiService;

    public function __construct(AiService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * 执行翻译
     *
     * @param Event $event
     * @return void
     */
    public function execute(Event $event): void
    {
        $data = $event->getData('data');
        
        if (!$data) {
            return;
        }

        $text = $data['text'] ?? '';
        $texts = $data['texts'] ?? [];
        $sourceLocale = $data['source_locale'] ?? 'auto';
        $targetLocale = $data['target_locale'] ?? 'zh_Hans_CN';
        $context = $data['context'] ?? 'customer_service';

        // 如果源语言和目标语言相同，不需要翻译
        if ($sourceLocale !== 'auto' && $sourceLocale === $targetLocale) {
            $event->setData('success', true);
            if (!empty($text)) {
                $event->setData('translated_text', $text);
            }
            if (!empty($texts)) {
                $event->setData('translated_texts', $texts);
            }
            return;
        }

        try {
            // 单条文本翻译
            if (!empty($text)) {
                $translatedText = $this->translateText($text, $sourceLocale, $targetLocale);
                $event->setData('translated_text', $translatedText);
                $event->setData('success', true);
            }

            // 批量文本翻译
            if (!empty($texts)) {
                $translatedTexts = [];
                foreach ($texts as $key => $textItem) {
                    $translatedTexts[$key] = $this->translateText($textItem, $sourceLocale, $targetLocale);
                }
                $event->setData('translated_texts', $translatedTexts);
                $event->setData('success', true);
            }
        } catch (\Exception $e) {
            error_log('[CustomerService] TranslationObserver error: ' . $e->getMessage());
            $event->setData('success', false);
            $event->setData('error', $e->getMessage());
        }
    }

    /**
     * 使用 AI 翻译文本
     *
     * @param string $text 待翻译文本
     * @param string $sourceLocale 源语言
     * @param string $targetLocale 目标语言
     * @return string 翻译后的文本
     */
    private function translateText(string $text, string $sourceLocale, string $targetLocale): string
    {
        if (empty(trim($text))) {
            return $text;
        }

        // 构建翻译提示词
        $sourceLangName = $this->getLanguageName($sourceLocale);
        $targetLangName = $this->getLanguageName($targetLocale);

        $prompt = $this->buildTranslationPrompt($text, $sourceLangName, $targetLangName);

        try {
            // 调用 AI 服务进行翻译
            $translatedText = $this->aiService->generate(
                $prompt,
                null, // 使用默认模型
                'translation', // 翻译场景
                null,
                [
                    'temperature' => 0.3, // 低温度，更精确的翻译
                    'max_tokens' => 2000,
                ]
            );

            // 清理翻译结果
            $translatedText = $this->cleanTranslationResult($translatedText);

            return $translatedText ?: $text;
        } catch (\Exception $e) {
            error_log('[CustomerService] Translation failed: ' . $e->getMessage());
            // 翻译失败时返回原文
            return $text;
        }
    }

    /**
     * 构建翻译提示词
     *
     * @param string $text 待翻译文本
     * @param string $sourceLang 源语言名称
     * @param string $targetLang 目标语言名称
     * @return string
     */
    private function buildTranslationPrompt(string $text, string $sourceLang, string $targetLang): string
    {
        return <<<PROMPT
You are a professional translator for customer service conversations. 
Translate the following text from {$sourceLang} to {$targetLang}.

Requirements:
1. Keep the translation natural and conversational
2. Preserve the original meaning and tone
3. Only output the translated text, no explanations or additional content
4. If the text is already in the target language, return it as is

Text to translate:
{$text}

Translation:
PROMPT;
    }

    /**
     * 清理翻译结果
     *
     * @param string $result AI 返回的结果
     * @return string 清理后的文本
     */
    private function cleanTranslationResult(string $result): string
    {
        // 移除可能的前缀
        $prefixes = ['Translation:', 'Translated text:', '翻译:', '翻译结果:'];
        foreach ($prefixes as $prefix) {
            if (stripos($result, $prefix) === 0) {
                $result = substr($result, strlen($prefix));
            }
        }

        return trim($result);
    }

    /**
     * 获取语言名称
     *
     * @param string $locale 语言代码
     * @return string 语言名称
     */
    private function getLanguageName(string $locale): string
    {
        $languages = [
            'zh_Hans_CN' => 'Simplified Chinese',
            'zh_Hant_TW' => 'Traditional Chinese',
            'en_US' => 'English',
            'en_GB' => 'British English',
            'ja_JP' => 'Japanese',
            'ko_KR' => 'Korean',
            'fr_FR' => 'French',
            'de_DE' => 'German',
            'es_ES' => 'Spanish',
            'pt_BR' => 'Portuguese',
            'ru_RU' => 'Russian',
            'ar_SA' => 'Arabic',
            'th_TH' => 'Thai',
            'vi_VN' => 'Vietnamese',
            'auto' => 'auto-detected language',
        ];

        return $languages[$locale] ?? $locale;
    }
}
