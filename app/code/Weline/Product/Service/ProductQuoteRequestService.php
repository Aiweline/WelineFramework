<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\SessionFactory;
use Weline\Product\Api\ProductQuoteRequestSubmitInterface;
use Weline\Product\Exception\ProductQuoteRequestStateTransitionException;
use Weline\Product\Model\ProductQuoteRequest;

/**
 * Validates and persists Product-owned storefront quote requests.
 */
final class ProductQuoteRequestService implements ProductQuoteRequestSubmitInterface
{
    public function __construct(
        private readonly ProductQuoteRequest $quoteRequest,
        private readonly ProductQuoteRequestCaptchaGuard $captchaGuard,
        private readonly StorefrontCatalogViewService $catalog,
        private readonly ProductCurrentCustomerResolver $currentCustomer,
        private readonly ProductQuoteRequestStateMachine $stateMachine,
        private readonly EventsManager $eventsManager,
        private readonly ?SessionFactory $sessions = null,
    ) {
    }

    public function submit(array $payload): array
    {
        // Honeypot: bots fill company_website.
        if (trim((string)($payload['company_website'] ?? '')) !== '') {
            return [
                'accepted' => true,
                'duplicate' => false,
                'quote_request_id' => 0,
                'message' => (string)__('提交成功，我们会尽快联系您。'),
            ];
        }

        if (!$this->captchaGuard->verify($payload)) {
            throw new \InvalidArgumentException((string)__('人机验证失败或已过期，请重试'));
        }

        $idempotencyKey = trim((string)($payload['idempotency_key'] ?? ''));
        if (preg_match('/^[A-Za-z0-9._-]{16,128}$/', $idempotencyKey) !== 1) {
            throw new \InvalidArgumentException((string)__('无效的幂等提交键'));
        }

        $existing = $this->quoteRequest->reset()
            ->where(ProductQuoteRequest::schema_fields_IDEMPOTENCY_KEY, $idempotencyKey)
            ->select()
            ->fetchArray();
        if ($existing !== []) {
            return [
                'accepted' => true,
                'duplicate' => true,
                'quote_request_id' => (int)($existing[0][ProductQuoteRequest::schema_fields_ID] ?? 0),
                'message' => (string)__('提交成功，我们会尽快联系您。'),
            ];
        }

        $clean = $this->normalize($payload);
        $this->assertQuoteOnlyOffer($clean);

        $customerId = $this->currentCustomer->currentCustomerId();
        $now = date('Y-m-d H:i:s');
        $rowData = [
            ProductQuoteRequest::schema_fields_PRODUCT_ID => $clean['product_id'],
            ProductQuoteRequest::schema_fields_SKU => $clean['sku'],
            ProductQuoteRequest::schema_fields_GLOBAL_OFFER_UUID => $clean['global_offer_uuid'],
            ProductQuoteRequest::schema_fields_SELECTION_JSON => $clean['selection_json'],
            ProductQuoteRequest::schema_fields_REFERENCE_PRICE_MINOR => $clean['reference_price_minor'],
            ProductQuoteRequest::schema_fields_CURRENCY => $clean['currency'],
            ProductQuoteRequest::schema_fields_CAMPAIGN_LABEL => $clean['campaign_label'],
            ProductQuoteRequest::schema_fields_PRODUCT_URL => $clean['product_url'],
            ProductQuoteRequest::schema_fields_CONTACT_NAME => $clean['contact_name'],
            ProductQuoteRequest::schema_fields_EMAIL => $clean['email'],
            ProductQuoteRequest::schema_fields_PHONE => $clean['phone'],
            ProductQuoteRequest::schema_fields_QUANTITY => $clean['quantity'],
            ProductQuoteRequest::schema_fields_MESSAGE => $clean['message'],
            ProductQuoteRequest::schema_fields_ADDRESS_JSON => $clean['address_json'],
            ProductQuoteRequest::schema_fields_STATUS => ProductQuoteRequest::STATUS_NEW,
            ProductQuoteRequest::schema_fields_LOCALE => $clean['locale'],
            ProductQuoteRequest::schema_fields_IDEMPOTENCY_KEY => $idempotencyKey,
            ProductQuoteRequest::schema_fields_SOURCE_FINGERPRINT => $this->sourceFingerprint(),
            ProductQuoteRequest::schema_fields_CREATED_AT => $now,
            ProductQuoteRequest::schema_fields_UPDATED_AT => $now,
        ];
        if ($customerId > 0) {
            $rowData[ProductQuoteRequest::schema_fields_CUSTOMER_ID] = $customerId;
        }
        $row = $this->quoteRequest->clear()->setData($rowData);
        $row->save();

        $quoteRequestId = (int)$row->getId();
        $this->eventsManager->dispatch('Weline_Product::quote_request_submitted', [
            'quote_request_id' => $quoteRequestId,
            'quote_request' => $row,
            'status' => ProductQuoteRequest::STATUS_NEW,
            'customer_id' => $customerId > 0 ? $customerId : null,
        ]);

        return [
            'accepted' => true,
            'duplicate' => false,
            'quote_request_id' => $quoteRequestId,
            'message' => (string)__('提交成功，我们会尽快联系您。'),
        ];
    }

    /**
     * @param array{status?:string,limit?:int,offset?:int} $filters
     * @return list<array<string, mixed>>
     */
    public function listForAdmin(array $filters = []): array
    {
        $status = strtolower(trim((string)($filters['status'] ?? '')));
        $limit = max(1, min(200, (int)($filters['limit'] ?? 50)));
        $offset = max(0, (int)($filters['offset'] ?? 0));

        $query = $this->quoteRequest->reset();
        if (in_array($status, ProductQuoteRequest::STATUSES, true)) {
            $query->where(ProductQuoteRequest::schema_fields_STATUS, $status);
        }
        $rows = $query
            ->order(ProductQuoteRequest::schema_fields_CREATED_AT, 'DESC')
            ->limit($limit, $offset)
            ->select()
            ->fetchArray();

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result[] = $this->mapAdminRow($row);
        }

        return $result;
    }

    public function markProcessed(int $quoteRequestId): bool
    {
        try {
            $this->transitionStatus($quoteRequestId, ProductQuoteRequest::STATUS_PROCESSED, 'marked_processed');

            return true;
        } catch (ProductQuoteRequestStateTransitionException) {
            return false;
        }
    }

    /**
     * @return array{quote_request_id:int,old_status:string,new_status:string,admin_reply_at:?string}
     */
    public function transitionStatus(int $quoteRequestId, string $newStatus, ?string $comment = null): array
    {
        return $this->stateMachine->transition($quoteRequestId, $newStatus, $comment);
    }

    /**
     * @return list<string>
     */
    public function availableTransitions(string $currentStatus): array
    {
        return $this->stateMachine->getAvailableTransitions($currentStatus);
    }

    /**
     * Claim orphan guest quotes matching the customer email, then list for account center.
     *
     * @return list<array<string, mixed>>
     */
    public function listForCustomer(int $customerId, int $limit = 50): array
    {
        $customerId = max(0, $customerId);
        if ($customerId <= 0) {
            return [];
        }
        $this->claimOrphanQuotesForCustomer($customerId);

        $limit = max(1, min(100, $limit));
        $rows = $this->quoteRequest->reset()
            ->where(ProductQuoteRequest::schema_fields_CUSTOMER_ID, $customerId)
            ->order(ProductQuoteRequest::schema_fields_CREATED_AT, 'DESC')
            ->limit($limit)
            ->select()
            ->fetchArray();

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result[] = $this->mapCustomerRow($row);
        }

        return $result;
    }

    public function countUnreadForCustomer(int $customerId): int
    {
        $customerId = max(0, $customerId);
        if ($customerId <= 0) {
            return 0;
        }
        $this->claimOrphanQuotesForCustomer($customerId);

        $rows = $this->quoteRequest->reset()
            ->where(ProductQuoteRequest::schema_fields_CUSTOMER_ID, $customerId)
            ->where(ProductQuoteRequest::schema_fields_ADMIN_REPLY_AT, null, 'is not null')
            ->select()
            ->fetchArray();

        $unread = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $replyAt = trim((string)($row[ProductQuoteRequest::schema_fields_ADMIN_REPLY_AT] ?? ''));
            if ($replyAt === '') {
                continue;
            }
            $seenAt = trim((string)($row[ProductQuoteRequest::schema_fields_CUSTOMER_LAST_SEEN_AT] ?? ''));
            if ($seenAt === '' || strcmp($replyAt, $seenAt) > 0) {
                ++$unread;
            }
        }

        return $unread;
    }

    public function markSeenForCustomer(int $customerId): int
    {
        $customerId = max(0, $customerId);
        if ($customerId <= 0) {
            return 0;
        }
        $this->claimOrphanQuotesForCustomer($customerId);

        $now = date('Y-m-d H:i:s');
        $rows = $this->quoteRequest->reset()
            ->where(ProductQuoteRequest::schema_fields_CUSTOMER_ID, $customerId)
            ->select()
            ->fetchArray();

        $updated = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int)($row[ProductQuoteRequest::schema_fields_ID] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $model = $this->quoteRequest->clear()->load($id);
            if (!(int)$model->getId()) {
                continue;
            }
            $model->setData(ProductQuoteRequest::schema_fields_CUSTOMER_LAST_SEEN_AT, $now);
            $model->setData(ProductQuoteRequest::schema_fields_UPDATED_AT, $now);
            $model->save();
            ++$updated;
        }

        return $updated;
    }

    /**
     * Silently attach guest quotes that share the customer's email.
     */
    public function claimOrphanQuotesForCustomer(int $customerId): int
    {
        $customerId = max(0, $customerId);
        if ($customerId <= 0) {
            return 0;
        }
        $email = $this->resolveCustomerEmail($customerId);
        if ($email === '') {
            return 0;
        }

        $rows = $this->quoteRequest->reset()
            ->where(ProductQuoteRequest::schema_fields_EMAIL, $email)
            ->where(ProductQuoteRequest::schema_fields_CUSTOMER_ID, null, 'is null')
            ->select()
            ->fetchArray();

        $claimed = 0;
        $now = date('Y-m-d H:i:s');
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int)($row[ProductQuoteRequest::schema_fields_ID] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $model = $this->quoteRequest->clear()->load($id);
            if (!(int)$model->getId()) {
                continue;
            }
            $existing = (int)($model->getData(ProductQuoteRequest::schema_fields_CUSTOMER_ID) ?? 0);
            if ($existing > 0) {
                continue;
            }
            $model->setData(ProductQuoteRequest::schema_fields_CUSTOMER_ID, $customerId);
            $model->setData(ProductQuoteRequest::schema_fields_UPDATED_AT, $now);
            $model->save();
            ++$claimed;
        }

        return $claimed;
    }

    private function resolveCustomerEmail(int $customerId): string
    {
        $facadeClass = 'Weline\\Customer\\Api\\Auth\\CustomerAccountFacadeInterface';
        try {
            if (interface_exists($facadeClass)) {
                $accounts = ObjectManager::getInstance($facadeClass);
                if (is_object($accounts) && method_exists($accounts, 'find')) {
                    $identity = $accounts->find($customerId);
                    if (is_object($identity) && method_exists($identity, 'getEmail')) {
                        $email = strtolower(trim((string)$identity->getEmail()));
                        if ($email !== '') {
                            return $email;
                        }
                    }
                }
            }
        } catch (\Throwable) {
        }

        try {
            $session = ($this->sessions ?? SessionFactory::getInstance())->createFrontendSession();
            $user = $session->getUser();
            $customerClass = 'Weline\\Customer\\Model\\Customer';
            if (is_object($user)
                && class_exists($customerClass)
                && $user instanceof $customerClass
                && (int)$user->getId() === $customerId
                && method_exists($user, 'getEmail')
            ) {
                return strtolower(trim((string)$user->getEmail()));
            }
        } catch (\Throwable) {
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapCustomerRow(array $row): array
    {
        $replyAt = trim((string)($row[ProductQuoteRequest::schema_fields_ADMIN_REPLY_AT] ?? ''));
        $seenAt = trim((string)($row[ProductQuoteRequest::schema_fields_CUSTOMER_LAST_SEEN_AT] ?? ''));
        $unread = $replyAt !== '' && ($seenAt === '' || strcmp($replyAt, $seenAt) > 0);

        return [
            'quote_request_id' => (int)($row[ProductQuoteRequest::schema_fields_ID] ?? 0),
            'product_id' => (int)($row[ProductQuoteRequest::schema_fields_PRODUCT_ID] ?? 0),
            'sku' => (string)($row[ProductQuoteRequest::schema_fields_SKU] ?? ''),
            'reference_price_minor' => (int)($row[ProductQuoteRequest::schema_fields_REFERENCE_PRICE_MINOR] ?? 0),
            'currency' => (string)($row[ProductQuoteRequest::schema_fields_CURRENCY] ?? 'CNY'),
            'campaign_label' => (string)($row[ProductQuoteRequest::schema_fields_CAMPAIGN_LABEL] ?? ''),
            'product_url' => (string)($row[ProductQuoteRequest::schema_fields_PRODUCT_URL] ?? ''),
            'quantity' => (int)($row[ProductQuoteRequest::schema_fields_QUANTITY] ?? 1),
            'status' => (string)($row[ProductQuoteRequest::schema_fields_STATUS] ?? ProductQuoteRequest::STATUS_NEW),
            'admin_reply_at' => $replyAt,
            'customer_last_seen_at' => $seenAt,
            'unread' => $unread,
            'created_at' => (string)($row[ProductQuoteRequest::schema_fields_CREATED_AT] ?? ''),
            'updated_at' => (string)($row[ProductQuoteRequest::schema_fields_UPDATED_AT] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *     product_id:int,
     *     sku:string,
     *     global_offer_uuid:string,
     *     selection_json:string,
     *     reference_price_minor:int,
     *     currency:string,
     *     campaign_label:string,
     *     product_url:string,
     *     contact_name:string,
     *     email:string,
     *     phone:string,
     *     quantity:int,
     *     message:string,
     *     locale:string
     * }
     */
    private function normalize(array $payload): array
    {
        $contactName = mb_substr(trim((string)($payload['contact_name'] ?? '')), 0, 128);
        $email = mb_substr(strtolower(trim((string)($payload['email'] ?? ''))), 0, 255);
        $phone = mb_substr(trim((string)($payload['phone'] ?? '')), 0, 64);
        if ($contactName === '') {
            throw new \InvalidArgumentException((string)__('请填写联系人姓名'));
        }
        if ($email === '' && $phone === '') {
            throw new \InvalidArgumentException((string)__('请填写邮箱或手机号'));
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException((string)__('邮箱格式不正确'));
        }

        $sku = mb_substr(trim((string)($payload['sku'] ?? '')), 0, 128);
        $productId = max(0, (int)($payload['product_id'] ?? 0));
        $offerUuid = mb_substr(trim((string)($payload['global_offer_uuid'] ?? '')), 0, 36);
        if ($productId <= 0 || $sku === '') {
            throw new \InvalidArgumentException((string)__('缺少商品规格信息，请刷新后重试'));
        }

        $selection = $payload['selection'] ?? [];
        if (!is_array($selection)) {
            $selection = [];
        }
        $normalizedSelection = [];
        foreach ($selection as $key => $value) {
            $code = trim((string)$key);
            $token = trim((string)$value);
            if ($code === '' || $token === '') {
                continue;
            }
            $normalizedSelection[$code] = $token;
        }

        $quantity = max(1, min(9999, (int)($payload['quantity'] ?? 1)));
        $message = mb_substr(trim((string)($payload['message'] ?? '')), 0, 2000);
        $currency = strtoupper(trim((string)($payload['currency'] ?? 'CNY'))) ?: 'CNY';
        $currency = mb_substr($currency, 0, 8);
        $campaignLabel = mb_substr(trim((string)($payload['campaign_label'] ?? '')), 0, 128);
        $productUrl = mb_substr(trim((string)($payload['product_url'] ?? '')), 0, 512);
        $locale = mb_substr(trim((string)($payload['locale'] ?? '')), 0, 32);
        $referencePriceMinor = max(0, (int)($payload['reference_price_minor'] ?? 0));

        $addressJson = $this->normalizeAddressJson($payload['address'] ?? ($payload['address_json'] ?? null));

        return [
            'product_id' => $productId,
            'sku' => $sku,
            'global_offer_uuid' => $offerUuid,
            'selection_json' => json_encode($normalizedSelection, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'reference_price_minor' => $referencePriceMinor,
            'currency' => $currency,
            'campaign_label' => $campaignLabel,
            'product_url' => $productUrl,
            'contact_name' => $contactName,
            'email' => $email,
            'phone' => $phone,
            'quantity' => $quantity,
            'message' => $message,
            'address_json' => $addressJson,
            'locale' => $locale,
        ];
    }

    /**
     * @param mixed $raw
     */
    private function normalizeAddressJson(mixed $raw): string
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return '';
        }
        $keys = [
            'postal_code', 'country_code', 'country', 'province', 'city', 'district', 'street', 'address1',
            'province_code', 'city_code', 'district_code',
        ];
        $out = [];
        foreach ($keys as $key) {
            $value = trim((string)($raw[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $out[$key] = mb_substr($value, 0, $key === 'address1' ? 512 : 128);
        }
        if ($out === []) {
            return '';
        }

        return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * @param array{product_id:int,sku:string,global_offer_uuid:string} $clean
     */
    private function assertQuoteOnlyOffer(array $clean): void
    {
        // Use per-product live projection (all offers). Catalog cache is representative-only
        // and would miss non-default SKUs for multi-offer quote_only products.
        $offers = $this->catalog->publishedOffersForProduct($clean['product_id']);
        $matched = null;
        foreach ($offers as $offer) {
            if (!is_array($offer)) {
                continue;
            }
            if ((int)($offer['product_id'] ?? 0) !== $clean['product_id']) {
                continue;
            }
            $sku = trim((string)($offer['sku'] ?? ''));
            $uuid = trim((string)($offer['global_offer_uuid'] ?? ''));
            if ($clean['global_offer_uuid'] !== '' && $uuid === $clean['global_offer_uuid']) {
                $matched = $offer;
                break;
            }
            if ($sku !== '' && strcasecmp($sku, $clean['sku']) === 0) {
                $matched = $offer;
                break;
            }
        }

        if (!is_array($matched)) {
            throw new \InvalidArgumentException((string)__('商品规格无效或已下架'));
        }

        $isQuoteOnly = !empty($matched['quote_only'])
            || str_contains((string)($matched['message'] ?? ''), '仅询价');
        if (!$isQuoteOnly) {
            throw new \InvalidArgumentException((string)__('该规格可直接购买，无需提交询价'));
        }
    }

    private function sourceFingerprint(): string
    {
        try {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $ip = trim((string)$request->clientIP());
        } catch (\Throwable) {
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        }

        return $ip === '' ? '' : hash('sha256', $ip);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapAdminRow(array $row): array
    {
        $selection = [];
        $raw = (string)($row[ProductQuoteRequest::schema_fields_SELECTION_JSON] ?? '');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $selection = $decoded;
            }
        }

        $status = (string)($row[ProductQuoteRequest::schema_fields_STATUS] ?? ProductQuoteRequest::STATUS_NEW);

        return [
            'quote_request_id' => (int)($row[ProductQuoteRequest::schema_fields_ID] ?? 0),
            'product_id' => (int)($row[ProductQuoteRequest::schema_fields_PRODUCT_ID] ?? 0),
            'sku' => (string)($row[ProductQuoteRequest::schema_fields_SKU] ?? ''),
            'global_offer_uuid' => (string)($row[ProductQuoteRequest::schema_fields_GLOBAL_OFFER_UUID] ?? ''),
            'selection' => $selection,
            'reference_price_minor' => (int)($row[ProductQuoteRequest::schema_fields_REFERENCE_PRICE_MINOR] ?? 0),
            'currency' => (string)($row[ProductQuoteRequest::schema_fields_CURRENCY] ?? 'CNY'),
            'campaign_label' => (string)($row[ProductQuoteRequest::schema_fields_CAMPAIGN_LABEL] ?? ''),
            'product_url' => (string)($row[ProductQuoteRequest::schema_fields_PRODUCT_URL] ?? ''),
            'contact_name' => (string)($row[ProductQuoteRequest::schema_fields_CONTACT_NAME] ?? ''),
            'email' => (string)($row[ProductQuoteRequest::schema_fields_EMAIL] ?? ''),
            'phone' => (string)($row[ProductQuoteRequest::schema_fields_PHONE] ?? ''),
            'quantity' => (int)($row[ProductQuoteRequest::schema_fields_QUANTITY] ?? 1),
            'message' => (string)($row[ProductQuoteRequest::schema_fields_MESSAGE] ?? ''),
            'address_json' => (string)($row[ProductQuoteRequest::schema_fields_ADDRESS_JSON] ?? ''),
            'address' => $this->decodeAddressJson((string)($row[ProductQuoteRequest::schema_fields_ADDRESS_JSON] ?? '')),
            'status' => $status,
            'available_transitions' => $this->stateMachine->getAvailableTransitions($status),
            'customer_id' => isset($row[ProductQuoteRequest::schema_fields_CUSTOMER_ID])
                ? (int)$row[ProductQuoteRequest::schema_fields_CUSTOMER_ID]
                : null,
            'admin_reply_at' => (string)($row[ProductQuoteRequest::schema_fields_ADMIN_REPLY_AT] ?? ''),
            'locale' => (string)($row[ProductQuoteRequest::schema_fields_LOCALE] ?? ''),
            'created_at' => (string)($row[ProductQuoteRequest::schema_fields_CREATED_AT] ?? ''),
            'updated_at' => (string)($row[ProductQuoteRequest::schema_fields_UPDATED_AT] ?? ''),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function decodeAddressJson(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $key => $value) {
            $k = trim((string)$key);
            $v = trim((string)$value);
            if ($k === '' || $v === '') {
                continue;
            }
            $out[$k] = $v;
        }

        return $out;
    }
}
