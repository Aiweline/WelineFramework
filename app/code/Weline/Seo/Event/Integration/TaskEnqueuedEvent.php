<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Event\Integration;

use Weline\Seo\Event\AbstractSeoEvent;

/**
 * SEO任务入队事件
 * 
 * 当SEO任务被加入队列时触发
 * 
 * @package Weline_Seo
 */
class TaskEnqueuedEvent extends AbstractSeoEvent
{
    protected const EVENT_NAME = 'Weline_Seo::integration::task_enqueued';
    protected const EVENT_VERSION = '1.0.0';
    protected const EVENT_TYPE = 'integration';
    protected const EVENT_DESCRIPTION = 'SEO任务入队事件，当SEO任务被加入队列时触发';

    /**
     * 获取数据契约
     */
    public function getDataContract(): array
    {
        return [
            'task_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => '任务ID',
            ],
            'task_type' => [
                'type' => 'string',
                'required' => true,
                'description' => '任务类型：feed_generate, push_urls等',
            ],
            'subject_type' => [
                'type' => 'string',
                'required' => true,
                'description' => '主体类型',
            ],
            'subject_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => '主体ID',
            ],
            'priority' => [
                'type' => 'integer',
                'required' => false,
                'description' => '任务优先级',
                'default' => 5,
            ],
            'scheduled_at' => [
                'type' => 'string',
                'required' => false,
                'description' => '计划执行时间',
            ],
        ];
    }

    /**
     * 获取任务ID
     */
    public function getTaskId(): int
    {
        return (int)$this->getData('task_id');
    }

    /**
     * 获取任务类型
     */
    public function getTaskType(): string
    {
        return (string)$this->getData('task_type');
    }

    /**
     * 获取主体类型
     */
    public function getSubjectType(): string
    {
        return (string)$this->getData('subject_type');
    }

    /**
     * 获取主体ID
     */
    public function getSubjectId(): int
    {
        return (int)$this->getData('subject_id');
    }
}

