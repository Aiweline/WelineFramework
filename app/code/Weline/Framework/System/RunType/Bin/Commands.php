<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\System\RunType\Bin;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\App\System;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\System\Helper\Data;

class Commands
{
    protected Printing $printer;

    protected Data $data;

    public function __construct()
    {
        $this->printer = new Printing();
        $this->data    = new Data();
    }

    public function run()
    {
        $hasErr = false;
        $tmp    = [];
        foreach ($this->data->getCommands() as $needCommand) {
            $result = [];
            $return = 0;
            try {
                $key = str_pad('---' . $needCommand, 45, '-', STR_PAD_BOTH);
                if (CLI) {
                    $this->printer->note($key);
                }
                
                // 完全不使用进程函数 - 直接在当前 PHP 进程中调用命令类
                // 解析命令字符串 "bin/w command:name" -> "command:name"
                $commandParts = explode(' ', trim($needCommand));
                $commandName = end($commandParts); // 获取最后一个部分（命令名）
                
                // 加载所有已注册的命令
                $commandsFile = BP . 'generated' . DS . 'commands.php';
                if (file_exists($commandsFile)) {
                    $registeredCommands = require $commandsFile;
                    
                    if (isset($registeredCommands[$commandName])) {
                        $commandClass = $registeredCommands[$commandName];
                        
                        // 使用 ObjectManager 实例化命令类
                        $commandInstance = \Weline\Framework\Manager\ObjectManager::getInstance($commandClass);
                        
                        if ($commandInstance && method_exists($commandInstance, 'execute')) {
                            if (CLI) {
                                $this->printer->printing("执行命令: {$commandName}");
                            }
                            
                            // 直接调用命令的 execute 方法（输出会实时显示）
                            $commandInstance->execute([], []);
                            $result[] = "命令 {$commandName} 执行完成";
                        } else {
                            $result[] = "命令类 {$commandClass} 无法执行";
                            if (CLI) {
                                $this->printer->warning("命令类无法实例化或缺少 execute 方法");
                            }
                        }
                    } else {
                        $result[] = "未找到命令: {$commandName}";
                        if (CLI) {
                            $this->printer->warning("命令 {$commandName} 未注册");
                        }
                    }
                } else {
                    $result[] = "命令列表文件不存在";
                    if (CLI) {
                        $this->printer->warning("命令列表文件不存在，请先运行 command:upgrade");
                    }
                }
                
                $tmp[$needCommand] = implode(',', $result);
                $value = str_pad('✔', 10, ' ', STR_PAD_BOTH);
            } catch (Exception $e) {
                $hasErr = true;
                $value  = str_pad('✖', 10, ' ', STR_PAD_BOTH);
            }
            
            $key = str_pad('---' . $needCommand, 45, '-', STR_PAD_BOTH);
            if (CLI) {
                $this->printer->success($key . '=>' . $value, 'OK');
            }
            $tmp[$key] = $value;
            unset($result);
        }
        // 读取后台以及接口后台地址（使用新的 area_routes 配置）
        $tmp['=========后台入口:'] = Env::getAreaRoutePrefix('backend') ?? '';
        $tmp['=========REST后台入口:'] = Env::getAreaRoutePrefix('rest_backend') ?? '';

        return ['data' => $tmp, 'hasErr' => $hasErr, 'msg' => '-------  环境命令初始化...  -------'];
    }
}
