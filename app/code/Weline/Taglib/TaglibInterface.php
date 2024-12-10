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

interface TaglibInterface
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

    static function document():string;
}