<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Api;

/**
 * CDN缓存预热URL提供者接口
 * 
 * 所有WarmupProvider必须实现此接口
 * 
 * @package Weline_Cdn
 */
interface WarmupProviderInterface
{
    /**
     * 执行并返回预热URL列表
     * 
     * @return array URL数组，可以是简单字符串数组或详细数组
     *               简单格式: ['https://example.com/page1', 'https://example.com/page2']
     *               详细格式: [['url' => 'https://example.com/page3', 'site_id' => 1]]
     */
    public static function execute(): array;
}

