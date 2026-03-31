<?php

declare(strict_types=1);

namespace WeShop\B2B\Controller\Backend\Credit;

use WeShop\B2B\Model\Credit;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Manager\ObjectManager;

class Index extends BaseController
{
    public function index(): string
    {
        $page = max(1, (int) $this->request->getParam('page', 1));
        $pageSize = max(1, (int) $this->request->getParam('page_size', 20));

        /** @var Credit $credit */
        $credit = ObjectManager::getInstance(Credit::class);
        $credit->clear()
            ->order(Credit::schema_fields_UPDATED_AT, 'DESC')
            ->pagination($page, $pageSize);

        $this->assign([
            'title' => (string) __('B2B Credit Lines'),
            'creditSaveUrl' => $this->request->getUrlBuilder()->getBackendUrl('*/backend/credit/save'),
            'creditIndexUrl' => $this->request->getUrlBuilder()->getBackendUrl('*/backend/credit'),
            'items' => $credit->select()->fetchArray() ?: [],
            'pagination' => $credit->getPagination(),
            'total' => $credit->getTotalCount(),
        ]);

        return (string) $this->fetchBase('WeShop_B2B::backend/templates/credit/index.phtml');
    }
}
