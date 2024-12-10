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
    function beforeExecute()
    {
        /**@var Setup $setup*/
        $setup = ObjectManager::getInstance(Setup::class);
        if($setup->setConnection($this->acl->getConnection())->tableExist($this->acl->getTable())){
            $this->acl->query("TRUNCATE TABLE {$this->acl->getTable()}");
        }
    }
}