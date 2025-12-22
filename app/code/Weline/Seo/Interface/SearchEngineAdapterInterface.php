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
 * 搜索引擎适配器接口
 * 
 * @package Weline_Seo
 */
interface SearchEngineAdapterInterface
{
    /**
     * 获取搜索引擎代码
     * 
     * @return string 搜索引擎代码，如 'google', 'baidu', 'bing' 等
     */
    public function getCode(): string;

    /**
     * 获取搜索引擎名称
     * 
     * @return string 显示名称
     */
    public function getLabel(): string;

    /**
     * 推送 URL 到搜索引擎
     * 
     * @param array $urls URL数组
     * @param array $options 选项
     * @return array 推送结果，格式：
     *   [
     *     'success' => true/false,
     *     'message' => '...',
     *     'data' => [...]
     *   ]
     */
    public function pushUrls(array $urls, array $options = []): array;

    /**
     * 获取配置要求
     * 
     * @return array 配置要求说明，如需要 API Key、Token 等
     */
    public function getRequirements(): array;

    /**
     * 检查是否已配置
     * 
     * @return bool
     */
    public function isConfigured(): bool;
}

