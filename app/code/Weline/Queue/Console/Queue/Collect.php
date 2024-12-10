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

    private Helper $helper;
    private Printing $printing;

    function __construct(Helper $helper, Printing $printing)
    {
        $this->helper   = $helper;
        $this->printing = $printing;
    }

    /**
     * @inheritDoc
     * @throws \Exception
     */
    public function execute(array $args = [], array $data = [])
    {
        $this->helper::collect();
        $this->printing->success('队列数据收集完成！', '系统队列');
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '从各个模组中收集队列类型数据';
    }
}