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
use Weline\Framework\Module\ModuleIdentityProviderInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;
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
        $urlManager->pagination(
            $this->request->getParam('page', 1),
            $this->request->getParam('pageSize', 10),
            $this->request->getParams()
        )->select()
         ->fetch();
        /**@var UrlRewrite $item */
        $items = $urlManager->getItems();
        $moduleIds = [];
        foreach ($items as $item) {
            $moduleId = (int)$item->getData(UrlManager::schema_fields_MODULE_ID);
            if ($moduleId > 0) {
                $moduleIds[] = $moduleId;
            }
        }
        $moduleNames = [];
        $provider = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(ModuleIdentityProviderInterface::class);
        if ($provider instanceof ModuleIdentityProviderInterface) {
            $moduleNames = $provider->namesByIds($moduleIds);
        }
        foreach ($items as &$item) {
            $moduleId = (int)$item->getData(UrlManager::schema_fields_MODULE_ID);
            $item->setData('module_name', (string)($moduleNames[$moduleId] ?? ''));
            $item->setData('can_rewrite', str_ends_with($item['path'], '::GET'));
        }
        $this->assign('urls', $items);
        $this->assign('has_urls', !empty($items));
        $this->assign('current_q', $q);
        $this->assign('pagination', $urlManager->getPagination());
        return $this->fetch();
    }

    public function delete()
    {
    }
}
