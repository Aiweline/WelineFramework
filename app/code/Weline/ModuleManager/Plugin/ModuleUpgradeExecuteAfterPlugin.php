<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/6/23 09:23:12
 */

namespace Weline\ModuleManager\Plugin;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\ModuleManager\Model\Module;

class ModuleUpgradeExecuteAfterPlugin
{
    function afterExecute($object, $result)
    {
        # 获取modules
        $modules = (include Env::path_MODULES_FILE) ?? [];
        # 模型
        /**@var Module $moduleModel */
        $moduleModel = ObjectManager::getInstance(Module::class);
        $moduleModel->query("truncate table {$moduleModel->getTable()}");
        # 写入数据库
        foreach ($modules as $module) {
            $module['base_path'] = str_replace(BP, '', $module['base_path']);
            $module['description'] = htmlspecialchars($module['description']);
            $module['status'] = $module['status'] ? 1 : 0;
            $moduleModel->clearData()
                ->setModelFieldsData($module)
                ->save(true, Module::fields_NAME);
        }
    }
}