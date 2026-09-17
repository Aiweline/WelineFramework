<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;
use Weline\Websites\Model\Website;

/**
 * 订单邮件薄服务：从订单归属站显式推导 scope/locale，经 smtp.send channel+vars 发出。
 */
class OrderMailNotifier
{
    public const CHANNEL_CREATED = 'Weline_Order::order_created';
    public const CHANNEL_PAID = 'Weline_Order::order_paid';
    public const CHANNEL_STATUS_CHANGED = 'Weline_Order::order_status_changed';
    public const CHANNEL_SHIPPED = 'Weline_Order::order_shipped';
    public const CHANNEL_REFUND = 'Weline_Order::order_refund';

    /**
     * @param array<string, mixed> $extraVars
     * @return array{success:bool,message:string,skipped?:bool}
     */
    public function notify(Order $order, string $channel, array $extraVars = []): array
    {
        $email = trim((string)$order->getData(Order::schema_fields_CUSTOMER_EMAIL));
        if ($email === '') {
            w_log_warning('OrderMailNotifier skip: empty customer_email', [
                'order_id' => (int)$order->getId(),
                'channel' => $channel,
            ], 'order_mail');

            return ['success' => false, 'message' => 'no_customer_email', 'skipped' => true];
        }
        if (!function_exists('w_query')) {
            w_log_warning('OrderMailNotifier skip: smtp unavailable', [
                'order_id' => (int)$order->getId(),
                'channel' => $channel,
            ], 'order_mail');

            return ['success' => false, 'message' => 'smtp_unavailable', 'skipped' => true];
        }

        $scopeCtx = $this->resolveScopeLocale($order);
        $currency = strtoupper(trim((string)$order->getData(Order::schema_fields_CURRENCY))) ?: 'CNY';
        $itemsHtml = array_key_exists('items_html', $extraVars)
            ? (string)$extraVars['items_html']
            : $this->buildItemsHtml($order, $currency, $scopeCtx['storage_scope']);
        $vars = array_merge([
            'order_uuid' => (string)$order->getData(Order::schema_fields_ORDER_UUID),
            'order_id' => (string)$order->getId(),
            'order_number' => (string)$order->getData(Order::schema_fields_ORDER_NUMBER),
            'customer_name' => (string)$order->getData(Order::schema_fields_CUSTOMER_NAME),
            'customer_email' => $email,
            'status' => (string)$order->getData(Order::schema_fields_STATUS),
            'payment_status' => (string)$order->getData(Order::schema_fields_PAYMENT_STATUS),
            'fulfillment_status' => (string)$order->getData(Order::schema_fields_FULFILLMENT_STATUS),
            'grand_total' => $this->formatGrandTotalDisplay(
                (string)$order->getData(Order::schema_fields_GRAND_TOTAL),
                $currency,
            ),
            'currency' => $currency,
            'items_html' => $itemsHtml,
            'message' => (string)($extraVars['message'] ?? ''),
            'comment' => (string)($extraVars['comment'] ?? ''),
            'old_status' => (string)($extraVars['old_status'] ?? ''),
        ], $extraVars);
        // Bare numeric grand_total from extraVars still needs currency display.
        if ($this->isBareMoneyAmount((string)($vars['grand_total'] ?? ''))) {
            $vars['grand_total'] = $this->formatGrandTotalDisplay(
                (string)$vars['grand_total'],
                (string)($vars['currency'] ?? $currency),
            );
        }
        $vars = $this->localizeStatusVars($vars, $scopeCtx['locale']);

        try {
            $result = w_query('smtp', 'send', [
                'module' => 'Weline_Order',
                'channel' => $channel,
                'to' => $email,
                'vars' => $vars,
                'website_code' => $scopeCtx['website_code'],
                'scope' => $scopeCtx['storage_scope'],
                'locale' => $scopeCtx['locale'],
            ]);
            if (is_array($result) && !empty($result['success'])) {
                return ['success' => true, 'message' => ''];
            }

            return [
                'success' => false,
                'message' => is_array($result) ? (string)($result['message'] ?? 'send_failed') : 'send_failed',
            ];
        } catch (\Throwable $e) {
            w_log_error('OrderMailNotifier failed: ' . $e->getMessage(), [], 'order_mail');

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * status_changed：尊重 notify_customer；发货/退款状态映射到专用渠道。
     *
     * @param array<string, mixed> $extraVars
     */
    public function notifyStatusChanged(
        Order $order,
        string $oldStatus,
        string $newStatus,
        bool $notifyCustomer,
        array $extraVars = [],
    ): array {
        if (!$notifyCustomer) {
            return ['success' => false, 'message' => 'notify_customer_false', 'skipped' => true];
        }

        $mapped = $this->mapStatusToChannel($newStatus);
        $vars = array_merge([
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
        ], $extraVars);

        return $this->notify($order, $mapped, $vars);
    }

    public function mapStatusToChannel(string $status): string
    {
        $status = strtolower(trim($status));
        return match ($status) {
            Order::STATUS_FULFILLED,
            Order::FULFILLMENT_STATUS_SHIPPED,
            'shipped' => self::CHANNEL_SHIPPED,
            Order::STATUS_REFUNDED,
            Order::PAYMENT_STATUS_REFUNDED,
            'refund',
            'refunded' => self::CHANNEL_REFUND,
            default => self::CHANNEL_STATUS_CHANGED,
        };
    }

    /**
     * @return array{website_code:string,storage_scope:string,locale:string}
     */
    public function resolveScopeLocale(Order $order): array
    {
        $websiteCode = 'default';
        $storageScope = 'default.default.default';
        $locale = 'zh_Hans_CN';

        try {
            /** @var OrderObjectScopeService $scopeService */
            $scopeService = ObjectManager::getInstance(OrderObjectScopeService::class);
            $identity = $scopeService->fromOrder($order);
            $websiteCode = strtolower(trim((string)$identity->websiteCode)) ?: 'default';
            if ($identity->storeCode) {
                $storageScope = $websiteCode . '.' . strtolower((string)$identity->storeCode) . '.default';
            } else {
                $storageScope = $websiteCode . '.default.default';
            }
        } catch (\Throwable) {
            $websiteId = (int)$order->getData(Order::schema_fields_WEBSITE_ID);
            if ($websiteId > 0) {
                try {
                    /** @var Website $website */
                    $website = ObjectManager::getInstance(Website::class);
                    $website->load($websiteId);
                    $code = strtolower(trim((string)$website->getCode()));
                    if ($code !== '') {
                        $websiteCode = $code;
                        $storageScope = $code . '.default.default';
                    }
                } catch (\Throwable) {
                }
            }
        }

        try {
            /** @var Website $website */
            $website = ObjectManager::getInstance(Website::class);
            $row = $website->clear()->where(Website::schema_fields_CODE, $websiteCode)->find()->fetch();
            if ($row && $row->getId()) {
                $lang = trim((string)($row->getDefaultLanguage() ?? ''));
                if ($lang !== '') {
                    $locale = $lang;
                }
            }
        } catch (\Throwable) {
        }

        $snapshot = json_decode((string)$order->getData(Order::schema_fields_SCOPE_SNAPSHOT_JSON), true);
        if (is_array($snapshot)) {
            $snapLocale = trim((string)($snapshot['locale'] ?? $snapshot['language'] ?? ''));
            if ($snapLocale !== '' && $snapLocale !== 'default') {
                $locale = $snapLocale;
            }
        }

        return [
            'website_code' => $websiteCode,
            'storage_scope' => $storageScope,
            'locale' => $locale,
        ];
    }

    /**
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    private function localizeStatusVars(array $vars, string $locale): array
    {
        $apply = static function () use ($vars): array {
            foreach (['status', 'old_status', 'new_status'] as $key) {
                $raw = trim((string)($vars[$key] ?? ''));
                if ($raw === '') {
                    continue;
                }
                $vars[$key] = Order::getStatusLabel($raw);
            }
            $pay = trim((string)($vars['payment_status'] ?? ''));
            if ($pay !== '') {
                $vars['payment_status'] = Order::getPaymentStatusLabel($pay);
            }
            $ful = trim((string)($vars['fulfillment_status'] ?? ''));
            if ($ful !== '') {
                $vars['fulfillment_status'] = Order::getFulfillmentStatusLabel($ful);
            }

            return $vars;
        };

        try {
            if (class_exists(\Weline\Smtp\Service\MailTemplateShellComposer::class)) {
                /** @var \Weline\Smtp\Service\MailTemplateShellComposer $shell */
                $shell = ObjectManager::getInstance(\Weline\Smtp\Service\MailTemplateShellComposer::class);

                return $shell->withMailLocaleEnvironment($locale !== '' ? $locale : 'zh_Hans_CN', $apply);
            }
        } catch (\Throwable) {
        }

        return $apply();
    }

    private function formatGrandTotalDisplay(string $rawAmount, string $currency): string
    {
        $currency = strtoupper(trim($currency)) ?: 'CNY';
        $raw = trim($rawAmount);
        if ($raw === '') {
            return '';
        }
        if (!$this->isBareMoneyAmount($raw)) {
            return $raw;
        }
        $amount = (float)$raw;
        // 订单总额固定 2 位小数；符号优先 Currency 定义，避免裸数。
        $number = number_format($amount, 2, '.', '');
        $symbol = $this->resolveCurrencySymbol($currency);
        if ($symbol !== '') {
            return $symbol . $number;
        }

        return $currency . ' ' . $number;
    }

    private function resolveCurrencySymbol(string $currency): string
    {
        try {
            if (class_exists(\Weline\Currency\Model\Currency::class)) {
                /** @var \Weline\Currency\Model\Currency $model */
                $model = ObjectManager::getInstance(\Weline\Currency\Model\Currency::class);
                $row = $model->clear()
                    ->where(\Weline\Currency\Model\Currency::schema_fields_CODE, $currency)
                    ->find()
                    ->fetch();
                if ($row && $row->getId()) {
                    $symbol = trim((string)$row->getSymbol());
                    if ($symbol !== '') {
                        // 邮件展示统一半角 ¥，避免与商品行混用全角 ￥。
                        return $symbol === '￥' ? '¥' : $symbol;
                    }
                }
            }
        } catch (\Throwable) {
        }

        return match ($currency) {
            'CNY', 'RMB' => '¥',
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'JPY' => '¥',
            default => '',
        };
    }

    private function isBareMoneyAmount(string $raw): bool
    {
        $raw = trim($raw);

        return $raw !== '' && preg_match('/^-?\d+(\.\d+)?$/', $raw) === 1;
    }

    private function buildItemsHtml(Order $order, string $currency, string $storageScope): string
    {
        try {
            /** @var UnpaidOrderMailLineProjector $projector */
            $projector = ObjectManager::getInstance(UnpaidOrderMailLineProjector::class);
        } catch (\Throwable) {
            $projector = new UnpaidOrderMailLineProjector();
        }
        try {
            /** @var OrderMailItemsHtmlBuilder $builder */
            $builder = ObjectManager::getInstance(OrderMailItemsHtmlBuilder::class);
        } catch (\Throwable) {
            $builder = new OrderMailItemsHtmlBuilder();
        }

        return $builder->render($projector->project($order), $currency, $this->resolveMailAssetBaseUrl($storageScope));
    }

    /**
     * 邮件商品图 Origin：须邮件客户端可达。拒绝 e2e / *.weline.test，回落 default 站公网 Host。
     */
    private function resolveMailAssetBaseUrl(string $storageScope): string
    {
        $candidates = [];
        try {
            if (class_exists(\Weline\Smtp\Service\MailBrandContextService::class)) {
                /** @var \Weline\Smtp\Service\MailBrandContextService $brand */
                $brand = ObjectManager::getInstance(\Weline\Smtp\Service\MailBrandContextService::class);
                $ctx = $brand->resolve($storageScope !== '' ? $storageScope : 'default.default.default');
                $candidates[] = trim((string)($ctx['site_url'] ?? ''));
                if ($storageScope !== '' && !str_starts_with($storageScope, 'default.')) {
                    $fallbackCtx = $brand->resolve('default.default.default');
                    $candidates[] = trim((string)($fallbackCtx['site_url'] ?? ''));
                }
            }
        } catch (\Throwable) {
        }
        try {
            if (class_exists(Website::class)) {
                /** @var Website $website */
                $website = ObjectManager::getInstance(Website::class);
                $row = $website->clear()->where(Website::schema_fields_CODE, 'default')->find()->fetch();
                if ($row) {
                    $candidates[] = trim((string)$row->getData(Website::schema_fields_URL));
                }
            }
        } catch (\Throwable) {
        }

        foreach ($candidates as $url) {
            $url = rtrim($url, '/');
            if ($url !== '' && !$this->isUndeliverableMailAssetHost($url)) {
                return $url;
            }
        }

        return rtrim((string)($candidates[0] ?? ''), '/');
    }

    private function isUndeliverableMailAssetHost(string $url): bool
    {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return true;
        }
        if (str_starts_with($host, 'e2e-') || str_contains($host, 'e2e-test') || str_contains($host, 'e2e_')) {
            return true;
        }

        return str_ends_with($host, '.weline.test');
    }
}
