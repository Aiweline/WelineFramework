<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Frontend\Model;

use Weline\SystemConfig\Model\SystemConfig;

class Config
{
    private SystemConfig $systemConfig;

    public function __construct(
        SystemConfig $systemConfig
    )
    {
        $this->systemConfig = $systemConfig;
    }

    /**
     * @DESC          # 【前端】获取配置
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/14 21:48
     * 参数区：
     *
     * @param string $key
     * @param string $module
     *
     * @return mixed
     */
    public function getConfig(string $key, string $module): mixed
    {
        return $this->systemConfig->getConfig($key, $module, SystemConfig::area_FRONTEND);
    }

    /**
     * @DESC          # 【前端】设置前端配置
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/14 21:48
     * 参数区：
     *
     * @param string $key
     * @param string $value
     * @param string $module
     *
     * @return bool
     */
    public function setConfig(string $key, string $value, string $module): bool
    {
        return $this->systemConfig->setConfig($key, $value, $module, SystemConfig::area_FRONTEND);
    }
}
