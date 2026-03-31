<?php

declare(strict_types=1);

namespace WeShop\Compare\Controller\Backend\Compare;

use WeShop\Compare\Service\CompareAdminPageDataService;
use Weline\Admin\Controller\BaseController;

class Index extends BaseController
{
    public function __construct(
        private readonly CompareAdminPageDataService $compareAdminPageDataService
    ) {
    }

    public function index(): string
    {
        $page = max(1, (int) $this->request->getParam('page', 1));
        $pageSize = max(1, (int) $this->request->getParam('page_size', 20));
        $editingId = (int) $this->request->getParam('id', 0);

        $filters = [
            'customer_id' => $this->request->getParam('customer_id', ''),
            'product_id' => $this->request->getParam('product_id', ''),
        ];

        $this->assign(array_merge(
            [
                'title' => (string) __('Compare Management'),
                'compareIndexUrl' => $this->_url->getBackendUrl('*/backend/compare'),
                'compareDeleteUrl' => $this->_url->getBackendUrl('*/backend/compare/delete'),
            ],
            $this->compareAdminPageDataService->getPageData($page, $pageSize, $filters, $editingId)
        ));

        return (string) $this->fetch('WeShop_Compare::backend/templates/compare/index.phtml');
    }

    public function delete(): string
    {
        $compareId = (int) $this->request->getParam('id', 0);

        if ($compareId <= 0) {
            $this->getMessageManager()->addError(__('Invalid compare record ID.'));
            $this->redirect($this->_url->getBackendUrl('*/backend/compare'));
            return '';
        }

        $result = $this->compareAdminPageDataService->delete($compareId);

        if ($result) {
            $this->getMessageManager()->addSuccess(__('Compare record deleted successfully.'));
        } else {
            $this->getMessageManager()->addError(__('Failed to delete compare record.'));
        }

        $this->redirect($this->_url->getBackendUrl('*/backend/compare'));
        return '';
    }
}
