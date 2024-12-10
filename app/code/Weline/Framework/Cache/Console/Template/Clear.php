<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Cache\Console\Template;

use Weline\Framework\App\Env;
use Weline\Framework\App\System;
use Weline\Framework\Console\Command\Upgrade;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\View\Data\DataInterface;

class Clear implements \Weline\Framework\Console\CommandInterface
{
    private System $system;
    private Printing $printing;

    public function __construct(
        Printing $printing,
        System   $system
    )
    {
        $this->system   = $system;
        $this->printing = $printing;
    }


    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        unset($args[0]);
        $modules = Env::getInstance()->getModuleList();
        if (empty($modules)) {
            ObjectManager::getInstance(Upgrade::class)->execute();
        }
        $this->printing->note(__('开始清理拓展全页缓存：'));
        foreach ($args as $arg) {
            if (isset($modules[$arg]) && $module_data = $modules[$arg]) {
                $this->clear($arg, $module_data['base_path']);
            } else {
                $this->printing->note(__('模块')) . $this->printing->setup($arg) . $this->printing->note(__('不存在！'));
            }
        }
        if (empty($args)) {
            foreach ($modules as $module_name => $module_data) {
                $this->clear($module_name, $module_data['base_path']);
            }
        }
    }

    public function clear(string $module_name, string $base_path)
    {
        $this->printing->note($module_name);
        if (is_dir($base_path . DataInterface::dir . DS . DataInterface::dir_type_TEMPLATE_COMPILE)) {
//            p($this->system->exec("rm -rf $base_path" . DataInterface::dir . DS . DataInterface::dir_type_TEMPLATE_COMPILE. DS,true));
            $this->system->exec("rm -rf $base_path" . DataInterface::dir . DS . DataInterface::dir_type_TEMPLATE_COMPILE . DS);
            $this->printing->note(__('清理完成：%1', $module_name));
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '清理模板缓存！';
    }
}
