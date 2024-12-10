<?php

declare(strict_types=1);
/**
 * 文件信息
 * 作者：邹万才
 * 网名：秋风雁飞(Aiweline)
 * 网站：www.aiweline.com/bbs.aiweline.com
 * 工具：PhpStorm
 * 日期：2021/5/13
 * 时间：17:29
 * 描述：此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
 */

namespace Weline\Theme\Console\Theme;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Output\Cli\Printing;
use Weline\Theme\Model\WelineTheme;

abstract class AbstractConsole implements CommandInterface
{
    /**
     * @var WelineTheme
     */
    protected WelineTheme $welineTheme;
    /**
     * @var Printing
     */
    protected Printing $printing;

    public function __construct(
        WelineTheme $welineTheme,
        Printing    $printing
    )
    {
        $this->welineTheme = $welineTheme;
        $this->printing    = $printing;
    }
}
