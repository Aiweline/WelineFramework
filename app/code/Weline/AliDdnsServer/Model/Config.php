<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Weline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/5/10 22:42:22
 */

namespace Weline\AliDdnsServer\Model;

class Config extends \Weline\SystemConfig\Model\SystemConfig
{
    const accessKeyId  = 'accessKeyId';
    const accessSecret = 'accessSecret';

    public function get(string $key)
    {
        return $this->getConfig($key);
    }

    public function set(string $key, $value):bool
    {
        return $this->setConfig($key, $value);
    }

    function getConfig(string $key, string $module = 'Weline_AliDdnsServer', string $area = self::area_BACKEND): mixed
    {
        return parent::getConfig($key, $module, $area);
    }

    function setConfig(string $key, string $value, $module = 'Weline_AliDdnsServer', string $area = self::area_BACKEND): bool
    {
        return parent::setConfig($key, $value, $module, $area);
    }
}