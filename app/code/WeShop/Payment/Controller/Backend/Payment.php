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
        $filters = [
            'search' => (string) $this->request->getGet('search', ''),
            'country' => strtoupper((string) $this->request->getGet('country', '')),
            'currency' => strtoupper((string) $this->request->getGet('currency', '')),
            'provider' => (string) $this->request->getGet('provider', ''),
            'status' => (string) $this->request->getGet('status', ''),
            'tab' => (string) $this->request->getGet('tab', 'enabled'),
            'scope' => (string) $this->request->getGet('scope', 'default.default.default'),
            'environment' => (string) $this->request->getGet('environment', 'sandbox'),
        ];
        $data = $this->paymentManagementService->getManagementData($filters);

        $this->assign('page_title', (string) __('支付方式'));
        $this->assign('filter_url', $this->_url->getBackendUrl('*/backend/payment'));
        $this->assign('methods', $data['methods'] ?? []);
        $this->assign('all_methods', $data['all_methods'] ?? []);
        $this->assign('providers', $data['providers'] ?? []);
        $this->assign('countries', $data['countries'] ?? []);
        $this->assign('filters', $data['filters'] ?? []);
        $this->assign('scope', $data['scope'] ?? []);
        $this->assign('stats', $data['stats'] ?? []);

        return $this->fetchBase();
    }
}
