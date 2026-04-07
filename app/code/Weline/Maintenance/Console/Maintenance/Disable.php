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

use Weline\Framework\Console\CommandInterface;

use Weline\Framework\App\Env;
use Weline\Framework\Output\Cli\Printing;
use Weline\Maintenance\Helper\WlsMaintenanceSync;

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
        Env::getInstance()->setConfig('system.maintenance', false);
        $this->printing->success(__('维护模式已关闭！'));
        WlsMaintenanceSync::syncAfterCliToggle($this->printing, false, $args);
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '关闭维护模式';
    }

    public function help(): array|string
    {
        // 基于tip的默认help实现
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
                '-n, --name' => __('指定 WLS 实例名；省略则向当前所有运行中的实例同步维护入口'),
            ],
            [],
            []
        );
    }
}
