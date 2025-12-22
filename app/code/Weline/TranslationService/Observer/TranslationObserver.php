<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\TranslationService\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\TranslationService\Api\TranslationServiceInterface;

/**
 * 翻译事件观察者
 * 
 * 监听 Weline_TranslationService::translate 事件，执行翻译并设置结果
 */
class TranslationObserver implements ObserverInterface
{
    /**
     * @var TranslationServiceInterface
     */
    private TranslationServiceInterface $translationService;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->translationService = ObjectManager::getInstance(TranslationServiceInterface::class);
    }

    /**
     * 执行观察者逻辑
     * 
     * @param Event $event
     */
    public function execute(Event &$event): void
    {
        try {
            // 获取事件数据
            $text = $event->getData('text');
            $targetLanguage = $event->getData('target_language');
            $sourceLanguage = $event->getData('source_language') ?? 'auto';
            $providerCode = $event->getData('provider_code');
            $options = $event->getData('options') ?? [];
            $moduleName = $event->getData('module_name');

            // 验证必填参数
            if (empty($text)) {
                $event->setData('error', __('文本不能为空'));
                $event->setData('success', false);
                return;
            }

            if (empty($targetLanguage)) {
                $event->setData('error', __('目标语言不能为空'));
                $event->setData('success', false);
                return;
            }

            // 添加模块名称到选项
            if ($moduleName) {
                $options['module_name'] = $moduleName;
            }

            // 执行翻译
            try {
                $translatedText = $this->translationService->translate(
                    $text,
                    $targetLanguage,
                    $sourceLanguage,
                    $providerCode,
                    $options
                );

                // 设置翻译结果
                $event->setData('translated_text', $translatedText);
                $event->setData('success', true);
                $event->setData('error', null);
            } catch (\Exception $e) {
                // 翻译失败
                $event->setData('translated_text', '');
                $event->setData('success', false);
                $event->setData('error', $e->getMessage());
            }
        } catch (\Exception $e) {
            // 捕获所有异常，避免影响其他观察者
            $event->setData('success', false);
            $event->setData('error', __('事件处理异常: %{1}', [$e->getMessage()]));
        }
    }
}

