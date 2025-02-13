<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Module\Console\Module;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandAbstract;

class Status extends CommandAbstract
{
    public function execute(array $args = [], array $data = [])
    {
        array_shift($args);
        $module_list = Env::getInstance()->getModuleList();
        if (empty($module_list)) {
            $this->printer->error('请先更新模块:bin/w module:upgrade');
            exit();
        }
        if (!empty($args)) {
            foreach ($args as $module) {
                if (isset($module_list[$module])) {
                    $this->printer->printList([$module => $module_list[$module]], '=>');
                } else {
                    $this->printer->error(__('不存在的模块:') . $module);
                }
            }
        } else {
            $this->printer->printList($module_list, '=>');
        }
    }

    public function tip(): string
    {
        return '获取模块列表';
    }
}
