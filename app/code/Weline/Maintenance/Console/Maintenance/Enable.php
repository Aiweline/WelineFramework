<?php

declare(strict_types=1);
/**
 * 文件信息
 * 作者：邹万才
 * 网名：秋风雁飞(Aiweline)
 * 网站：www.aiweline.com/bbs.aiweline.com
 * 工具：PhpStorm
 * 日期：2021/5/10
 * 时间：23:49
 * 描述：此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
 */

namespace Weline\Maintenance\Console\Maintenance;

use Weline\Framework\App\Env;
use Weline\Framework\Output\Cli\Printing;

class Enable implements \Weline\Framework\Console\CommandInterface
{
    /**
     * @var Printing
     */
    private Printing $printing;

    /**
     * Enable 初始函数...
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
        Env::getInstance()->setConfig('maintenance', true);
        $this->printing->success(__('维护模式已开启！'));
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '开启维护模式';
    }
}
