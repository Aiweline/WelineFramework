<?php

namespace Weline\Taglib\Helper;

use Weline\Framework\Manager\ObjectManager;

class Taglib
{
    /**
     * 获取所有标签库
     * @return array
     * @throws \Exception
     */
    public static function getTagLibs(): array
    {
        # 核心标签库
        /**@var \Weline\Framework\View\Taglib $taglib*/
        $taglib = ObjectManager::getInstance(\Weline\Framework\View\Taglib::class);
        return $taglib->getTags(ObjectManager::getInstance(\Weline\Framework\View\Template::class));
    }
}
