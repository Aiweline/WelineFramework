<?php

declare(strict_types=1);

namespace Weline\Mail\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Service\MailComposerService;
use Weline\Mail\Service\MailCustomerAccountService;

final class Composer extends FrontendController
{
    public function thread(): string
    {
        $customerId = $this->resolveCustomerId();
        if ($customerId <= 0) {
            return $this->json(['success' => false, 'message' => __('请先登录'), 'items' => []], 401);
        }

        $source = (string)$this->request->getGet('source', $this->request->getParam('source', ''));
        $sourceId = (int)$this->request->getGet('source_id', $this->request->getParam('source_id', 0));
        $items = ObjectManager::getInstance(MailComposerService::class)
            ->listThreadBySource($source, $sourceId);

        return $this->json(['success' => true, 'items' => $items]);
    }

    public function postSend(): string
    {
        $customerId = $this->resolveCustomerId();
        if ($customerId <= 0) {
            return $this->json(['success' => false, 'message' => __('请先登录')], 401);
        }

        $payload = $this->request->getBodyParams();
        if (!is_array($payload) || $payload === []) {
            $payload = $this->request->getParams();
        }
        if (!is_array($payload)) {
            $payload = [];
        }

        $accountId = (int)($payload['account_id'] ?? 0);
        $mailService = ObjectManager::getInstance(MailCustomerAccountService::class);
        $owned = false;
        foreach ($mailService->getActiveAccountsForCustomer($customerId) as $account) {
            if ((int)$account->getId() === $accountId) {
                $owned = true;
                break;
            }
        }
        if (!$owned) {
            return $this->json(['success' => false, 'message' => __('请选择已绑定的本机企业邮箱')], 422);
        }

        $result = ObjectManager::getInstance(MailComposerService::class)->send(
            $accountId,
            (string)($payload['to'] ?? ''),
            (string)($payload['subject'] ?? ''),
            (string)($payload['body'] ?? ''),
            (string)($payload['source'] ?? ''),
            (int)($payload['source_id'] ?? 0)
        );

        return $this->json($result, !empty($result['success']) ? 200 : (int)($result['code'] ?? 422));
    }

    private function resolveCustomerId(): int
    {
        try {
            return ObjectManager::getInstance(MailCustomerAccountService::class)
                ->resolveFrontendCustomerId(null);
        } catch (\Throwable) {
            return 0;
        }
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
