<?php

declare(strict_types=1);

namespace WeShop\B2B\Controller\Backend\Account;

use WeShop\B2B\Model\Account;
use WeShop\B2B\Service\PaymentTermService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Manager\ObjectManager;

class Index extends BaseController
{
    public function __construct(
        private readonly PaymentTermService $paymentTermService
    ) {
    }

    public function index(): string
    {
        $this->paymentTermService->ensureDefaultTerms();

        $page = max(1, (int) $this->request->getParam('page', 1));
        $pageSize = max(1, (int) $this->request->getParam('page_size', 20));

        /** @var Account $account */
        $account = ObjectManager::getInstance(Account::class);
        $account->clear()
            ->order(Account::schema_fields_UPDATED_AT, 'DESC')
            ->pagination($page, $pageSize);

        $this->assign([
            'title' => (string) __('B2B Trade Accounts'),
            'accountSaveUrl' => $this->request->getUrlBuilder()->getBackendUrl('*/backend/account/save'),
            'accountIndexUrl' => $this->request->getUrlBuilder()->getBackendUrl('*/backend/account'),
            'items' => $account->select()->fetchArray() ?: [],
            'pagination' => $account->getPagination(),
            'total' => $account->getTotalCount(),
            'paymentTerms' => $this->paymentTermService->listActiveTerms(),
        ]);

        return (string) $this->fetchBase('WeShop_B2B::backend/templates/account/index.phtml');
    }
}
