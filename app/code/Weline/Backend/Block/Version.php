<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Backend\Block;

use Weline\Framework\App\Env;

class Version extends \Weline\Framework\View\Block
{
    protected string $_template = 'Weline_Backend::version.phtml';

    public function getVersion(): string
    {
        return Env::getInstance()->getConfig('version') ?: '1.0.1';
    }
}
