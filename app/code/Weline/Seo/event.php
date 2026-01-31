<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

/**
 * Weline_Seo 模块事件规约
 * 
 * 按照国际标准设计的事件契约，使用事件解耦模块间依赖
 * 
 * 事件命名规范：
 * - 格式：模块名::事件类型::事件名称
 * - 示例：Weline_Seo::domain::subject_created
 * 
 * 事件类型：
 * - domain: 领域事件（Domain Events）- 业务领域内的事件
 * - integration: 集成事件（Integration Events）- 跨模块/系统的事件
 * - application: 应用事件（Application Events）- 应用层事件
 */

return [
    // ========== Domain Events (领域事件) ==========
    
    /**
     * SEO主体创建事件
     * 当SEO主体（店铺/网站等）被创建时触发
     */
    'Weline_Seo::domain::subject_created' => [
        'name' => __('SEO主体创建'),
        'description' => __('当SEO主体（店铺、网站等）被创建时触发，允许其他模块监听并处理SEO主体创建逻辑。'),
        'doc' => 'domain/subject_created.md',
        'version' => '1.0.0',
        'type' => 'domain',
        'data_contract' => [
            'subject_id' => ['type' => 'integer', 'required' => true, 'description' => 'SEO主体ID'],
            'subject_type' => ['type' => 'string', 'required' => true, 'description' => '主体类型：store, website等'],
            'subject_entity_id' => ['type' => 'integer', 'required' => true, 'description' => '主体实体ID'],
            'url' => ['type' => 'string', 'required' => false, 'description' => 'URL地址'],
            'title' => ['type' => 'string', 'required' => false, 'description' => '标题'],
            'description' => ['type' => 'string', 'required' => false, 'description' => '描述'],
        ],
    ],

    /**
     * SEO主体更新事件
     * 当SEO主体信息被更新时触发
     */
    'Weline_Seo::domain::subject_updated' => [
        'name' => __('SEO主体更新'),
        'description' => __('当SEO主体信息被更新时触发，允许其他模块监听并处理SEO主体更新逻辑。'),
        'doc' => 'domain/subject_updated.md',
        'version' => '1.0.0',
        'type' => 'domain',
        'data_contract' => [
            'subject_id' => ['type' => 'integer', 'required' => true, 'description' => 'SEO主体ID'],
            'subject_type' => ['type' => 'string', 'required' => true, 'description' => '主体类型'],
            'changes' => ['type' => 'array', 'required' => false, 'description' => '变更字段列表'],
        ],
    ],

    /**
     * 关键词提取完成事件
     * 当关键词提取任务完成时触发
     */
    'Weline_Seo::domain::keywords_extracted' => [
        'name' => __('关键词提取完成'),
        'description' => __('当关键词提取任务完成时触发，允许其他模块监听并处理提取的关键词。'),
        'doc' => 'domain/keywords_extracted.md',
        'version' => '1.0.0',
        'type' => 'domain',
        'data_contract' => [
            'subject_id' => ['type' => 'integer', 'required' => true, 'description' => 'SEO主体ID'],
            'keywords' => ['type' => 'array', 'required' => true, 'description' => '提取的关键词列表'],
            'source' => ['type' => 'string', 'required' => true, 'description' => '关键词来源：extracted, ai, manual等'],
        ],
    ],

    /**
     * SEO建议生成完成事件
     * 当AI生成SEO建议完成时触发
     */
    'Weline_Seo::domain::suggestion_generated' => [
        'name' => __('SEO建议生成完成'),
        'description' => __('当AI生成SEO建议完成时触发，允许其他模块监听并处理生成的建议。'),
        'doc' => 'domain/suggestion_generated.md',
        'version' => '1.0.0',
        'type' => 'domain',
        'data_contract' => [
            'subject_id' => ['type' => 'integer', 'required' => true, 'description' => 'SEO主体ID'],
            'suggestion_id' => ['type' => 'integer', 'required' => true, 'description' => '建议ID'],
            'keywords' => ['type' => 'array', 'required' => false, 'description' => '推荐关键词列表'],
            'content' => ['type' => 'array', 'required' => false, 'description' => '建议内容'],
        ],
    ],

    /**
     * 站点绑定SEO账户事件
     * 当站点绑定或更新SEO账户时触发
     */
    'Weline_Seo::domain::website_account_bind' => [
        'name' => __('站点绑定SEO账户'),
        'description' => __('当站点绑定SEO账户时触发，自动保存站点与账户的关联关系，用于控制sitemap自动提交。'),
        'doc' => 'domain/website_account_bind.md',
        'version' => '1.0.0',
        'type' => 'domain',
        'data_contract' => [
            'website_id' => ['type' => 'integer', 'required' => true, 'description' => '站点ID'],
            'account_id' => ['type' => 'integer', 'required' => true, 'description' => 'SEO账户ID'],
            'is_auto_submit' => ['type' => 'boolean', 'required' => false, 'description' => '是否自动提交sitemap，默认true'],
        ],
    ],

    // ========== Integration Events (集成事件) ==========
    
    /**
     * SEO Feed收集事件
     * 允许其他模块向SEO模块注入Feed数据
     */
    'Weline_Seo::integration::feed_collect' => [
        'name' => __('SEO Feed收集'),
        'description' => __('允许其他模块向SEO模块注入Feed数据，实现跨模块的SEO信息收集。'),
        'doc' => 'integration/feed_collect.md',
        'version' => '1.0.0',
        'type' => 'integration',
        'data_contract' => [
            'subject_type' => ['type' => 'string', 'required' => true, 'description' => '主体类型'],
            'subject_id' => ['type' => 'integer', 'required' => true, 'description' => '主体ID'],
            'feed_data' => ['type' => 'array', 'required' => true, 'description' => 'Feed数据，包含url, title, description, keywords等'],
        ],
    ],

    /**
     * SEO任务入队事件
     * 当SEO任务被加入队列时触发
     */
    'Weline_Seo::integration::task_enqueued' => [
        'name' => __('SEO任务入队'),
        'description' => __('当SEO任务被加入队列时触发，允许其他模块监听任务入队事件。'),
        'doc' => 'integration/task_enqueued.md',
        'version' => '1.0.0',
        'type' => 'integration',
        'data_contract' => [
            'task_id' => ['type' => 'integer', 'required' => true, 'description' => '任务ID'],
            'task_type' => ['type' => 'string', 'required' => true, 'description' => '任务类型'],
            'subject_type' => ['type' => 'string', 'required' => true, 'description' => '主体类型'],
            'subject_id' => ['type' => 'integer', 'required' => true, 'description' => '主体ID'],
        ],
    ],

    /**
     * SEO任务处理完成事件
     * 当SEO任务处理完成时触发
     */
    'Weline_Seo::integration::task_completed' => [
        'name' => __('SEO任务处理完成'),
        'description' => __('当SEO任务处理完成时触发，允许其他模块监听任务完成事件。'),
        'doc' => 'integration/task_completed.md',
        'version' => '1.0.0',
        'type' => 'integration',
        'data_contract' => [
            'task_id' => ['type' => 'integer', 'required' => true, 'description' => '任务ID'],
            'task_type' => ['type' => 'string', 'required' => true, 'description' => '任务类型'],
            'status' => ['type' => 'string', 'required' => true, 'description' => '任务状态：done, error'],
            'result' => ['type' => 'mixed', 'required' => false, 'description' => '处理结果'],
        ],
    ],

    // ========== Application Events (应用事件) ==========
    
    /**
     * SEO趋势数据同步完成事件
     * 当趋势数据同步任务完成时触发
     */
    'Weline_Seo::application::trend_sync_completed' => [
        'name' => __('SEO趋势数据同步完成'),
        'description' => __('当趋势数据同步任务完成时触发，允许其他模块监听趋势同步结果。'),
        'doc' => 'application/trend_sync_completed.md',
        'version' => '1.0.0',
        'type' => 'application',
        'data_contract' => [
            'platform' => ['type' => 'string', 'required' => true, 'description' => '平台：google, baidu等'],
            'keyword_count' => ['type' => 'integer', 'required' => true, 'description' => '处理的关键词数量'],
            'trend_count' => ['type' => 'integer', 'required' => true, 'description' => '保存的趋势数据数量'],
            'error_count' => ['type' => 'integer', 'required' => false, 'description' => '错误数量'],
        ],
    ],
];

