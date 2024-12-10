<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Installer\RunType\Bin;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\App\System;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Installer\Helper\Data;

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
            try {
                $command = 'php ' . BP . str_replace('/', DIRECTORY_SEPARATOR, $needCommand);
                \exec($command, $result, $return);
                $tmp[$needCommand] = implode(',', $result);
                $tmp[$command] = implode(',', $result);
                $value             = str_pad('✔', 10, ' ', STR_PAD_BOTH);
            } catch (Exception $e) {
                $hasErr = true;
                $value  = str_pad('✖', 10, ' ', STR_PAD_BOTH);
            }
            $key = str_pad('---' . $needCommand, 45, '-', STR_PAD_BOTH);
            if (CLI) {
                $this->printer->success($key . '=>' . $value, 'OK');
            }
            $tmp[$key] = $value;
            if (CLI) {
                foreach ($result as $item) {
                    $this->printer->printing($item);
                }
            }
            unset($result);
        }
        // 读取后台以及接口后台地址
        $tmp['=========Admin后台入口:'] = Env::getInstance()->getConfig('admin', '');
        $tmp['=========API后台入口:']   = Env::getInstance()->getConfig('api_admin', '');

        return ['data' => $tmp, 'hasErr' => $hasErr, 'msg' => '-------  环境命令初始化...  -------'];
    }
}
