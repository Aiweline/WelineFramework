<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Module\Console\Module;

use Weline\Framework\App\Env;
use Weline\Framework\App\System;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\ConsoleException;
use Weline\Framework\Module\Handle;
use Weline\Framework\Module\Helper\Data;

class Remove extends CommandAbstract
{
    /**
     * @var System
     */
    private System $system;

    /**
     * @var Data
     */
    private Data $data;

    /**
     * @var Upgrade
     */
    private Upgrade $upgrade;

    /**
     * @var Handle
     */
    private Handle $handle;

    public function __construct(
        System  $system,
        Data    $data,
        Upgrade $upgrade,
        Handle  $handle
    )
    {
        $this->system  = $system;
        $this->data    = $data;
        $this->upgrade = $upgrade;
        $this->handle  = $handle;
    }

    /**
     * @DESC         |执行方法
     *
     * 参数区：
     *
     * @param array $args
     *
     * @return mixed|void
     * @throws \Weline\Framework\App\Exception
     * @throws ConsoleException
     */
    public function execute(array $args = [], array $data = [])
    {
        array_shift($args);

        // 获得模块列表
        $module_list = Env::getInstance()->getModuleList();
        if (empty($args)) {
            throw new ConsoleException('缺少模块名参数。示例：module:remove Aiweline_demo Aiweline_Test');
        }
        $this->printer->setup(__('提示：此命令将执行以下模块的卸载程序。'));
        foreach ($args as $key => $module) {
            $this->printer->warning($module);
            if (!isset($module_list[$module])) {
                unset($args[$key]);
                $this->printer->warning($module . __('模块不存在！'));
            } elseif (!is_dir($module_list[$module]['base_path'])) {
                unset($args[$key]);
                $this->printer->setup(__('%1 模块已安装，但是代码已不存在，是否彻底卸载（y/n）？', $module));
                // 控制台输入
                $input = $this->system->input();
                if (strtolower(chop($input)) === 'y') {
                    unset($module_list[$module]);
                    $this->data->updateModules($module_list);
                    $this->printer->success($module . __('模块卸载成功！'));
                }
            }
        }
        if (empty($args)) {
            $this->printer->error(__('无可卸载模块'));
        } else {
            $this->printer->setup(__('是否继续（y/n）？'));
            // 控制台输入
            $input = $this->system->input();
            if (strtolower(chop($input)) === 'y') {
                if (empty($module_list)) {
                    $this->printer->error('请先更新模块:bin/w module:upgrade');
                    exit();
                }
                foreach ($args as $module) {
                    $this->printer->note(__('执行 ') . $module . __(' 卸载程序...'));
                    $this->handle->remove($module);
                    // 卸载数组中模块
                    unset($module_list[$module]);
                }
                // 更新模块数据
//            $this->printer->warning(__('请手动执行：php bin/w module:upgrade'));
                $this->data->updateModules($module_list);
//            $this->upgrade->execute();
                # 请继续执行 php bin/w module:upgrade
                $this->printer->printing('请继续执行 php bin/w module:upgrade', $this->printer::WARNING);
            } else {
                $this->printer->warning(__('已取消执行！'));
            }
        }
    }

    /**
     * @DESC         |命令提示
     *
     * 参数区：
     *
     * @return string
     */
    public function tip(): string
    {
        return '移除模块以及模块数据！并执行卸载脚本（如果有）';
    }
}
