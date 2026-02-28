<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\I18n\Observer;

use Weline\Ai\Service\TranslationService;
use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * AI翻译事件观察者
 * 
 * 监听 Weline_Ai::translate 事件，其他模块通过触发此事件调用AI翻译
 */
class AiTranslationObserver implements ObserverInterface
{
    /**
     * @var TranslationService
     */
    private TranslationService $translationService;

    /**
     * 构造函数
     */
    public function __construct(
        TranslationService $translationService
    ) {
        $this->translationService = $translationService;
    }

    /**
     * 执行事件观察者
     * 
     * @param Event $event
     */
    public function execute(Event &$event): void
    {
        try {
            // 获取事件数据
            $words = $event->getData('words');
            $targetLocale = $event->getData('target_locale');
            $sourceLocale = $event->getData('source_locale') ?? 'auto';
            $strategy = $event->getData('strategy') ?? 'light';

            // 验证必填参数
            if (empty($words) || !is_array($words)) {
                $this->setEventError($event, __('参数错误：words 必须是非空数组'));
                return;
            }

            if (empty($targetLocale)) {
                $this->setEventError($event, __('参数错误：target_locale 不能为空'));
                return;
            }

            // 调用AI翻译服务
            try {
                $translations = $this->translationService->batchTranslate(
                    $words,
                    $targetLocale,
                    $sourceLocale,
                    $strategy
                );

                // 设置翻译结果
                $event->setData('translations', $translations);
                $event->setData('success', true);
                $event->setData('errors', []);
            } catch (\Weline\Framework\App\Exception $e) {
                // AI服务异常
                $errorMessage = $e->getMessage();
                
                // 发送系统消息通知
                $this->sendSystemMessage(
                    __('AI翻译调用失败'),
                    __(
                        "目标语言：%{locale}\n词数：%{count}\n错误信息：%{error}",
                        [
                            'locale' => $targetLocale,
                            'count' => count($words),
                            'error' => $errorMessage
                        ]
                    ),
                    'ri-error-warning-line'
                );

                $this->setEventError($event, $errorMessage);
            } catch (\Exception $e) {
                // 其他异常
                $errorMessage = $e->getMessage();
                
                // 发送系统消息通知
                $this->sendSystemMessage(
                    __('AI翻译异常'),
                    __(
                        "目标语言：%{locale}\n词数：%{count}\n异常信息：%{error}\n异常位置：%{file}:%{line}",
                        [
                            'locale' => $targetLocale,
                            'count' => count($words),
                            'error' => $errorMessage,
                            'file' => $e->getFile(),
                            'line' => $e->getLine()
                        ]
                    ),
                    'ri-alarm-warning-line'
                );

                $this->setEventError($event, $errorMessage);
            }
        } catch (\Exception $e) {
            // 捕获所有异常，避免影响其他观察者
            $this->setEventError($event, __('事件处理异常: %{1}', [$e->getMessage()]));
        }
    }

    /**
     * 设置事件错误信息
     * 
     * @param Event $event
     * @param string $errorMessage
     */
    private function setEventError(Event &$event, string $errorMessage): void
    {
        $event->setData('success', false);
        $event->setData('translations', []);
        
        $errors = $event->getData('errors') ?? [];
        $errors[] = $errorMessage;
        $event->setData('errors', $errors);
    }

    /**
     * 发送系统消息通知
     * 
     * @param string $title 标题
     * @param string $content 内容
     * @param string $icon 图标
     */
    private function sendSystemMessage(string $title, string $content, string $icon = 'ri-translate'): void
    {
        try {
            w_msg(
                'ai_translation',
                'warning',
                $title,
                $content,
                ['icon' => $icon, 'source_module' => 'Weline_I18n']
            );
        } catch (\Exception $e) {
            Env::log_error('i18n', "发送AI翻译事件系统消息失败: " . $e->getMessage());
        }
    }
}

