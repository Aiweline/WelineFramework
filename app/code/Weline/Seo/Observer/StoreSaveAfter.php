<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Observer;

use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Event\Event;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SeoTask;
use Weline\Seo\Model\SeoSubject;

/**
 * Store 保存后观察者
 * 
 * 轻量级任务入队，不阻塞主流程
 * 
 * @package Weline_Seo
 */
class StoreSaveAfter implements ObserverInterface
{
    private ObjectManager $objectManager;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * 执行观察者逻辑
     * 
     * 仅将任务入队，不进行实际处理
     * 
     * @param Event $event
     * @return void
     */
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $store = $data['store'] ?? null;

        // 使用鸭式类型检查，避免跨模块直接依赖 Store 类
        if (!is_object($store) || !method_exists($store, 'getId') || !$store->getId()) {
            return;
        }

        try {
            /** @var SeoTask $taskModel */
            $taskModel = $this->objectManager->getInstance(SeoTask::class);
            
            // 检查是否已存在相同任务（避免重复入队）
            $existingTask = $taskModel->reset()
                ->where(SeoTask::schema_fields_TASK_TYPE, SeoTask::TASK_TYPE_FEED_GENERATE)
                ->where(SeoTask::schema_fields_SUBJECT_TYPE, SeoSubject::SUBJECT_TYPE_STORE)
                ->where(SeoTask::schema_fields_SUBJECT_ID, $store->getId())
                ->where(SeoTask::schema_fields_STATUS, [SeoTask::STATUS_PENDING, SeoTask::STATUS_PROCESSING], 'IN')
                ->find()
                ->fetch();
            
            if ($existingTask->getId()) {
                // 已存在待处理任务，跳过
                return;
            }

            // 创建Feed生成任务
            $payload = [
                'store_id' => $store->getId(),
                'store_name' => $store->getName(),
                'description' => $store->getDescription(),
                'meta_title' => $store->getMetaTitle(),
                'meta_description' => $store->getMetaDescription(),
                'meta_keywords' => $store->getMetaKeywords(),
            ];

            $taskModel->reset()
                ->setTaskType(SeoTask::TASK_TYPE_FEED_GENERATE)
                ->setSubjectType(SeoSubject::SUBJECT_TYPE_STORE)
                ->setSubjectId($store->getId())
                ->setPayloadArray($payload)
                ->setPriority(SeoTask::PRIORITY_NORMAL)
                ->setStatus(SeoTask::STATUS_PENDING)
                ->setMaxAttempts(3)
                ->save();

            // 分发任务入队事件
            $taskId = $taskModel->getId();
            if ($taskId) {
                /** @var \Weline\Seo\Service\EventDispatcher $eventDispatcher */
                $eventDispatcher = $this->objectManager->getInstance(\Weline\Seo\Service\EventDispatcher::class);
                $eventDispatcher->dispatchTaskEnqueued(
                    $taskId,
                    SeoTask::TASK_TYPE_FEED_GENERATE,
                    SeoSubject::SUBJECT_TYPE_STORE,
                    $store->getId(),
                    [
                        'priority' => SeoTask::PRIORITY_NORMAL,
                        'scheduled_at' => $taskModel->getData(SeoTask::schema_fields_SCHEDULED_AT),
                    ]
                );
            }

        } catch (\Exception $e) {
            // 记录错误但不中断主流程
            // TODO: 记录日志
        }
    }
}

