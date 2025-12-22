<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Event\Domain;

use Weline\Seo\Event\AbstractSeoEvent;

/**
 * SEO主体创建事件
 * 
 * 当SEO主体（店铺/网站等）被创建时触发
 * 
 * @package Weline_Seo
 */
class SubjectCreatedEvent extends AbstractSeoEvent
{
    protected const EVENT_NAME = 'Weline_Seo::domain::subject_created';
    protected const EVENT_VERSION = '1.0.0';
    protected const EVENT_TYPE = 'domain';
    protected const EVENT_DESCRIPTION = 'SEO主体创建事件，当SEO主体（店铺、网站等）被创建时触发';

    /**
     * 获取数据契约
     */
    public function getDataContract(): array
    {
        return [
            'subject_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'SEO主体ID',
            ],
            'subject_type' => [
                'type' => 'string',
                'required' => true,
                'description' => '主体类型：store, website等',
            ],
            'subject_entity_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => '主体实体ID',
            ],
            'url' => [
                'type' => 'string',
                'required' => false,
                'description' => 'URL地址',
            ],
            'title' => [
                'type' => 'string',
                'required' => false,
                'description' => '标题',
            ],
            'description' => [
                'type' => 'string',
                'required' => false,
                'description' => '描述',
            ],
            'locale' => [
                'type' => 'string',
                'required' => false,
                'description' => '语言代码',
                'default' => 'zh-CN',
            ],
        ];
    }

    /**
     * 获取主体ID
     */
    public function getSubjectId(): int
    {
        return (int)$this->getData('subject_id');
    }

    /**
     * 获取主体类型
     */
    public function getSubjectType(): string
    {
        return (string)$this->getData('subject_type');
    }

    /**
     * 获取主体实体ID
     */
    public function getSubjectEntityId(): int
    {
        return (int)$this->getData('subject_entity_id');
    }
}

