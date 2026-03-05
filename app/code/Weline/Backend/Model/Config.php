<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Backend\Model;

/**
 * 后端配置 - 通过统一查询器访问系统配置，避免模块间直接调用
 */
class Config
{
    private const AREA_BACKEND = 'backend';

    /**
     * @DESC          # 【后端】读取配置
     *
     * @param string $key
     * @param string $module
     *
     * @return mixed
     */
    public function getConfig(string $key, string $module): mixed
    {
        return w_query('system_config', 'getConfig', [
            'key' => $key,
            'module' => $module,
            'area' => self::AREA_BACKEND,
        ]);
    }

    /**
     * @DESC          # 【后端】设置配置
     *
     * @param string $key
     * @param string $value
     * @param string $module
     *
     * @return bool
     */
    public function setConfig(string $key, string $value, string $module): bool
    {
        return (bool)w_query('system_config', 'setConfig', [
            'key' => $key,
            'value' => $value,
            'module' => $module,
            'area' => self::AREA_BACKEND,
        ]);
    }
}
