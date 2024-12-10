<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Manager;

interface ManagerInterface
{
    /**
     * @DESC         |创建对象
     *
     * 参数区：
     *
     * @param string $class
     * @param string $method
     * @param array  $params
     */
    public static function make(string $class, array $params = [], string $method = '__construct');

    /**
     * @DESC         |获取实例
     *
     * 参数区：
     *
     * @return mixed
     */
    public static function getInstance(): mixed;
}
