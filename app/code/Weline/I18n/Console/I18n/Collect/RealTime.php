<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\I18n\Console\I18n\Collect;

use Weline\Framework\Console\CommandInterface;

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

    public function help(): array|string
    {
        // 基于tip的默认help实现
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
            ],
            [],
            []
        );
    }
}
