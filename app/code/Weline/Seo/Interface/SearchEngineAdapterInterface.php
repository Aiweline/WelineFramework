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
     * @param array $options 选项（必须包含 account/config 等必要信息）
     * @return array 推送结果，格式：
     *   [
     *     'success' => true/false,
     *     'message' => '...',
     *     'data' => [...]
     *   ]
     */
    public function pushUrls(array $urls, array $options = []): array;

    /**
     * 提交 Sitemap 到搜索引擎
     *
     * @param string $sitemapUrl Sitemap 地址
     * @param array $options 选项（必须包含 account/config 等必要信息）
     * @return array 结果结构同 pushUrls
     */
    public function submitSitemap(string $sitemapUrl, array $options = []): array;

    /**
     * 获取配置要求
     * 
     * @return array 配置要求说明，如需要 API Key、Token 等
     */
    public function getRequirements(): array;

    /**
     * 后台账户表单字段（由 Provider 自行决定，禁止跨平台复用）
     *
     * 每项建议包含：
     * - key: string 写入账户 config 的键
     * - label: string 字段标题
     * - type: text|password|url|website_url|textarea|json|checkbox（默认 text；website_url 由网站选择标签填公网 URL）
     * - required: bool
     * - placeholder?: string
     * - hint?: string
     * - accept?: string（如 .json，仅 json/文件类字段）
     *
     * @return list<array<string, mixed>>
     */
    public function getAccountConfigFields(): array;

    /**
     * 检查是否已配置
     * 
     * @return bool
     */
    public function isConfigured(): bool;
}

