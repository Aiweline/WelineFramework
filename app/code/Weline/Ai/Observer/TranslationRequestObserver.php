<?php

declare(strict_types=1);

namespace Weline\Ai\Observer;

use Weline\Ai\Service\TranslationService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * AI-side implementation of the neutral I18n machine-translation request.
 *
 * Keeping the implementation in Weline_Ai prevents Weline_I18n from loading
 * or depending on AI internals when the optional AI module is absent.
 */
final class TranslationRequestObserver implements ObserverInterface
{
    public function __construct(
        private readonly TranslationService $translationService,
    ) {
    }

    public function execute(Event &$event): void
    {
        try {
            $words = $event->getData('words');
            $targetLocale = (string)($event->getData('target_locale') ?? '');
            $sourceLocale = (string)($event->getData('source_locale') ?? 'auto');
            $strategy = (string)($event->getData('strategy') ?? 'light');

            if (!is_array($words) || $words === []) {
                $this->setEventError($event, (string)__('参数错误：words 必须是非空数组'));
                return;
            }
            if ($targetLocale === '') {
                $this->setEventError($event, (string)__('参数错误：target_locale 不能为空'));
                return;
            }

            $translations = $this->translationService->batchTranslate(
                $words,
                $targetLocale,
                $sourceLocale,
                $strategy,
            );
            $event->setData('translations', $translations);
            $event->setData('success', true);
            $event->setData('errors', []);
        } catch (\Throwable $throwable) {
            $message = $throwable->getMessage();
            $this->sendSystemMessage(
                (string)__('AI翻译调用失败'),
                (string)__(
                    "目标语言：%{locale}\n词数：%{count}\n错误信息：%{error}",
                    [
                        'locale' => (string)($event->getData('target_locale') ?? ''),
                        'count' => (string)count((array)($event->getData('words') ?? [])),
                        'error' => $message,
                    ],
                ),
                'ri-error-warning-line',
            );
            $this->setEventError($event, $message);
        }
    }

    private function setEventError(Event $event, string $errorMessage): void
    {
        $event->setData('success', false);
        $event->setData('translations', []);
        $errors = (array)($event->getData('errors') ?? []);
        $errors[] = $errorMessage;
        $event->setData('errors', $errors);
    }

    private function sendSystemMessage(string $title, string $content, string $icon): void
    {
        try {
            w_msg(
                'ai_translation',
                'warning',
                $title,
                $content,
                ['icon' => $icon, 'source_module' => 'Weline_Ai'],
            );
        } catch (\Throwable $throwable) {
            w_log_error(
                (string)__('发送 AI 翻译系统消息失败：%{1}', [$throwable->getMessage()]),
                [],
                'ai',
            );
        }
    }
}
