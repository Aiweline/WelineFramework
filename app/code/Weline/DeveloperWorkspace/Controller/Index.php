<?php

declare(strict_types=1);
/**
 * 文件信息
 * 作者：邹万才
 * 网名：秋风雁飞(Aiweline)
 * 网站：www.aiweline.com/bbs.aiweline.com
 * 工具：PhpStorm
 * 日期：2021/5/13
 * 时间：16:50
 * 描述：此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
 */

namespace Weline\DeveloperWorkspace\Controller;

use Weline\DeveloperWorkspace\Helper\Data;
use Weline\DeveloperWorkspace\Model\Document;

class Index extends BaseController
{
    public function index()
    {
        $catalogsModel = \Weline\DeveloperWorkspace\Helper\Data::getCatalogModel();
        $catalogs      = $catalogsModel->pagination($this->request->getParam('page', 1), $this->request->getParam('page', 10))
                                       ->select()->fetch();
        $this->assign('catalogs', $catalogs);
        # 文章列表
        $this->assign('documents', Data::getDocuments());
        return $this->fetch();
    }
    public function tree()
    {
        $trees = $this->getCatalogModel()->getTree();
        $trees = $this->processTrees($trees);
        return $this->fetchJson($trees);
    }
    private function getCatalogModel(): \Weline\DeveloperWorkspace\Model\Document\Catalog
    {
        return $this->_objectManager::getInstance(\Weline\DeveloperWorkspace\Model\Document\Catalog::class);
    }

    private function processTrees(array &$trees)
    {
        foreach ($trees as &$tree) {
            $tree['text']       = '<a class="btn" href="' . $this->_url->getUrl('/dev/tool/catalog', ['id' => $tree['id']]) . '">' . $tree['text'] . '</a>
<a class="btn btn-info pull-right" href="' . $this->_url->getUrl('/dev/tool/catalog', ['id' => $tree['id']]) . '">查看</a>
';
            $tree['selectable'] = true;
            $tree['state']      = [
                'checked'  => false,
                'disabled' => !$tree['is_active'],
                'expanded' => true,
                'selected' => false
            ];
            $tree['tags']       = ['available'];
            $tree['href']       = $this->_url->getUrl('dev/tool/document/catalog', ['id' => $tree['id']]);
            if (isset($tree['nodes']) and count($tree['nodes'])) {
                $this->processTrees($tree['nodes']);
            }
        }
        return $trees;
    }
}
