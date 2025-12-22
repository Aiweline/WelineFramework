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
 * 趋势平台适配器接口
 * 
 * @package Weline_Seo
 */
interface TrendPlatformAdapterInterface
{
    /**
     * 获取平台代码
     * 
     * @return string 平台代码，如 'google_trends', 'baidu_index' 等
     */
    public function getCode(): string;

    /**
     * 获取平台名称
     * 
     * @return string 平台显示名称
     */
    public function getLabel(): string;

    /**
     * 获取关键词趋势数据
     * 
     * @param array $keywords 关键词数组
     * @param array $options 选项，包含：
     *   - 'region': 地区代码（如 'CN', 'US'）
     *   - 'date_range': 日期范围
     *   - 其他平台特定选项
     * @return array 趋势数据，格式：
     *   [
     *     'keyword1' => [
     *       'value' => 100,  // 趋势值
     *       'date' => '2024-01-01',
     *       'region' => 'CN',
     *     ],
     *     ...
     *   ]
     */
    public function fetchTrends(array $keywords, array $options = []): array;

    /**
     * 检查平台是否可用
     * 
     * @return bool
     */
    public function isAvailable(): bool;
}

