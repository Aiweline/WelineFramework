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
 * 关键词提取完成事件
 * 
 * 当关键词提取任务完成时触发
 * 
 * @package Weline_Seo
 */
class KeywordsExtractedEvent extends AbstractSeoEvent
{
    protected const EVENT_NAME = 'Weline_Seo::domain::keywords_extracted';
    protected const EVENT_VERSION = '1.0.0';
    protected const EVENT_TYPE = 'domain';
    protected const EVENT_DESCRIPTION = '关键词提取完成事件，当关键词提取任务完成时触发';

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
            'keywords' => [
                'type' => 'array',
                'required' => true,
                'description' => '提取的关键词列表',
            ],
            'source' => [
                'type' => 'string',
                'required' => true,
                'description' => '关键词来源：extracted, ai, manual等',
            ],
            'count' => [
                'type' => 'integer',
                'required' => false,
                'description' => '关键词数量',
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
     * 获取关键词列表
     */
    public function getKeywords(): array
    {
        $keywords = $this->getData('keywords');
        return is_array($keywords) ? $keywords : [];
    }

    /**
     * 获取关键词来源
     */
    public function getSource(): string
    {
        return (string)$this->getData('source');
    }

    /**
     * 获取关键词数量
     */
    public function getCount(): int
    {
        return (int)$this->getData('count', count($this->getKeywords()));
    }
}

