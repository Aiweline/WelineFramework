<?php

namespace Weline\Taglib\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\ModuleManager\Model\Module;

#[Acl('Weline_Taglib::taglib_manager', '标签管理', 'fa fa-tags', '标签查看文档', 'Weline_Taglib::taglib')]
class Taglib extends BackendController
{
    private \Weline\Taglib\Model\Taglib $taglib;

    public function __construct(\Weline\Taglib\Model\Taglib $taglib)
    {
        $this->taglib = $taglib;
    }

    #[Acl('Weline_Taglib::taglib_listing', '标签列表', 'fa fa-list', '标签列表')]
    public function listing()
    {
        if ($q = $this->request->getGet('q')) {
            $this->taglib->where('main_table.name', "%$q%", 'like', 'or')
                ->where('module.name', "%$q%", 'like', 'and');
        }
        $listing = $this->taglib
            ->joinModel(Module::class, 'module', 'main_table.module_id=module.module_id')
            ->pagination()
            ->order($this->taglib::fields_UPDATE_TIME)
            ->select()
            ->fetch();
        $this->assign('items', $listing->getItems());
        $this->assign('pagination', $listing->getPagination());
        return $this->fetch();
    }

    #[Acl('Weline_Taglib::taglib_document', '标签文档', 'fa fa-list', '查看标签文档')]
    public function document()
    {
        $id = $this->request->getGet('id');
        if (!$id) {
            die('标签文档不存在!');
        }
        $document = $this->taglib->load($id);
        $this->assign('document', $document);
        return $this->fetch();
    }
}
