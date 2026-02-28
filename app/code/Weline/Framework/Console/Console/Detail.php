<?php

namespace Weline\Framework\Console\Console;

use Weline\Framework\App\Env;

class Detail extends \Weline\Framework\Console\CommandAbstract
{
    public function execute(array $args = [], array $data = [])
    {
        if (!isset($args[1])) {
            $this->printer->error(__('请指定要查看的命令名称'));
            $this->printer->note(__('用法: php bin/w detail <command>'));
            $this->printer->note(__('示例: php bin/w detail dev:debug'));
            return;
        }
        $command = $args[1];
        # 导入命令行信息
        $commands = Env::GENERATED_DIR.DS.'commands.php';
        if(!file_exists($commands)){
            $this->printer->error(__('命令行信息不存在'));
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
        return __('查看命令详情，示例：php bin/w detail dev:debug');
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
