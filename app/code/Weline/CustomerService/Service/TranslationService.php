<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\CustomerService\Service;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

/**
 * 翻译服务
 * 通过事件机制调用翻译模块进行翻译
 */
class TranslationService
{
    private EventsManager $eventsManager;

    public function __construct(
        EventsManager $eventsManager
    ) {
        $this->eventsManager = $eventsManager;
    }

    /**
     * 翻译文本
     * 
     * @param string $text 待翻译文本
     * @param string $targetLocale 目标语言
     * @param string $sourceLocale 源语言（可选，默认auto）
     * @param string|null $sessionId 会话ID（可选）
     * @return string 翻译后的文本，如果翻译失败则返回原文
     */
    public function translate(
        string $text,
        string $targetLocale,
        string $sourceLocale = 'auto',
        ?string $sessionId = null
    ): string {
        if (empty($text)) {
            return $text;
        }

        // 如果源语言和目标语言相同，不需要翻译
        if ($sourceLocale !== 'auto' && $sourceLocale === $targetLocale) {
            return $text;
        }

        // 准备事件数据
        $eventData = [
            'text' => $text,
            'source_locale' => $sourceLocale,
            'target_locale' => $targetLocale,
            'context' => 'customer_service',
            'session_id' => $sessionId
        ];

        // 触发翻译事件
        $this->eventsManager->dispatch('Weline_CustomerService::translate', $eventData);

        // 从事件数据中获取翻译结果
        $translatedText = $eventData['translated_text'] ?? null;
        $success = $eventData['success'] ?? false;

        // 如果翻译成功，返回翻译结果；否则返回原文
        if ($success && !empty($translatedText)) {
            return $translatedText;
        }

        return $text;
    }

    /**
     * 批量翻译文本
     * 
     * @param array $texts 待翻译文本数组
     * @param string $targetLocale 目标语言
     * @param string $sourceLocale 源语言（可选，默认auto）
     * @param string|null $sessionId 会话ID（可选）
     * @return array 翻译后的文本数组，键值对应
     */
    public function batchTranslate(
        array $texts,
        string $targetLocale,
        string $sourceLocale = 'auto',
        ?string $sessionId = null
    ): array {
        if (empty($texts)) {
            return [];
        }

        // 如果源语言和目标语言相同，不需要翻译
        if ($sourceLocale !== 'auto' && $sourceLocale === $targetLocale) {
            return $texts;
        }

        // 准备事件数据
        $eventData = [
            'texts' => $texts,
            'source_locale' => $sourceLocale,
            'target_locale' => $targetLocale,
            'context' => 'customer_service',
            'session_id' => $sessionId
        ];

        // 触发翻译事件
        $this->eventsManager->dispatch('Weline_CustomerService::translate', $eventData);

        // 从事件数据中获取翻译结果
        $translatedTexts = $eventData['translated_texts'] ?? null;
        $success = $eventData['success'] ?? false;

        // 如果翻译成功，返回翻译结果；否则返回原文
        if ($success && !empty($translatedTexts) && is_array($translatedTexts)) {
            return $translatedTexts;
        }

        return $texts;
    }
}

