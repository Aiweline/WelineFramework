<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：23/4/2024 17:51:03
 */

namespace Weline\Queue\Console\Queue;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Printing;
use Weline\Queue\Helper\Helper;
use Weline\Queue\Observer\QueueCollect;

class Collect implements CommandInterface
{
    private Printing $printing;

    function __construct(Printing $printing)
    {
        $this->printing = $printing;
    }

    /**
     * @inheritDoc
     * @throws \Exception
     */
    public function execute(array $args = [], array $data = [])
    {
        // Helper 是静态类，直接使用静态方法，无需通过依赖注入
        Helper::collect();
        $this->printing->success('队列数据收集完成！', '系统队列');
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '从各个模组中收集队列类型数据';
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