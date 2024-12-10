<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Helper;

use Weline\Framework\Output\Debug\Printing;

class AbstractHelper
{
    protected Printing $_debug;

    public function getDebug()
    {
        if (!isset($this->_debug)) {
            $this->_debug = new Printing();
        }
        return $this->_debug;
    }
}
