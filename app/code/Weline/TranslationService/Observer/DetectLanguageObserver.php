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
 * 语言检测事件观察者
 * 
 * 监听 Weline_TranslationService::detect_language 事件，执行语言检测并设置结果
 */
class DetectLanguageObserver implements ObserverInterface
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
            $providerCode = $event->getData('provider_code');

            // 验证必填参数
            if (empty($text)) {
                $event->setData('error', __('文本不能为空'));
                $event->setData('success', false);
                return;
            }

            // 执行语言检测
            try {
                $detectedLanguage = $this->translationService->detectLanguage($text, $providerCode);

                // 设置检测结果
                $event->setData('detected_language', $detectedLanguage);
                $event->setData('success', true);
                $event->setData('error', null);
            } catch (\Exception $e) {
                // 检测失败
                $event->setData('detected_language', null);
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

