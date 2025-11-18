<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Interception;

interface InterceptorInterface
{
    /**
     * @DESC         | 调用侦听原始类的方法
     *
     * 参数区：
     *
     * @param string $method
     * @param array  $arguments
     *
     * @return mixed
     */
    public function ___callParentMethod(string $method, array $arguments);
}
