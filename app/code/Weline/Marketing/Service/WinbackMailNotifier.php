<?php

declare(strict_types=1);

namespace Weline\Marketing\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Website;

/**
 * 挽回邮件薄封装：经 smtp.send channel+vars 发出（不依赖 Order Model）。
 * 邮件语言优先订单 scope 快照 locale，再回退网站默认语言。
 */
class WinbackMailNotifier
{
    public const CHANNEL_UNPAID_ORDER_REMINDER = 'Weline_Marketing::unpaid_order_reminder';

    /**
     * @param array<string, mixed> $orderDto order_signals DTO
     * @param array<string, mixed> $extraVars
     * @return array{success:bool,message:string,skipped?:bool}
     */
    public function notifyUnpaidReminder(array $orderDto, array $extraVars = []): array
    {
        $email = trim((string)($orderDto['email'] ?? $orderDto['customer_email'] ?? ''));
        if ($email === '') {
            return ['success' => false, 'message' => 'no_customer_email', 'skipped' => true];
        }
        if (!\function_exists('w_query')) {
            return ['success' => false, 'message' => 'smtp_unavailable', 'skipped' => true];
        }

        $scopeCtx = $this->resolveScopeLocale(
            (int)($orderDto['website_id'] ?? 0),
            (string)($orderDto['locale'] ?? ''),
        );
        $vars = \array_merge([
            'order_uuid' => (string)($orderDto['order_uuid'] ?? ''),
            'order_number' => (string)($orderDto['order_number'] ?? ''),
            'customer_name' => (string)($orderDto['customer_name'] ?? ''),
            'customer_email' => $email,
            'grand_total' => (string)($orderDto['grand_total'] ?? ''),
            'grand_total_minor' => (string)($orderDto['grand_total_minor'] ?? ''),
            'currency' => (string)($orderDto['currency'] ?? ''),
            'continue_pay_url' => (string)($orderDto['continue_pay_url'] ?? ''),
            'reachable' => !empty($orderDto['reachable']) ? '1' : '0',
            'payment_status' => (string)($orderDto['payment_status'] ?? ''),
            'checkout_entry' => (string)($orderDto['checkout_entry'] ?? ''),
            'created_at' => (string)($orderDto['created_at'] ?? ''),
            'locale' => $scopeCtx['locale'],
        ], $extraVars);

        try {
            $result = w_query('smtp', 'send', [
                'module' => 'Weline_Marketing',
                'channel' => self::CHANNEL_UNPAID_ORDER_REMINDER,
                'to' => $email,
                'vars' => $vars,
                'website_code' => $scopeCtx['website_code'],
                'scope' => $scopeCtx['storage_scope'],
                'locale' => $scopeCtx['locale'],
            ]);
            if (\is_array($result) && !empty($result['success'])) {
                return ['success' => true, 'message' => ''];
            }

            return [
                'success' => false,
                'message' => \is_array($result) ? (string)($result['message'] ?? 'send_failed') : 'send_failed',
            ];
        } catch (\Throwable $e) {
            if (\function_exists('w_log_error')) {
                w_log_error('WinbackMailNotifier failed: ' . $e->getMessage(), [], 'marketing_winback');
            }

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{website_code:string,storage_scope:string,locale:string}
     */
    public function resolveScopeLocale(int $websiteId, string $snapshotLocale = ''): array
    {
        $websiteCode = 'default';
        $storageScope = 'default.default.default';
        $locale = 'zh_Hans_CN';

        if ($websiteId > 0) {
            try {
                /** @var Website $website */
                $website = ObjectManager::getInstance(Website::class);
                $website->load($websiteId);
                if ($website->getId()) {
                    $code = \strtolower(\trim((string)$website->getCode()));
                    if ($code !== '') {
                        $websiteCode = $code;
                        $storageScope = $code . '.default.default';
                    }
                    $lang = \trim((string)($website->getDefaultLanguage() ?? ''));
                    if ($lang !== '') {
                        $locale = $lang;
                    }
                }
            } catch (\Throwable) {
            }
        }

        $snap = \trim($snapshotLocale);
        if ($snap !== '' && \strtolower($snap) !== 'default') {
            $locale = $snap;
        }

        return [
            'website_code' => $websiteCode,
            'storage_scope' => $storageScope,
            'locale' => $locale,
        ];
    }
}
