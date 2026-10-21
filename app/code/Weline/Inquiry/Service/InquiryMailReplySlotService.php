<?php

declare(strict_types=1);

namespace Weline\Inquiry\Service;

use Weline\Acl\Api\Authorization\ResourceAuthorizationServiceInterface;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Registry\Service\RegistryModulePresence;

/**
 * 询盘后台的企业邮箱回复槽：本人邮箱优先，超管/无本人邮箱时可搜索选择后台邮箱。
 * 同时提供 mailto 站外回复；实际发送走 Weline_Mail 的 MailComposerService。
 */
final class InquiryMailReplySlotService
{
    public const SOURCE = 'inquiry';

    /**
     * @param array<string,mixed> $submission
     * @param array<int,string> $formNames
     * @return array{
     *   enabled:bool,
     *   mode:string,
     *   message:string,
     *   mailboxes:list<array<string,mixed>>,
     *   default_account_id:int,
     *   composer:array<string,mixed>,
     *   mailto_url:string
     * }
     */
    public function buildForSubmission(array $submission, array $formNames, int $backendUserId): array
    {
        $empty = [
            'enabled' => false,
            'mode' => 'hidden',
            'message' => '',
            'mailboxes' => [],
            'default_account_id' => 0,
            'composer' => [],
            'mailto_url' => '',
        ];

        if (!RegistryModulePresence::isActivePresent('Weline_Mail')) {
            return $empty;
        }

        $submissionId = (int)($submission['submission_id'] ?? 0);
        $formId = (int)($submission['form_id'] ?? 0);
        $payload = json_decode((string)($submission['payload_json'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $toEmail = strtolower(trim((string)($payload['email'] ?? '')));
        if ($toEmail === '' || filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
            return [
                'enabled' => true,
                'mode' => 'unavailable',
                'message' => (string)__('该询盘无有效邮箱'),
                'mailboxes' => [],
                'default_account_id' => 0,
                'composer' => [],
                'mailto_url' => '',
            ];
        }

        $formName = trim((string)($formNames[$formId] ?? ''));
        $name = trim((string)($payload['name'] ?? $payload['contact_name'] ?? $payload['company'] ?? ''));
        $message = trim((string)($payload['message'] ?? ''));
        $subject = $this->buildSubject($formName, $submissionId);
        $body = $this->buildBody($name, $message);
        $mailto = $this->buildMailto($toEmail, $subject, $body);

        $mailboxes = $this->listLocalMailboxes();
        $own = $this->resolveLocalMailboxByEmail($this->resolveBackendUserEmail($backendUserId));
        $canPick = $this->canPickAnyLocalMailbox($backendUserId);

        if ($mailboxes === []) {
            return [
                'enabled' => true,
                'mode' => 'external',
                'message' => (string)__('未开通本机企业邮箱，请使用站外回复'),
                'mailboxes' => [],
                'default_account_id' => 0,
                'composer' => [],
                'mailto_url' => $mailto,
            ];
        }

        if ($canPick || empty($own['local'])) {
            $defaultId = 0;
            if (!empty($own['local']) && is_array($own['mailbox'] ?? null)) {
                $defaultId = (int)($own['mailbox']['account_id'] ?? 0);
            }
            if ($defaultId <= 0) {
                $defaultId = (int)($mailboxes[0]['account_id'] ?? 0);
            }

            return [
                'enabled' => true,
                'mode' => 'pick',
                'message' => (string)__('可选择发件邮箱（可搜索）'),
                'mailboxes' => $mailboxes,
                'default_account_id' => $defaultId,
                'composer' => $this->buildComposerPayload($toEmail, $subject, $body, $defaultId, $submissionId, $mailboxes),
                'mailto_url' => $mailto,
            ];
        }

        $accountId = (int)($own['mailbox']['account_id'] ?? 0);
        $boxes = [$own['mailbox']];

        return [
            'enabled' => true,
            'mode' => 'own',
            'message' => (string)__('使用本人邮箱'),
            'mailboxes' => $boxes,
            'default_account_id' => $accountId,
            'composer' => $this->buildComposerPayload($toEmail, $subject, $body, $accountId, $submissionId, $boxes),
            'mailto_url' => $mailto,
        ];
    }

    /**
     * 解析本次实际发件账号：本人有邮箱时必须用本人；超管/无本人邮箱时按选择。
     */
    public function resolveAccountId(int $backendUserId, int $requestedAccountId): int
    {
        if (!RegistryModulePresence::isActivePresent('Weline_Mail')) {
            return 0;
        }

        $mailboxes = $this->listLocalMailboxes();
        if ($mailboxes === []) {
            return 0;
        }
        $ids = [];
        foreach ($mailboxes as $mailbox) {
            $ids[(int)($mailbox['account_id'] ?? 0)] = true;
        }

        $own = $this->resolveLocalMailboxByEmail($this->resolveBackendUserEmail($backendUserId));
        $ownLocal = !empty($own['local']) && is_array($own['mailbox'] ?? null);
        $canPick = $this->canPickAnyLocalMailbox($backendUserId);

        if ($ownLocal && !$canPick) {
            return (int)($own['mailbox']['account_id'] ?? 0);
        }
        if ($requestedAccountId > 0 && isset($ids[$requestedAccountId])) {
            return $requestedAccountId;
        }
        if ($ownLocal) {
            return (int)($own['mailbox']['account_id'] ?? 0);
        }

        return 0;
    }

    /**
     * @param list<array<string,mixed>> $mailboxes
     * @return array<string,mixed>
     */
    private function buildComposerPayload(
        string $to,
        string $subject,
        string $body,
        int $accountId,
        int $submissionId,
        array $mailboxes
    ): array {
        return [
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'account_id' => $accountId,
            'source' => self::SOURCE,
            'source_id' => $submissionId,
            'mailboxes' => $mailboxes,
        ];
    }

    private function buildSubject(string $formName, int $submissionId): string
    {
        $formName = $formName !== '' ? $formName : (string)__('询盘');

        return (string)__('Re: %{1} #%{2}', [$formName, (string)$submissionId]);
    }

    private function buildBody(string $name, string $message): string
    {
        $lines = [];
        $lines[] = $name !== '' ? (string)__('Hi %{1},', [$name]) : (string)__('Hello,');
        $lines[] = '';
        $lines[] = (string)__('Thank you for contacting PlayingInChina. Here is our reply:');
        $lines[] = '';
        if ($message !== '') {
            $lines[] = (string)__('Your message:');
            $lines[] = $message;
            $lines[] = '';
        }
        $lines[] = (string)__('Best regards,');

        return implode("\n", $lines);
    }

    private function buildMailto(string $to, string $subject, string $body): string
    {
        return 'mailto:' . $to
            . '?subject=' . rawurlencode($subject)
            . '&body=' . rawurlencode($body);
    }

    private function canPickAnyLocalMailbox(int $backendUserId): bool
    {
        if ($backendUserId === 1) {
            return true;
        }
        if ($backendUserId <= 0) {
            return false;
        }
        $ctx = BackendUser::getAclContext($backendUserId);
        $roleId = (int)($ctx['role_id'] ?? 0);
        if ($roleId <= 0) {
            return false;
        }
        try {
            return ObjectManager::getInstance(ResourceAuthorizationServiceInterface::class)
                ->isSourceAllowed($roleId, 'Weline_Mail::mail_send_as');
        } catch (\Throwable $e) {
            w_log_warning('Inquiry reply mailbox ACL resolve failed: ' . $e->getMessage(), [], 'inquiry_mail_reply');
            return false;
        }
    }

    private function resolveBackendUserEmail(int $backendUserId): string
    {
        if ($backendUserId <= 0) {
            return '';
        }
        try {
            $user = ObjectManager::getInstance(BackendUser::class)->clear()->load($backendUserId);
            if ($user->getId()) {
                return strtolower(trim((string)$user->getEmail()));
            }
        } catch (\Throwable $e) {
            w_log_warning('Inquiry reply backend user resolve failed: ' . $e->getMessage(), [], 'inquiry_mail_reply');
        }

        return '';
    }

    /**
     * @return array{local:bool,mailbox:?array<string,mixed>}
     */
    private function resolveLocalMailboxByEmail(string $email): array
    {
        if ($email === '') {
            return ['local' => false, 'mailbox' => null];
        }
        try {
            $result = \w_query('mail', 'resolveLocalMailboxByEmail', ['email' => $email]);
            if (!is_array($result)) {
                return ['local' => false, 'mailbox' => null];
            }

            return [
                'local' => !empty($result['local']),
                'mailbox' => is_array($result['mailbox'] ?? null) ? $result['mailbox'] : null,
            ];
        } catch (\Throwable $e) {
            w_log_warning('Inquiry reply mailbox lookup failed: ' . $e->getMessage(), [], 'inquiry_mail_reply');
            return ['local' => false, 'mailbox' => null];
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function listLocalMailboxes(): array
    {
        try {
            $result = \w_query('mail', 'listLocalMailboxes', ['limit' => 100]);
            if (!is_array($result) || !is_array($result['items'] ?? null)) {
                return [];
            }
            $items = [];
            foreach ($result['items'] as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }

            return $items;
        } catch (\Throwable $e) {
            w_log_warning('Inquiry reply mailbox list failed: ' . $e->getMessage(), [], 'inquiry_mail_reply');
            return [];
        }
    }
}
