<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Manager\Api;

use Weline\Framework\Database\ConnectionFactory;

interface FactoryInterface
{
    /**
     * @DESC          # 创建一个新的创建表对象
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/5 20:58
     * 参数区：
     */
    public function create(ConnectionFactory $connection): mixed;

    /**
     * @DESC          # 创建一个新的修改表对象
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/5/29 21:44
     * 参数区：
     *
     * @param ConnectionFactory $connection
     *
     * @return mixed
     */
    public function alter(ConnectionFactory $connection): mixed;
}
