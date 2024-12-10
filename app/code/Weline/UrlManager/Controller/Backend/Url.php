<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/9/18 14:08:56
 */

namespace Weline\UrlManager\Controller\Backend;

use Weline\Framework\Manager\ObjectManager;
use Weline\ModuleManager\Model\Module;
use Weline\UrlManager\Model\UrlManager;
use Weline\UrlManager\Model\UrlRewrite;

class Url extends \Weline\Framework\App\Controller\BackendController
{
    public function listing()
    {
        /**@var UrlManager $urlManager */
        $urlManager = ObjectManager::getInstance(UrlManager::class);
        # 搜索词
        $q = $this->request->getParam('q', '');
        if ($q) {
            $urlManager->where('path', "%{$q}%", 'like');
        }
        $urlManager->joinModel(
            Module::class,
            'm',
            'main_table.module_id=m.module_id',
            'left',
            'm.name as module_name'
        )->pagination(
            $this->request->getParam('page', 1),
            $this->request->getParam('pageSize', 10),
            $this->request->getParams()
        )->select()
         ->fetch();
        /**@var UrlRewrite $item */
        $items = $urlManager->getItems();
        foreach ($items as &$item) {
            $item->setData('can_rewrite', str_ends_with($item['path'], '::GET'));
        }
        $this->assign('urls', $items);
        $this->assign('pagination', $urlManager->getPagination());
        return $this->fetch();
    }

    public function delete()
    {
    }
}
