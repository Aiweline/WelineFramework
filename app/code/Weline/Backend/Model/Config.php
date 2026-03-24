<?php

declare(strict_types=1);

/*
 * 鏈枃浠剁敱 绉嬫灚闆侀 缂栧啓锛屾墍鏈夎В閲婃潈褰扐iweline鎵€鏈夈€?
 * 閭锛歛iweline@qq.com
 * 缃戝潃锛歛iweline.com
 * 璁哄潧锛歨ttps://bbs.aiweline.com
 */

namespace Weline\Backend\Model;

class Config
{
    private const AREA_BACKEND = 'backend';

    public function getConfig(string $key, string $module): mixed
    {
        return w_query('system_config', 'getConfig', [
            'key' => $key,
            'module' => $module,
            'area' => self::AREA_BACKEND,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfigs(string $module): array
    {
        return (array) w_query('system_config', 'getConfigs', [
            'module' => $module,
            'area' => self::AREA_BACKEND,
        ]);
    }

    public function setConfig(string $key, string $value, string $module): bool
    {
        return (bool) w_query('system_config', 'setConfig', [
            'key' => $key,
            'value' => $value,
            'module' => $module,
            'area' => self::AREA_BACKEND,
        ]);
    }
}
