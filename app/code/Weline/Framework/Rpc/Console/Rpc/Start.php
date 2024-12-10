<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：30/9/2022 15:33:36
 */

namespace Weline\Framework\Rpc\Console\Rpc;

use Weline\Framework\Rpc\Server;

class Start extends Server implements \Weline\Framework\Console\CommandInterface
{
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        Server::instance([
                             'host' => '127.0.0.1',
                             'port' => 8989,
                             'path' => './api'
                         ])->run();
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('启动RPC服务。');
    }
}
