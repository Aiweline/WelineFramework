<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\View\Data;

interface HtmlInterface
{
    /**
     * @DESC          # 设置头部信息
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/15 21:12
     * 参数区：
     *
     * @param string $key
     * @param string $html
     * @return \Weline\Framework\View\Data\HtmlInterface
     */
    public function setHtml(string $key, string $html): static;

    /**
     * @DESC          # 读取头部信息
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/15 21:12
     * 参数区：
     * @param string $key
     * @return string
     */
    public function getHtml(string $key = ''): string;

    /**
     * 添加html信息
     * @param string $key
     * @param string $html
     * @return $this
     */
    public function addHtml(string $key, string $html): static;

    /**
     * 追加html，不保存到数据库
     * @param string $key
     * @param string $html
     * @return $this
     */
    public function append(string $key, string $html): static;
}
