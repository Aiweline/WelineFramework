<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 *
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/6/28 17:29:24
 */
namespace Weline\Acl\Plugin;

class ModuleUpgradeExecuteAfterPlugin
{
    /**
     * 模块升级前的 ACL 处理入口。
     *
     * 菜单与权限统一采用增量同步，禁止 TRUNCATE 清表。
     * 
     * @param mixed $subject Upgrade 实例
     * @param array ...$args execute 方法的参数 [$args, $data]
     */
    function beforeExecute($subject, ...$args)
    {
        // ACL 改为由增量同步维护，禁止升级阶段执行 TRUNCATE。
        return;
    }
}