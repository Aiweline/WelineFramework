<?php

declare(strict_types=1);
/**
 * 文件信息
 * 作者：邹万才
 * 网名：秋风雁飞(Aiweline)
 * 网站：www.aiweline.com/bbs.aiweline.com
 * 工具：PhpStorm
 * 日期：2021/5/10
 * 时间：23:50
 * 描述：此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
 */

namespace Weline\Maintenance\Console\Maintenance;

use Weline\Framework\App\Env;
use Weline\Framework\Output\Cli\Printing;

class Disable implements \Weline\Framework\Console\CommandInterface
{
    /**
     * @var Printing
     */
    private Printing $printing;

    /**
     * Disable 初始函数...
     *
     * @param Printing $printing
     */
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
        Env::getInstance()->setConfig('maintenance', false);
        $this->printing->success(__('维护模式已关闭！'));
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '关闭维护模式';
    }
}
