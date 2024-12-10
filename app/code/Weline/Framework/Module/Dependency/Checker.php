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
     * 如果存在则返回对应的值。如果不存在，则返回false。
     * 可以返回任何东西，但请尽可能说明状态，以便向其他者说：你好！这个依赖于我，谁能证明它是你的？
     *
     * @param string $name 要检查的依赖项的名称
     *
     * @return bool 是否存在
     */
    static public function hasDependency(string $dependency_module): bool|string
    {
        $dependencies = Env::getInstance()->getModuleList();
        if (empty($dependencies)) {
            return false;
        }
        if (!isset($dependencies[$dependency_module])) {
            return $dependency_module;
        }
        return true;
    }
}