<?php
declare(strict_types=1);

namespace Weline\Mail\Controller\Frontend\Account;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Service\MailCustomerAccountService;
use Weline\Mail\Service\MailFakeMailboxService;

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
        $fakeMode = (string)($this->request->getPost('mail_fake', '') ?: ($_POST['mail_fake'] ?? '')) === '1';
        $result = $service->apply(
            (int)$this->getLoginUser()->getId(),
            (int)$this->request->getPost('domain_id', 0),
            (string)$this->request->getPost('local_part', ''),
            (string)$this->request->getPost('display_name', ''),
            $fakeMode
        );

        $this->addResultMessage($result);
        return $this->redirect($fakeMode ? 'customer/account/index?mail_fake=1#mail' : 'customer/account/index#mail');
    }

    public function postSuspend(): string
    {
        return $this->changeStatus('suspended');
    }

    public function postResume(): string
    {
        return $this->changeStatus('pending');
    }

    public function postSend(): string
    {
        if (!$this->isLoggedIn()) {
            $this->getMessageManager()->addError(__('请先登录'));
            return $this->redirect('customer/account/login');
        }

        /** @var MailFakeMailboxService $mailboxService */
        $mailboxService = ObjectManager::getInstance(MailFakeMailboxService::class);
        $result = $mailboxService->send(
            (int)$this->getLoginUser()->getId(),
            (int)$this->request->getPost('account_id', 0),
            (string)$this->request->getPost('to_email', ''),
            (string)$this->request->getPost('subject', ''),
            (string)$this->request->getPost('body', '')
        );

        $this->addResultMessage($result);
        return $this->redirect('customer/account/index#mail');
    }

    public function postReceiveTest(): string
    {
        if (!$this->isLoggedIn()) {
            $this->getMessageManager()->addError(__('请先登录'));
            return $this->redirect('customer/account/login');
        }

        /** @var MailFakeMailboxService $mailboxService */
        $mailboxService = ObjectManager::getInstance(MailFakeMailboxService::class);
        $result = $mailboxService->receiveTest(
            (int)$this->getLoginUser()->getId(),
            (int)$this->request->getPost('account_id', 0)
        );

        $this->addResultMessage($result);
        return $this->redirect('customer/account/index#mail');
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
