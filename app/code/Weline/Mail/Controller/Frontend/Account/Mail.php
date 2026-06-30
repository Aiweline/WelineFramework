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
        $fakeMode = $this->postValue('mail_fake') === '1';
        $result = $service->apply(
            $this->currentCustomerId(),
            (int)$this->postValue('domain_id', '0'),
            $this->postValue('local_part'),
            $this->postValue('display_name'),
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
            $this->currentCustomerId(),
            (int)$this->postValue('account_id', '0'),
            $this->postValue('to_email'),
            $this->postValue('subject'),
            $this->postValue('body')
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
            $this->currentCustomerId(),
            (int)$this->postValue('account_id', '0')
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
            $this->currentCustomerId(),
            (int)$this->postValue('account_id', '0'),
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

    private function postValue(string $key, string $default = ''): string
    {
        $value = $this->request->getPost($key);
        if ($value !== null && $value !== '') {
            return is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if (array_key_exists($key, $_POST)) {
            $postValue = $_POST[$key];
            return is_scalar($postValue) ? (string)$postValue : json_encode($postValue, JSON_UNESCAPED_UNICODE);
        }

        $bodyParams = $this->request->getBodyParams(true);
        if (is_array($bodyParams) && array_key_exists($key, $bodyParams)) {
            $bodyValue = $bodyParams[$key];
            return is_scalar($bodyValue) ? (string)$bodyValue : json_encode($bodyValue, JSON_UNESCAPED_UNICODE);
        }

        $rawBody = '';
        if (method_exists($this->request, 'getParameterBag')) {
            $rawBody = (string)$this->request->getParameterBag()->getRawBody();
        }
        if ($rawBody === '') {
            return $default;
        }

        $trimmed = ltrim($rawBody);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $json = json_decode($rawBody, true);
            if (is_array($json) && array_key_exists($key, $json)) {
                $jsonValue = $json[$key];
                return is_scalar($jsonValue) ? (string)$jsonValue : json_encode($jsonValue, JSON_UNESCAPED_UNICODE);
            }
        }

        parse_str($rawBody, $params);
        if (array_key_exists($key, $params)) {
            $paramValue = $params[$key];
            return is_scalar($paramValue) ? (string)$paramValue : json_encode($paramValue, JSON_UNESCAPED_UNICODE);
        }

        return $default;
    }

    private function currentCustomerId(): int
    {
        $user = $this->getLoginUser();
        $customerId = is_object($user) && method_exists($user, 'getId') ? (int)$user->getId() : 0;
        if ($customerId > 0) {
            return $customerId;
        }

        $sessionId = $this->getLoginUserId();
        return is_numeric($sessionId) ? (int)$sessionId : 0;
    }
}
