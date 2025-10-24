<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Session\Driver;

interface SessionDriverHandlerInterface extends \SessionHandlerInterface
{
    public function __construct(array $config);
    /**
     * @DESC          # 设置数据
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/11/8 17:07
     * 参数区：
     *
     * @param $name
     * @param $value
     *
     * @return mixed
     */
    public function set($name, $value): bool;

    /**
     * @DESC         |方法描述
     *
     * 参数区：
     *
     * @param $name
     *
     * @return mixed
     */
    public function get($name = null): mixed;

    /**
     * @DESC          # 删除数据
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/11/8 17:03
     * 参数区：
     *
     * @param $name
     *
     * @return bool
     */
    public function delete($name): bool;

    /**
     * @DESC          # 获取Session ID
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/11/20 13:52
     * 参数区：
     * @return mixed
     */
    public function getSessionId(): string;
}
