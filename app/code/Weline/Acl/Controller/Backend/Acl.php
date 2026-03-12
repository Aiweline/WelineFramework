<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/1/7 20:39:18
 */

namespace Weline\Acl\Controller\Backend;

use Weline\Framework\Manager\ObjectManager;

#[\Weline\Framework\Acl\Acl('Weline_Acl::acl', '管理权限','mdi mdi-security', '')]
class Acl extends \Weline\Admin\Controller\BaseController
{
    function getIndex()
    {
        /**@var \Weline\Acl\Model\Acl $aclModel*/
        $aclModel = ObjectManager::getInstance(\Weline\Acl\Model\Acl::class);
        if ($search = $this->request->getGet('search')) {
            $connector = $aclModel->getConnection()->getConnector();
            $alias = 'main_table';
            $quotedFields = [];
            $reserved = ['order', 'key', 'table', 'fields'];
            foreach ($aclModel->getModelFields() as $f) {
                $quoted = $connector->quoteIdentifier($f);
                if (in_array(strtolower((string)$f), $reserved, true) && !str_contains($quoted, '"')) {
                    $quoted = '"' . str_replace('"', '""', (string)$f) . '"';
                }
                $quotedFields[] = str_contains((string)$f, '.') ? $quoted : ($alias . '.' . $quoted);
            }
            $aclModel->where('CONCAT(' . implode(',', $quotedFields) . ')', '%' . $search . '%', 'like');
        }
        $aclModel->pagination()->select()->fetch();
        $this->assign('acls',$aclModel->getItems());
        $this->assign('pagination',$aclModel->getPagination());
        return $this->fetch('index');
    }
}