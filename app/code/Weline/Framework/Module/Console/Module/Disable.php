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
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Helper\Data;
use Weline\Backend\Service\MenuCollector;

class Disable extends CommandAbstract
{
    public function execute(array $args = [], array $data = [])
    {
        $command     = array_shift($args);
        $module_list = Env::getInstance()->getModuleList();
        if (empty($module_list)) {
            $this->printer->error('请先更新模块:bin/w module:upgrade');
            exit();
        }
        if (!empty($args)) {
            $disabledModules = [];
            foreach ($args as $module) {
                if (isset($module_list[$module])) {
                    $module_list[$module]['status'] = false;
                    $disabledModules[] = $module;
                    $this->printer->printing('已禁用！', $this->printer->colorize($module, $this->printer::ERROR), $this->printer::ERROR);
                    $this->printer->printList([$module => $module_list[$module]], '=>');
                } else {
                    $this->printer->error('不存在的模块:' . $module);
                }
            }
            // 更新模块信息（updateModules 会统一触发注册表刷新）
            /**@var Data $helper */
            $helper = ObjectManager::getInstance(Data::class);
            $helper->updateModules($module_list);
            $this->printer->success(__('注册表已更新完成。'));

            // 禁用对应模块的后台菜单（软禁用 is_enable=0，包括 WeShop_Cms::cms_page_management 等）
            if (!empty($disabledModules)) {
                try {
                    /** @var MenuCollector $menuCollector */
                    $menuCollector = ObjectManager::getInstance(MenuCollector::class);
                    $menuCollector->collect($disabledModules);
                    $this->printer->success(__('已根据禁用模块更新后台菜单状态。'));
                } catch (\Throwable $e) {
                    $this->printer->warning(__('菜单更新失败：%{1}，请手动执行后台菜单收集命令', [$e->getMessage()]));
                }
            }
        } else {
            $this->printer->printList([$command => ['禁用提示：' => $this->printer->colorize('请输入要禁用的模块', $this->printer::ERROR)]]);
        }
    }

    public function tip(): string
    {
        return '禁用模块';
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
