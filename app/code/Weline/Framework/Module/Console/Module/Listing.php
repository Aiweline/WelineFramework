<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Module\Console\Module;

use Weline\Framework\App\Env;
use Weline\Framework\Output\Cli\Printing;

class Listing implements \Weline\Framework\Console\CommandInterface
{
    private Printing $printing;

    public function __construct(
        Printing $printing
    )
    {
        $this->printing = $printing;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        foreach (Env::getInstance()->getModuleList() as $name => $module) {
            if ($module['status']) {
                $name = str_pad($name, 45) . $this->printing->colorize('# ', 'Red') . $this->printing->colorize('开启', 'Green');
            } else {
                $name = str_pad($name, 45) . $this->printing->colorize('# ', 'Red') . $this->printing->colorize('禁用', 'Yellow');
            }
            $this->printing->note($name);
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '查看模块列表';
    }
}
