<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/7/1 11:28:43
 */

namespace Weline\Taglib;

/**
 * @deprecated Implement \Weline\Framework\Taglib\TaglibInterface directly.
 *             This bridge remains for third-party module compatibility.
 */
interface TaglibInterface extends \Weline\Framework\Taglib\TaglibInterface
{
    /**
     * @DESC          # 标签名
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/7/1 12:09
     * 参数区：
     * @return string
     */
    static public function name(): string;

    /**
     * @DESC          # 标签形式
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/7/1 11:36
     * 参数区：
     * @return bool  # 返回true时标签将支持<tag></tag>形式，否则标签不支持开头结尾形式。
     */
    static function tag(): bool;

    /**
     * @DESC          # 属性检测
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/7/1 11:34
     * 参数区：
     * @return array # ['condition'=>true] 表示需要检测的条件语句属性condition
     */
    static function attr(): array;

    /**
     * @DESC          # 是否接受匹配标签开始
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/7/1 11:37
     * 参数区：
     * @return bool # 选择接受后，callback中的$tag_key变量将传输tag_start数据，此时你将可以处理标签开始时的数据
     */
    static function tag_start(): bool;

    /**
     * @DESC          # 是否接受匹配标签结束
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/7/1 11:40
     * 参数区：
     * @return bool #  选择接受后，callback中的$tag_key变量将传输tag_end数据，此时你将可以处理标签结束时的数据
     */
    static function tag_end(): bool;

    /**
     * @DESC          # 匹配处理回调函数
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/7/1 11:40
     * 参数区：
     * @return callable #  回调函数，处理匹配数据 回调函数形式：function($tag_key, $config, $tag_data, $attributes)
     */
    static function callback(): callable;

    /**
     * @DESC          # 标签是否支持自闭和
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/7/1 11:41
     * 参数区：
     * @return bool
     */
    static function tag_self_close(): bool;

    /**
     * @DESC          # 标签是否自闭和携带属性参数
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/7/1 11:42
     * 参数区：
     * @return bool
     */
    static function tag_self_close_with_attrs(): bool;

    /**
     * @DESC          # 标签依赖管理（可选方法）
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/1/1 10:00
     * 参数区：
     * @return string|null # 返回父标签名称，用于依赖管理。如果标签没有依赖，可以不实现此方法或返回null
     * 
     * 说明：
     * 1. 此方法用于指定标签的依赖关系，确保父标签在子标签之前渲染
     * 2. 如果标签需要依赖其他标签，请实现此方法并返回父标签的名称
     * 3. 系统会自动检测此方法并进行依赖排序
     * 4. 支持单个父标签：public static function parent(): ?string { return 'parent-tag'; }
     * 5. 支持多个父标签：public static function parent(): ?string { return 'parent-tag1,parent-tag2'; }
     * 6. 多个父标签用逗号分隔，系统会确保所有父标签都在子标签之前渲染
     */
    static function parent(): ?string;

    static function document():string;
}
