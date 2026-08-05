<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\System\Console\System\Install;

use Weline\Framework\Console\CommandAbstract;

class Sample extends CommandAbstract
{
    public function execute(array $args = [], array $data = [])
    {
        $this->printer->note('安装命令示例：');
        $line_break = IS_WIN ? '^' : '\\';
        $this->printer->success('php bin/w system:install ' . $line_break . '
--db-type=pgsql ' . $line_break . '
--db-hostname=127.0.0.1 ' . $line_break . '
--db-hostport=5432 ' . $line_break . '
--db-database=weline ' . $line_break . '
--db-username=weline ' . $line_break . '
--db-password=weline ' . $line_break . '
--db-charset=utf8 ' . $line_break . '
--db-collate=utf8_general_ci' . $line_break . '
--sandbox_db-type=sqlite
            ');
        $this->printer->note('如果你是Windows11：');
        $this->printer->success('php bin/w system:install --db-type=pgsql --db-hostname=127.0.0.1 --db-hostport=5432 --db-database=weline --db-username=weline --db-password=weline --db-charset=utf8 --db-collate=utf8_general_ci --sandbox_db-type=sqlite');
        exit();
    }

    public function tip(): string
    {
        return '安装脚本样例';
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
