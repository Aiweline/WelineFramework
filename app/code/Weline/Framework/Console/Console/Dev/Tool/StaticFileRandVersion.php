<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Console\Console\Dev\Tool;

use Weline\Framework\App\Env;
use Weline\Framework\Output\Cli\Printing;

class StaticFileRandVersion implements \Weline\Framework\Console\CommandInterface
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
        if (Env::getInstance()->getConfig('static_file_rand_version')) {
            Env::getInstance()->setConfig('static_file_rand_version', false);
            $this->printing->success(__('成功关闭随机静态文件版本号！'));
        } else {
            Env::getInstance()->setConfig('static_file_rand_version', true);
            $this->printing->success(__('成功开启随机静态文件版本号！'));
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '随机静态文件版本号：协助开发模式下实时刷新浏览器更新静态css,js,less等静态文件。';
    }
}
