<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Plugin\Console\Plugin\Status;

class Set implements \Weline\Framework\Console\CommandInterface
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
        return __('状态操作：0/1 0:关闭，1:启用');
    }
}
