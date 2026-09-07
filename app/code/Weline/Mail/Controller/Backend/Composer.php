<?php

declare(strict_types=1);

namespace Weline\Mail\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Service\MailComposerService;
use Weline\Mail\Service\MailSmtpAccountService;

final class Composer extends BackendController
{
    #[\Weline\Framework\Acl\Acl(
        'Weline_Mail::mail_composer_thread',
        '企业邮箱沟通线程',
        'composer',
        '按业务来源加载企业邮箱沟通线程',
        'Weline_Mail::mail'
    )]
    public function thread(): string
    {
        $source = (string)$this->request->getGet('source', $this->request->getParam('source', ''));
        $sourceId = (int)$this->request->getGet('source_id', $this->request->getParam('source_id', 0));
        $items = ObjectManager::getInstance(MailComposerService::class)
            ->listThreadBySource($source, $sourceId);

        return $this->jsonResponse([
            'success' => true,
            'items' => $items,
        ]);
    }

    #[\Weline\Framework\Acl\Acl(
        'Weline_Mail::mail_composer_send',
        '企业邮箱沟通发送',
        'composer',
        '经写信浮层发送企业邮箱并写入业务线程',
        'Weline_Mail::mail'
    )]
    public function postSend(): string
    {
        $payload = $this->request->getBodyParams();
        if (!is_array($payload) || $payload === []) {
            $payload = $this->request->getParams();
        }
        if (!is_array($payload)) {
            $payload = [];
        }

        $requestedAccountId = (int)($payload['account_id'] ?? 0);
        $accountId = $this->resolveSendAsAccountId($requestedAccountId);
        $result = ObjectManager::getInstance(MailComposerService::class)->send(
            $accountId,
            (string)($payload['to'] ?? ''),
            (string)($payload['subject'] ?? ''),
            (string)($payload['body'] ?? ''),
            (string)($payload['source'] ?? ''),
            (int)($payload['source_id'] ?? 0)
        );

        return $this->jsonResponse($result, !empty($result['success']) ? 200 : (int)($result['code'] ?? 422));
    }

    private function resolveSendAsAccountId(int $requestedAccountId): int
    {
        $userId = (int)($this->session->getLoginUserID() ?? 0);
        $canPick = $userId === 1;
        if (!$canPick && $userId > 0) {
            try {
                $ctx = \Weline\Backend\Model\BackendUser::getAclContext($userId);
                $roleId = (int)($ctx['role_id'] ?? 0);
                if ($roleId > 0) {
                    $canPick = ObjectManager::getInstance(
                        \Weline\Acl\Api\Authorization\ResourceAuthorizationServiceInterface::class
                    )->isSourceAllowed($roleId, 'Weline_Mail::mail_send_as');
                }
            } catch (\Throwable) {
                $canPick = false;
            }
        }

        $smtp = ObjectManager::getInstance(MailSmtpAccountService::class);
        if ($canPick) {
            $cfg = $smtp->getAccountConfig($requestedAccountId);
            return is_array($cfg) ? $requestedAccountId : 0;
        }

        $email = '';
        try {
            $user = ObjectManager::getInstance(\Weline\Backend\Model\BackendUser::class)->load($userId);
            if ($user->getId()) {
                $email = strtolower(trim((string)$user->getEmail()));
            }
        } catch (\Throwable) {
        }
        $resolved = $smtp->resolveLocalMailboxByEmail($email);
        if (empty($resolved['local']) || !is_array($resolved['mailbox'] ?? null)) {
            return 0;
        }

        return (int)($resolved['mailbox']['account_id'] ?? 0);
    }

    private function jsonResponse(array $data, int $code = 200): string
    {
        $response = $this->request->getResponse();
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        $response->setHeader('Cache-Control', 'no-store');
        $response->setCode($code);

        return json_encode($data, JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
