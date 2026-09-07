<?php

declare(strict_types=1);

namespace Weline\Mail\Controller\Frontend\Account;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Service\MailCustomerAccountService;
use Weline\Mail\Service\MailFrontendRegisterService;

final class Register extends FrontendController
{
    public function postApply(): string
    {
        $customerId = ObjectManager::getInstance(MailCustomerAccountService::class)
            ->resolveFrontendCustomerId(null);
        $result = ObjectManager::getInstance(MailFrontendRegisterService::class)->apply(
            $customerId,
            (int)$this->request->getPost('domain_id', 0),
            (string)$this->request->getPost('local_part', ''),
            (string)$this->request->getPost('password', ''),
            (string)$this->request->getPost('aux_email', ''),
            (string)$this->request->getPost('display_name', '')
        );

        return $this->json($result, !empty($result['success']) ? 200 : 422);
    }

    public function postConfirm(): string
    {
        $customerId = ObjectManager::getInstance(MailCustomerAccountService::class)
            ->resolveFrontendCustomerId(null);
        $result = ObjectManager::getInstance(MailFrontendRegisterService::class)->confirm(
            $customerId,
            (string)$this->request->getPost('code', '')
        );

        return $this->json($result, !empty($result['success']) ? 200 : 422);
    }

    private function json(array $data, int $code = 200): string
    {
        $response = $this->request->getResponse();
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        $response->setHeader('Cache-Control', 'no-store');
        $response->setCode($code);

        return json_encode($data, JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
