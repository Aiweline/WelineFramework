<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Service;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Event\AbstractSeoEvent;

/**
 * SEO事件分发器
 * 
 * 提供标准化的事件分发接口，确保事件数据符合契约
 * 
 * @package Weline_Seo
 */
class EventDispatcher
{
    private EventsManager $eventsManager;

    public function __construct(EventsManager $eventsManager)
    {
        $this->eventsManager = $eventsManager;
    }

    /**
     * 分发SEO事件
     * 
     * @param AbstractSeoEvent $event 事件对象
     * @return void
     */
    public function dispatch(AbstractSeoEvent $event): void
    {
        // 验证事件数据
        $event->validateData($event->getData());
        
        // 添加事件元数据
        $eventData = $event->getData();
        $eventData['_event_meta'] = [
            'event_name' => $event->getEventName(),
            'version' => $event->getVersion(),
            'type' => $event->getEventType(),
            'timestamp' => $event->getTimestamp(),
            'event_id' => $event->getEventId(),
        ];
        
        // 分发事件
        $this->eventsManager->dispatch($event->getEventName(), $eventData);
    }

    /**
     * 分发SEO主体创建事件
     * 
     * @param int $subjectId 主体ID
     * @param string $subjectType 主体类型
     * @param int $subjectEntityId 主体实体ID
     * @param array $additionalData 额外数据
     * @return void
     */
    public function dispatchSubjectCreated(
        int $subjectId,
        string $subjectType,
        int $subjectEntityId,
        array $additionalData = []
    ): void {
        $event = ObjectManager::getInstance(\Weline\Seo\Event\Domain\SubjectCreatedEvent::class);
        $event->setData(array_merge([
            'subject_id' => $subjectId,
            'subject_type' => $subjectType,
            'subject_entity_id' => $subjectEntityId,
        ], $additionalData));
        
        $this->dispatch($event);
    }

    /**
     * 分发关键词提取完成事件
     * 
     * @param int $subjectId 主体ID
     * @param array $keywords 关键词列表
     * @param string $source 来源
     * @return void
     */
    public function dispatchKeywordsExtracted(
        int $subjectId,
        array $keywords,
        string $source
    ): void {
        $event = ObjectManager::getInstance(\Weline\Seo\Event\Domain\KeywordsExtractedEvent::class);
        $event->setData([
            'subject_id' => $subjectId,
            'keywords' => $keywords,
            'source' => $source,
            'count' => count($keywords),
        ]);
        
        $this->dispatch($event);
    }

    /**
     * 分发任务入队事件
     * 
     * @param int $taskId 任务ID
     * @param string $taskType 任务类型
     * @param string $subjectType 主体类型
     * @param int $subjectId 主体ID
     * @param array $additionalData 额外数据
     * @return void
     */
    public function dispatchTaskEnqueued(
        int $taskId,
        string $taskType,
        string $subjectType,
        int $subjectId,
        array $additionalData = []
    ): void {
        $event = ObjectManager::getInstance(\Weline\Seo\Event\Integration\TaskEnqueuedEvent::class);
        $event->setData(array_merge([
            'task_id' => $taskId,
            'task_type' => $taskType,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
        ], $additionalData));
        
        $this->dispatch($event);
    }
}

