<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Hook;

use Weline\Framework\Hook\Config\HookReader;

class Hooker
{
    private HookReader $hookReader;

    public function __construct(
        HookReader $hookReader
    )
    {
        $this->hookReader = $hookReader;
    }

    public function getHook(string $name)
    {
        $this->hookReader->setPath($name);
        return $this->hookReader->getFileList();
    }
}
