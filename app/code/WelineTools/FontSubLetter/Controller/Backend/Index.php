<?php

namespace WelineTools\FontSubLetter\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use WelineTools\FontSubLetter\Model\FontRecord;

class Index extends BackendController
{
    /**
     * 主页面
     */
    public function index()
    {
        $this->assign('title', __('在线字体压缩工具'));
        $this->assign('subtitle', __('Font Subset Tool'));
        return $this->fetch('index');
    }

    /**
     * 字体列表
     */
    public function list()
    {
        $page = (int)$this->request->getParam('page', 1);
        $limit = (int)$this->request->getParam('limit', 20);

        $record = ObjectManager::getInstance(FontRecord::class);
        $collection = $record->getCollection()
            ->setPageSize($limit)
            ->setCurPage($page)
            ->setOrder('created_at', 'DESC');

        $this->assign('records', $collection->getItems());
        $this->assign('total', $collection->getSize());
        $this->assign('page', $page);
        $this->assign('limit', $limit);
        $this->assign('title', __('字体处理记录'));
        $this->assign('subtitle', __('Font Processing Records'));

        return $this->fetch('list');
    }
}
