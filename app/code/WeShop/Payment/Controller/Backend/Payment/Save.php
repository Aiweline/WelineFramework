<?php

declare(strict_types=1);

namespace WeShop\Payment\Controller\Backend\Payment;

use WeShop\Payment\Service\PaymentManagementService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;

#[Acl('WeShop_Payment::payment_actions', '支付方式操作', 'mdi mdi-credit-card-edit-outline', '保存支付方式配置', 'WeShop_Payment::payment')]
class Save extends BaseController
{
    public function __construct(
        private readonly PaymentManagementService $paymentManagementService
    ) {
    }

    #[Acl('WeShop_Payment::payment_save', '保存支付配置', 'mdi mdi-content-save', '保存支付方式运行时配置')]
    public function post(): string
    {
        if (!$this->request->isPost()) {
            $this->getMessageManager()->addError(__('Invalid request method.'));
            $this->redirect('*/backend/payment');

            return '';
        }

        try {
            $result = $this->paymentManagementService->save([
                'default_method' => (string) $this->request->getPost('default_method', ''),
                'methods' => $this->request->getPost('methods', []),
                'test_method' => (string) $this->request->getPost('test_method', ''),
                'scope_type' => (string) $this->request->getPost('scope_type', 'global'),
                'scope_code' => (string) $this->request->getPost('scope_code', 'default'),
                'environment' => (string) $this->request->getPost('environment', 'sandbox'),
            ]);
            $testResult = \is_array($result['test_result'] ?? null) ? $result['test_result'] : null;
            if ($testResult !== null) {
                $message = (string) ($testResult['message'] ?? '');
                if (!empty($testResult['success'])) {
                    $this->getMessageManager()->addSuccess(__('Payment configuration test passed: %{1}', [$message]));
                } else {
                    $this->getMessageManager()->addError(__('Payment configuration test failed: %{1}', [$message]));
                }
            } else {
                $this->getMessageManager()->addSuccess(__('Payment settings saved successfully.'));
            }
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError(__('Payment settings save failed: %{1}', [$throwable->getMessage()]));
        }

        $query = [
            'tab' => (string) $this->request->getPost('tab', 'credentials'),
            'scope_type' => (string) $this->request->getPost('scope_type', 'global'),
            'scope_code' => (string) $this->request->getPost('scope_code', 'default'),
            'environment' => (string) $this->request->getPost('environment', 'sandbox'),
        ];
        $this->redirect('*/backend/payment?' . http_build_query($query));

        return '';
    }
}
