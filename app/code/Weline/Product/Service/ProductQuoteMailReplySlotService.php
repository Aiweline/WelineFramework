<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Acl\Api\Authorization\ResourceAuthorizationServiceInterface;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Registry\Service\RegistryModulePresence;

/**
 * Builds enterprise-mail reply slots for Product quote admin rows.
 * Opens global &lt;w:mail-composer/&gt; via WelineMailComposer.open (no GET body deeplink).
 */
final class ProductQuoteMailReplySlotService
{
    public const SOURCE = 'product_quote';

    /**
     * @param array<string,mixed> $quote Admin quote row from ProductQuoteRequestService::listForAdmin
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
    public function buildForQuote(array $quote, int $backendUserId): array
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

        $toEmail = strtolower(trim((string)($quote['email'] ?? '')));
        $quoteId = (int)($quote['quote_request_id'] ?? 0);
        if ($toEmail === '' || filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
            return [
                'enabled' => true,
                'mode' => 'unavailable',
                'message' => (string)__('该询价单无有效邮箱，无法邮件回复'),
                'mailboxes' => [],
                'default_account_id' => 0,
                'composer' => [],
                'mailto_url' => '',
            ];
        }

        $subject = $this->buildSubject($quote);
        $body = $this->buildBody($quote);
        $mailto = $this->buildMailto($toEmail, $subject, $body);

        $canPick = $this->canPickAnyLocalMailbox($backendUserId);
        $mailboxes = $this->listLocalMailboxes();
        $ownEmail = $this->resolveBackendUserEmail($backendUserId);
        $own = $this->resolveLocalMailboxByEmail($ownEmail);

        if ($canPick) {
            if ($mailboxes === []) {
                return [
                    'enabled' => true,
                    'mode' => 'external',
                    'message' => (string)__('尚未开通本机企业邮箱账号，请先在企业邮箱管理中创建测试或客服邮箱'),
                    'mailboxes' => [],
                    'default_account_id' => 0,
                    'composer' => [],
                    'mailto_url' => $mailto,
                ];
            }
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
                'message' => (string)__('可选择本机客服或测试邮箱代发'),
                'mailboxes' => $mailboxes,
                'default_account_id' => $defaultId,
                'composer' => $this->buildComposerPayload($toEmail, $subject, $body, $defaultId, $quoteId, $mailboxes),
                'mailto_url' => $mailto,
            ];
        }

        if (!empty($own['local']) && is_array($own['mailbox'] ?? null)) {
            $accountId = (int)($own['mailbox']['account_id'] ?? 0);
            $boxes = [$own['mailbox']];

            return [
                'enabled' => true,
                'mode' => 'own',
                'message' => (string)__('使用本人本机企业邮箱回复'),
                'mailboxes' => $boxes,
                'default_account_id' => $accountId,
                'composer' => $this->buildComposerPayload($toEmail, $subject, $body, $accountId, $quoteId, $boxes),
                'mailto_url' => $mailto,
            ];
        }

        return [
            'enabled' => true,
            'mode' => 'external',
            'message' => (string)__('当前账号邮箱不是本机企业邮箱，需外部发送'),
            'mailboxes' => [],
            'default_account_id' => 0,
            'composer' => [],
            'mailto_url' => $mailto,
        ];
    }

    /**
     * @param list<array<string,mixed>> $quotes
     * @return array<int, array<string,mixed>>
     */
    public function attachToQuotes(array $quotes, int $backendUserId): array
    {
        foreach ($quotes as &$quote) {
            if (!is_array($quote)) {
                continue;
            }
            $quote['mail_reply'] = $this->buildForQuote($quote, $backendUserId);
        }
        unset($quote);

        return $quotes;
    }

    /**
     * @param list<array<string,mixed>> $mailboxes
     * @return array<string,mixed>
     */
    public function buildComposerPayload(
        string $to,
        string $subject,
        string $body,
        int $accountId,
        int $quoteRequestId,
        array $mailboxes
    ): array {
        return [
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'account_id' => $accountId,
            'source' => self::SOURCE,
            'source_id' => $quoteRequestId,
            'mailboxes' => $mailboxes,
        ];
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
        } catch (\Throwable) {
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
        } catch (\Throwable) {
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
        } catch (\Throwable) {
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
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $quote
     */
    private function buildSubject(array $quote): string
    {
        $sku = trim((string)($quote['sku'] ?? ''));
        $id = (int)($quote['quote_request_id'] ?? 0);

        return (string)__('询价回复 #%{1} %{2}', [(string)$id, $sku !== '' ? $sku : '']);
    }

    /**
     * @param array<string,mixed> $quote
     */
    private function buildBody(array $quote): string
    {
        $sku = trim((string)($quote['sku'] ?? ''));
        $qty = (int)($quote['quantity'] ?? 1);
        $currency = strtoupper(trim((string)($quote['currency'] ?? 'CNY'))) ?: 'CNY';
        $minor = (int)($quote['reference_price_minor'] ?? 0);
        $price = number_format(max(0, $minor) / 100, 2, '.', '');
        $name = trim((string)($quote['contact_name'] ?? ''));
        $message = trim((string)($quote['message'] ?? ''));
        $lines = [
            (string)__('您好，关于您的商品询价：'),
            '',
            (string)__('SKU：%{1}', [$sku]),
            (string)__('数量：%{1}', [(string)$qty]),
            (string)__('参考价：%{1}', [$currency . ' ' . $price]),
        ];
        if ($name !== '') {
            $lines[] = (string)__('联系人：%{1}', [$name]);
        }
        if ($message !== '') {
            $lines[] = (string)__('客户留言：%{1}', [$message]);
        }
        $lines[] = '';
        $lines[] = (string)__('（请在此填写报价与说明）');

        return implode("\n", $lines);
    }

    private function buildMailto(string $to, string $subject, string $body): string
    {
        return 'mailto:' . $to
            . '?subject=' . rawurlencode($subject)
            . '&body=' . rawurlencode($body);
    }
}
