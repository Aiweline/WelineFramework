<?php

namespace Weline\Framework\Console\Console;

use Weline\Framework\App\Env;

class Detail extends \Weline\Framework\Console\CommandAbstract
{
    public function execute(array $args = [], array $data = [])
    {
        $command = $args[1];
        # 导入命令行信息
        $commands = Env::GENERATED_DIR.DS.'commands.php';
        if(!file_exists($commands)){
            $this->printer->error('命令行信息不存在');
            return;
        }
        $commands = include $commands;
        # 获取命令行信息
        foreach($commands as $module_commands){
            foreach($module_commands as $module_command => $command_info){
                if($module_command == $command){
                    $this->printer->printList($command_info);
                }
            }
        }
        return;
    }

    public function tip(): string
    {
        return '查看命令详情，示例：bin/w detail dev:debug';
    }
}
