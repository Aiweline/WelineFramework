<?php
declare(strict_types=1);

namespace Weline\Mail\Controller\Frontend\Account;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Service\MailCustomerAccountService;

class Mail extends FrontendController
{
    public function postApply(): string
    {
        if (!$this->isLoggedIn()) {
            $this->getMessageManager()->addError(__('请先登录'));
            return $this->redirect('customer/account/login');
        }

        /** @var MailCustomerAccountService $service */
        $service = ObjectManager::getInstance(MailCustomerAccountService::class);
        $result = $service->apply(
            (int)$this->getLoginUser()->getId(),
            (int)$this->request->getPost('domain_id', 0),
            (string)$this->request->getPost('local_part', ''),
            (string)$this->request->getPost('display_name', '')
        );

        $this->addResultMessage($result);
        return $this->redirect('customer/account/index#mail');
    }

    public function postSuspend(): string
    {
        return $this->changeStatus('suspended');
    }

    public function postResume(): string
    {
        return $this->changeStatus('pending');
    }

    private function changeStatus(string $status): string
    {
        if (!$this->isLoggedIn()) {
            $this->getMessageManager()->addError(__('请先登录'));
            return $this->redirect('customer/account/login');
        }

        /** @var MailCustomerAccountService $service */
        $service = ObjectManager::getInstance(MailCustomerAccountService::class);
        $result = $service->updateStatus(
            (int)$this->getLoginUser()->getId(),
            (int)$this->request->getPost('account_id', 0),
            $status
        );

        $this->addResultMessage($result);
        return $this->redirect('customer/account/index#mail');
    }

    private function addResultMessage(array $result): void
    {
        if (!empty($result['success'])) {
            $this->getMessageManager()->addSuccess((string)($result['message'] ?? __('操作成功')));
            return;
        }

        $this->getMessageManager()->addError((string)($result['message'] ?? __('操作失败')));
    }
}
