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

use Weline\Acl\Model\Acl;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Db\Setup;

class ModuleUpgradeExecuteAfterPlugin
{
    private Acl $acl;
    function __construct(
        Acl $acl
    )
    {
        $this->acl = $acl;
    }
    /**
     * 在模块升级执行前清空ACL表
     * 
     * 注意：此方法在系统升级开始前执行，清空ACL表以便重新收集所有权限
     * 权限收集是在升级过程中通过 ControllerAttributes 观察者进行的
     */
    function beforeExecute()
    {
        try {
            // 清空ACL表，以便重新收集所有权限
            if ($this->acl->getTable()) {
                $this->acl->query("TRUNCATE TABLE {$this->acl->getTable()}");
            }
        } catch (\Exception $e) {
            // 清空表失败不影响升级流程，只记录错误
            if (defined('DEV') && DEV) {
                error_log("清空ACL表失败: " . $e->getMessage());
            }
        }
    }
}