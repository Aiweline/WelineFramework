<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\I18n\Console\I18n\Collect;

class RealTime implements \Weline\Framework\Console\CommandInterface
{
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        p($args);
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('是否实时收集翻译词典。[enable/disable]');
    }
}
