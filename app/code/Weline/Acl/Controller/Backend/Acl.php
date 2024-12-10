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
        if($search = $this->request->getGet('search')){
            $aclModelFields = implode(',', $aclModel->getModelFields());
            $aclModel->where('CONCAT('.$aclModelFields.')','%'.$search.'%','like');
        }
        $aclModel->pagination()->select()->fetch();
        $this->assign('acls',$aclModel->getItems());
        $this->assign('pagination',$aclModel->getPagination());
        return $this->fetch('index');
    }
}