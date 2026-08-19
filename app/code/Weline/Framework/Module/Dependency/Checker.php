<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/6/23 20:55:07
 */

namespace Weline\Framework\Module\Dependency;

use Weline\Framework\App\Env;

class Checker
{

    /**
     * 检查依赖关系是否存在。
     * 已注册返回 true；模块表为空或依赖缺失返回 false。
     */
    static public function hasDependency(string $dependency_module): bool
    {
        $dependencies = Env::getInstance()->getModuleList();
        if ($dependencies === [] || $dependencies === null) {
            return false;
        }
        return isset($dependencies[$dependency_module]);
    }
}