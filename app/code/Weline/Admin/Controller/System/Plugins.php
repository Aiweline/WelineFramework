<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Controller\System;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Plugin\Config\PluginXmlReader;

class Plugins extends \Weline\Admin\Controller\BaseController
{
    public function getIndex()
    {
        $plugins = $this->getReader()->read();
        $this->assign('plugins', $plugins);
        return $this->fetch();
    }


    private function getReader(): PluginXmlReader
    {
        if (empty($this->reader)) {
            $this->reader = ObjectManager::getInstance(PluginXmlReader::class);
        }
        return $this->reader;
    }
}
