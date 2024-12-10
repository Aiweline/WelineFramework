<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/1/10 20:34:30
 */

namespace Weline\Framework\Attribute;

#[\Attribute] interface RouterAttributeInterface
{

    const result_key = 'result';

    /**
     * @DESC          # 属性执行方法,如果有返回结果将直接中断方法继续执行
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/1/10 20:34
     * 参数区：
     * @return mixed
     */
    public function execute(): ?string;

    public function setResult(string $result): static;

    public function getResult(): ?string;
}