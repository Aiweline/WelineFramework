<?php

declare(strict_types=1);

namespace WeShop\Payment\Controller\Backend;

use WeShop\Payment\Service\PaymentManagementService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;

#[Acl('WeShop_Payment::payment', '支付方式', 'mdi mdi-credit-card-settings-outline', '管理支付方式与运行时配置', 'Weline_Backend::payment_group')]
class Payment extends BaseController
{
    public function __construct(
        private readonly PaymentManagementService $paymentManagementService
    ) {
    }

    #[Acl('WeShop_Payment::payment_index', '查看支付方式', 'mdi mdi-credit-card-outline', '查看支付方式管理页面')]
    public function index(): string
    {
        $data = $this->paymentManagementService->getManagementData();

        $this->assign('page_title', (string) __('Payment Methods'));
        $this->assign('save_url', $this->_url->getBackendUrl('*/backend/payment/save'));
        $this->assign('methods', $data['methods'] ?? []);
        $this->assign('stats', $data['stats'] ?? []);

        return $this->fetchBase();
    }
}
