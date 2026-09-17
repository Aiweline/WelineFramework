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
    public const CHANNEL_CHECKOUT_ABANDON_REMINDER = 'Weline_Marketing::checkout_abandon_reminder';
    public const CHANNEL_CART_ABANDON_REMINDER = 'Weline_Marketing::cart_abandon_reminder';

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
        $currency = (string)($orderDto['currency'] ?? '');
        $itemsHtml = $this->itemsHtml(
            \is_array($orderDto['line_items'] ?? null) ? $orderDto['line_items'] : [],
            $currency,
            $scopeCtx['storage_scope'],
        );
        $vars = \array_merge([
            'order_uuid' => (string)($orderDto['order_uuid'] ?? ''),
            'order_number' => (string)($orderDto['order_number'] ?? ''),
            'customer_name' => (string)($orderDto['customer_name'] ?? ''),
            'customer_email' => $email,
            'grand_total' => (string)($orderDto['grand_total'] ?? ''),
            'grand_total_minor' => (string)($orderDto['grand_total_minor'] ?? ''),
            'currency' => $currency,
            'continue_pay_url' => (string)($orderDto['continue_pay_url'] ?? ''),
            'reachable' => !empty($orderDto['reachable']) ? '1' : '0',
            'payment_status' => (string)($orderDto['payment_status'] ?? ''),
            'checkout_entry' => (string)($orderDto['checkout_entry'] ?? ''),
            'created_at' => $this->resolveCreatedAtVar($orderDto),
            'locale' => $scopeCtx['locale'],
            'items_html' => $itemsHtml,
            'coupon_code' => (string)($orderDto['coupon_code'] ?? $extraVars['coupon_code'] ?? ''),
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
     * @param array<string, mixed> $dto checkout_signals stale quote DTO
     * @param array<string, mixed> $extraVars
     * @return array{success:bool,message:string,skipped?:bool}
     */
    public function notifyCheckoutAbandon(array $dto, array $extraVars = []): array
    {
        $email = trim((string)($dto['email'] ?? $dto['customer_email'] ?? ''));
        if ($email === '') {
            return ['success' => false, 'message' => 'no_customer_email', 'skipped' => true];
        }
        if (!\function_exists('w_query')) {
            return ['success' => false, 'message' => 'smtp_unavailable', 'skipped' => true];
        }

        $scopeCtx = $this->resolveScopeLocale(
            (int)($dto['website_id'] ?? 0),
            (string)($dto['locale'] ?? ''),
        );
        $currency = (string)($dto['currency'] ?? '');
        $itemsHtml = $this->itemsHtml(
            \is_array($dto['line_items'] ?? null) ? $dto['line_items'] : [],
            $currency,
            $scopeCtx['storage_scope'],
        );
        $vars = \array_merge([
            'quote_token' => (string)($dto['quote_token'] ?? ''),
            'customer_name' => (string)($dto['customer_name'] ?? ''),
            'customer_email' => $email,
            'email' => $email,
            'grand_total' => (string)($dto['grand_total'] ?? ''),
            'grand_total_minor' => (string)($dto['grand_total_minor'] ?? ''),
            'currency' => $currency,
            'continue_checkout_url' => (string)($dto['continue_checkout_url'] ?? ''),
            'created_at' => $this->resolveCreatedAtVar($dto),
            'checkout_entry' => (string)($dto['checkout_entry'] ?? ''),
            'locale' => $scopeCtx['locale'],
            'website_id' => (string)($dto['website_id'] ?? ''),
            'items_html' => $itemsHtml,
            'coupon_code' => (string)($dto['coupon_code'] ?? ''),
        ], $extraVars);

        try {
            $result = w_query('smtp', 'send', [
                'module' => 'Weline_Marketing',
                'channel' => self::CHANNEL_CHECKOUT_ABANDON_REMINDER,
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
                w_log_error('WinbackMailNotifier checkout abandon failed: ' . $e->getMessage(), [], 'marketing_winback');
            }

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $dto cart_signals stale cart DTO
     * @param array<string, mixed> $extraVars
     * @return array{success:bool,message:string,skipped?:bool}
     */
    public function notifyCartAbandon(array $dto, array $extraVars = []): array
    {
        $email = trim((string)($dto['email'] ?? $dto['customer_email'] ?? ''));
        if ($email === '') {
            return ['success' => false, 'message' => 'no_customer_email', 'skipped' => true];
        }
        if (!\function_exists('w_query')) {
            return ['success' => false, 'message' => 'smtp_unavailable', 'skipped' => true];
        }

        $scopeCtx = $this->resolveScopeLocale(
            (int)($dto['website_id'] ?? 0),
            (string)($dto['locale'] ?? ''),
        );
        $currency = (string)($dto['currency'] ?? '');
        $totals = \is_array($dto['totals'] ?? null) ? $dto['totals'] : [];
        $grandTotal = (string)($dto['grand_total'] ?? $totals['grand_total'] ?? '');
        $grandTotalMinor = (string)($dto['grand_total_minor'] ?? $totals['grand_total_minor'] ?? '');
        $itemsHtml = $this->itemsHtml(
            \is_array($dto['line_items'] ?? null) ? $dto['line_items'] : [],
            $currency,
            $scopeCtx['storage_scope'],
        );
        $vars = \array_merge([
            'cart_key' => (string)($dto['cart_key'] ?? ''),
            'customer_name' => (string)($dto['customer_name'] ?? ''),
            'customer_email' => $email,
            'email' => $email,
            'grand_total' => $grandTotal,
            'grand_total_minor' => $grandTotalMinor,
            'currency' => $currency,
            'continue_cart_url' => (string)($dto['continue_cart_url'] ?? ''),
            'created_at' => $this->resolveCreatedAtVar($dto),
            'updated_at' => \trim((string)($dto['updated_at'] ?? '')),
            'locale' => $scopeCtx['locale'],
            'website_id' => (string)($dto['website_id'] ?? ''),
            'items_html' => $itemsHtml,
            'coupon_code' => (string)($dto['coupon_code'] ?? ''),
        ], $extraVars);

        try {
            $result = w_query('smtp', 'send', [
                'module' => 'Weline_Marketing',
                'channel' => self::CHANNEL_CART_ABANDON_REMINDER,
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
                w_log_error('WinbackMailNotifier cart abandon failed: ' . $e->getMessage(), [], 'marketing_winback');
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

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function itemsHtml(array $lines, string $currency, string $storageScope = 'default.default.default'): string
    {
        try {
            /** @var WinbackMailItemsHtmlBuilder $builder */
            $builder = ObjectManager::getInstance(WinbackMailItemsHtmlBuilder::class);
        } catch (\Throwable) {
            $builder = new WinbackMailItemsHtmlBuilder();
        }
        $baseUrl = $this->resolveMailAssetBaseUrl($storageScope);

        return $builder->render($lines, $currency, $baseUrl);
    }

    /**
     * 邮件商品图 Origin：须邮件客户端可达。拒绝 e2e / *.weline.test，回落 default 站公网 Host。
     */
    private function resolveMailAssetBaseUrl(string $storageScope): string
    {
        $candidates = [];
        try {
            if (\class_exists(\Weline\Smtp\Service\MailBrandContextService::class)) {
                /** @var \Weline\Smtp\Service\MailBrandContextService $brand */
                $brand = ObjectManager::getInstance(\Weline\Smtp\Service\MailBrandContextService::class);
                $ctx = $brand->resolve($storageScope !== '' ? $storageScope : 'default.default.default');
                $candidates[] = \trim((string)($ctx['site_url'] ?? ''));
                // 渠道 scope 若落在 e2e 站，再解一次 default 站品牌址
                if ($storageScope !== '' && !\str_starts_with($storageScope, 'default.')) {
                    $fallbackCtx = $brand->resolve('default.default.default');
                    $candidates[] = \trim((string)($fallbackCtx['site_url'] ?? ''));
                }
            }
        } catch (\Throwable) {
        }
        try {
            if (\class_exists(Website::class)) {
                /** @var Website $website */
                $website = ObjectManager::getInstance(Website::class);
                $row = $website->clear()->where(Website::schema_fields_CODE, 'default')->find()->fetch();
                if ($row) {
                    $candidates[] = \trim((string)$row->getData(Website::schema_fields_URL));
                }
            }
        } catch (\Throwable) {
        }

        foreach ($candidates as $url) {
            $url = \rtrim($url, '/');
            if ($url !== '' && !$this->isUndeliverableMailAssetHost($url)) {
                return $url;
            }
        }

        return \rtrim((string)($candidates[0] ?? ''), '/');
    }

    private function isUndeliverableMailAssetHost(string $url): bool
    {
        $host = \strtolower((string)(\parse_url($url, \PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return true;
        }
        if (\str_starts_with($host, 'e2e-') || \str_contains($host, 'e2e-test') || \str_contains($host, 'e2e_')) {
            return true;
        }

        return \str_ends_with($host, '.weline.test');
    }

    /**
     * @param array<string, mixed> $dto
     */
    private function resolveCreatedAtVar(array $dto): string
    {
        $raw = \trim((string)($dto['created_at'] ?? ''));
        if ($raw === '') {
            $raw = \trim((string)($dto['create_time'] ?? $dto['placed_at'] ?? ''));
        }
        if ($raw === '' || \str_starts_with($raw, '0000-00-00')) {
            return '';
        }
        if (\preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', $raw, $m) === 1) {
            return $m[1] . ' ' . $m[2];
        }

        return $raw;
    }
}
