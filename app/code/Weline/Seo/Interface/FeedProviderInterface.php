<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Interface;

/**
 * SEO Feed 提供者接口
 * 
 * 其他模块通过实现此接口，可以向 SEO 模块上报 SEO Feed 信息
 * 
 * @package Weline_Seo
 */
interface FeedProviderInterface
{
    /**
     * 获取提供者代码（唯一标识）
     * 
     * @return string 提供者代码，如 'store_basic_seo', 'website_seo' 等
     */
    public function getCode(): string;

    /**
     * 获取提供者标签（显示名称）
     * 
     * @return string 显示名称，如 '店铺基础SEO', '网站SEO' 等
     */
    public function getLabel(): string;

    /**
     * 检查是否支持指定的主体类型
     * 
     * @param string $subjectType 主体类型，如 'store', 'website', 'product' 等
     * @return bool 是否支持
     */
    public function supports(string $subjectType): bool;

    /**
     * 收集 SEO Feed 数据
     * 
     * @param array $context 上下文信息，包含：
     *   - 'subject_type': 主体类型
     *   - 'subject_id': 主体ID
     *   - 'subject': 主体对象（可选）
     *   - 其他自定义上下文信息
     * @return array 标准化的 SEO Feed 数组，格式：
     *   [
     *     'subject_type' => 'store',
     *     'subject_id' => 1,
     *     'url' => 'https://example.com/store/1',
     *     'title' => '店铺标题',
     *     'description' => '店铺描述',
     *     'keywords' => ['关键词1', '关键词2'],
     *     'platforms' => ['google', 'baidu'], // 可选，推荐推送的平台
     *     'meta_data' => [ // 可选，其他元数据
     *       'image' => 'https://example.com/image.jpg',
     *       'locale' => 'zh-CN',
     *     ],
     *   ]
     */
    public function collect(array $context = []): array;
}

