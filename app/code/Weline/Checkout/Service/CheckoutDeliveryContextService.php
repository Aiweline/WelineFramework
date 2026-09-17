<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Session\SessionInterface;
use Weline\I18n\Model\I18n;
use Weline\Shipping\Model\DeliveryAddress;
use Weline\Shipping\Service\AddressFormatter;
use Weline\Shipping\Service\AddressValidationService;
use Weline\Shipping\Service\DeliveryAddressService;
use Weline\Shipping\Service\EmbargoService;
use Weline\Framework\Runtime\RequestContext;

/**
 * Header 与结账页共用的配送上下文。Weline_Location 仅提供可选自动定位。
 */
final class CheckoutDeliveryContextService
{
    public const SESSION_COUNTRY = 'checkout_delivery_country_code';
    public const SESSION_SELECTED = 'checkout_selected_delivery';
    public const SESSION_GUEST_BOOK = 'checkout_guest_delivery_addresses';
    public const SESSION_SHIPPING = 'shipping_delivery_address';
    public const COOKIE_LOCATION = 'weline_delivery_location';

    public function __construct(
        private readonly SessionFactory $sessionFactory,
        private readonly DeliveryAddressService $deliveryAddressService,
        private readonly AddressFormatter $addressFormatter,
        private readonly AddressValidationService $addressValidationService,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getContext(array $params = []): array
    {
        try {
            $requestedCountry = self::normalizeCountryCode((string)($params['country_code'] ?? ''));
            if ($requestedCountry !== '') {
                $this->persistCountry($requestedCountry);
            }

            $countryCode = $this->currentCountryCode();
            $this->ensureRegionCascade($countryCode);
            $countries = $this->listCountries();
            $countryName = $this->countryName($countryCode, $countries);
            // 更换地址 picking：拉全量地址簿（含其它国家）；默认仍按当前配送国过滤。
            $listAll = $this->wantsAllAddresses($params);
            $purpose = $this->resolveAddressPurpose($params);
            $addresses = $this->annotateEmbargoList($this->listAddresses($listAll ? '' : $countryCode, $purpose));
            $selected = $this->selectedAddress($addresses, $countryCode, $countryName);
            if (\is_array($selected)) {
                $selected = $this->annotateEmbargoOne($selected);
            }

            return [
                'country_code' => $countryCode,
                'country_name' => $countryName,
                'countries' => $countries,
                'addresses' => $addresses,
                'selected' => $selected,
                'is_logged_in' => $this->currentCustomerId() > 0,
                'can_auto_detect' => $this->canAutoDetect(),
                'display_text' => $this->displayText($selected, $countryName),
                'destination_blocked' => \is_array($selected) && !empty($selected['embargo_blocked']),
                'destination_block_message' => \is_array($selected)
                    ? (string)($selected['embargo_message'] ?? '')
                    : '',
                'checkout_address' => self::toFormAddress($selected ?? [
                    'country_code' => $countryCode,
                    'country' => $countryName,
                ], $this->currentEmail()),
            ];
        } catch (\Throwable) {
            $countryCode = self::normalizeCountryCode((string)($params['country_code'] ?? 'CN')) ?: 'CN';

            return [
                'country_code' => $countryCode,
                'country_name' => $countryCode,
                'countries' => [],
                'addresses' => [],
                'selected' => null,
                'is_logged_in' => false,
                'can_auto_detect' => false,
                'display_text' => (string)__('选择国家/地址'),
                'checkout_address' => self::toFormAddress([
                    'country_code' => $countryCode,
                    'country' => $countryCode,
                ]),
            ];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function setCountry(array $params): array
    {
        $countryCode = self::normalizeCountryCode((string)($params['country_code'] ?? ''));
        if ($countryCode === '') {
            throw new \InvalidArgumentException((string)__('请选择国家/地区'));
        }

        $this->persistCountry($countryCode);
        $this->ensureRegionCascade($countryCode);
        $selected = $this->readSelected();
        if (is_array($selected) && self::normalizeCountryCode((string)($selected['country_code'] ?? '')) !== $countryCode) {
            $this->writeSelected(null);
        }

        return $this->getContext(['country_code' => $countryCode]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function selectAddress(array $params): array
    {
        $payload = is_array($params['address'] ?? null) ? $params['address'] : $params;
        $addressId = trim((string)($payload['id'] ?? $payload['address_id'] ?? $payload['delivery_address_id'] ?? ''));
        if ($addressId === '') {
            throw new \InvalidArgumentException((string)__('请选择配送地址'));
        }

        $match = null;
        $purpose = $this->resolveAddressPurpose($params);
        foreach ($this->listAddresses('', $purpose === 'receiving' ? 'receiving' : 'checkout') as $address) {
            if ((string)$address['id'] === $addressId) {
                $match = $address;
                break;
            }
        }
        // 跨用途兜底：双标/错端选择时仍可按 id 命中。
        if ($match === null) {
            foreach ($this->listAddresses('', 'any') as $address) {
                if ((string)$address['id'] === $addressId) {
                    $match = $address;
                    break;
                }
            }
        }
        if ($match === null) {
            throw new \InvalidArgumentException((string)__('地址不存在'));
        }

        $this->assertDestinationAllowed($match);

        $this->persistCountry((string)$match['country_code']);
        $this->writeSelected($match);
        $this->syncShippingSession($match);

        return $this->getContext([
            'country_code' => (string)$match['country_code'],
            'address_purpose' => $purpose === 'receiving' ? 'receiving' : 'checkout',
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function saveAddress(array $params): array
    {
        $payload = is_array($params['address'] ?? null) ? $params['address'] : $params;
        if (!is_array($payload)) {
            $payload = [];
        }

        $normalized = $this->normalizeIncoming($payload);
        $this->addressValidationService->validate($normalized, [
            'contact_name',
            'contact_phone',
            'street',
        ]);
        $this->assertDestinationAllowed($normalized);

        $purposeMeta = $this->extractPurposeMeta($params, $payload);
        $normalized = array_merge($normalized, $purposeMeta);

        $customerId = $this->currentCustomerId();
        $saved = $customerId > 0
            ? $this->saveCustomerAddress($customerId, $normalized)
            : $this->saveGuestAddress($normalized);

        $this->persistCountry((string)$saved['country_code']);
        $this->writeSelected($saved);
        $this->syncShippingSession($saved);

        $listPurpose = (($purposeMeta['purpose_source'] ?? 'checkout') === 'receiving')
            ? 'receiving'
            : 'checkout';

        return $this->getContext([
            'country_code' => (string)$saved['country_code'],
            'address_purpose' => $listPurpose,
        ]);
    }

    /**
     * 仅清空结账用途地址（purpose_checkout）；保留收货/运输用途地址。
     * 双标条目：摘结账标、保留收货标。仅结账条目：移出访客簿。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function clearDeliveryBook(array $params = []): array
    {
        $session = $this->session();
        $book = $this->guestBook();
        $kept = [];
        $selected = $this->readSelected();
        $selectedId = is_array($selected) ? (string)($selected['id'] ?? '') : '';
        $selectedWasCheckout = false;

        foreach ($book as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string)($row['id'] ?? ''));
            // 去掉误种的演示数据
            if (str_starts_with($id, 'guest_demo_')) {
                if ($id !== '' && $id === $selectedId) {
                    $selectedWasCheckout = true;
                }
                continue;
            }
            $checkout = array_key_exists('purpose_checkout', $row)
                ? DeliveryAddressService::isTruthy($row['purpose_checkout'])
                : true;
            $receiving = array_key_exists('purpose_receiving', $row)
                ? DeliveryAddressService::isTruthy($row['purpose_receiving'])
                : true;

            if ($id !== '' && $id === $selectedId && $checkout) {
                $selectedWasCheckout = true;
            }

            if (!$checkout) {
                // 本就不是结账地址：原样保留
                $kept[] = $row;
                continue;
            }

            if ($receiving) {
                // 双标：只摘结账标，保留收货/运输
                $row['purpose_checkout'] = 0;
                $row['purpose_receiving'] = 1;
                $row['is_selected'] = false;
                $kept[] = $row;
                continue;
            }

            // 仅结账：从簿中移除
        }

        $session->set(self::SESSION_GUEST_BOOK, array_values($kept));

        // 账户库：摘结账标（clear 原先只清访客簿，登录用户展开账单/结账列表仍见双标地址）。
        $customerId = $this->currentCustomerId();
        if ($customerId > 0) {
            $this->deliveryAddressService->stripCheckoutPurposeForCustomer($customerId);
        }

        // 若误清后收货侧为空：从本机备份恢复原「纽约测试地址」为仅收货（不进结账列表）。
        $hasReceiving = false;
        foreach ($kept as $row) {
            if (DeliveryAddressService::isTruthy($row['purpose_receiving'] ?? 1)) {
                $hasReceiving = true;
                break;
            }
        }
        if (!$hasReceiving) {
            $ny = $this->loadBackupReceivingGuestAddress();
            if (is_array($ny)) {
                $ny['purpose_checkout'] = 0;
                $ny['purpose_receiving'] = 1;
                $ny['is_selected'] = true;
                $kept[] = $ny;
                $session->set(self::SESSION_GUEST_BOOK, array_values($kept));
                $session->set(self::SESSION_SELECTED, $ny);
                $this->syncShippingSession($ny);
                $selectedWasCheckout = false;
            }
        }

        if ($selectedWasCheckout || $selectedId === '') {
            // 结账选中被清掉后：优先改选仍有的收货地址，否则清空选中
            $nextSelected = null;
            foreach ($kept as $row) {
                if (DeliveryAddressService::isTruthy($row['purpose_receiving'] ?? 1)) {
                    $nextSelected = $row;
                    break;
                }
            }
            if (is_array($nextSelected)) {
                $nextSelected['is_selected'] = true;
                $session->set(self::SESSION_SELECTED, $nextSelected);
                $this->syncShippingSession($nextSelected);
            } else {
                $session->set(self::SESSION_SELECTED, null);
                $session->set(self::SESSION_SHIPPING, null);
            }
        }

        $session->save();

        return $this->getContext([
            'address_purpose' => 'checkout',
            'list_all_addresses' => true,
        ]);
    }

    /**
     * 下单成功：把本单选中的配送地址转化为结账地址（只加 purpose_checkout）。
     * 点选收货簿地址不会在选址时打标；真下单后才进结账/账单簿。
     */
    public function promoteSelectedAddressToCheckout(): void
    {
        $selected = $this->readSelected();
        if (!is_array($selected)) {
            return;
        }
        $id = trim((string)($selected['id'] ?? ''));
        if ($id === '') {
            return;
        }

        $customerId = $this->currentCustomerId();
        if ($customerId > 0 && ctype_digit($id)) {
            $this->deliveryAddressService->ensureCheckoutPurposeForAddressId((int)$id, $customerId);
            return;
        }

        if (!str_starts_with($id, 'guest_')) {
            return;
        }

        $book = $this->guestBook();
        $changed = false;
        foreach ($book as $i => $row) {
            if (!is_array($row) || (string)($row['id'] ?? '') !== $id) {
                continue;
            }
            if (DeliveryAddressService::isTruthy($row['purpose_checkout'] ?? 0)) {
                break;
            }
            $row['purpose_checkout'] = 1;
            if (!array_key_exists('purpose_receiving', $row)) {
                $row['purpose_receiving'] = 1;
            }
            $book[$i] = $row;
            $selected['purpose_checkout'] = 1;
            $changed = true;
            break;
        }
        if (!$changed) {
            return;
        }
        $session = $this->session();
        $session->set(self::SESSION_GUEST_BOOK, array_values($book));
        $session->set(self::SESSION_SELECTED, $selected);
        $this->syncShippingSession($selected);
        $session->save();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadBackupReceivingGuestAddress(): ?array
    {
        $paths = [
            BP . 'var/session',
            '/tmp',
        ];
        foreach ($paths as $dir) {
            if ($dir === '/tmp') {
                $json = '/tmp/weline-restore-ny-guest.json';
                if (is_file($json)) {
                    $decoded = json_decode((string)file_get_contents($json), true);
                    if (is_array($decoded) && trim((string)($decoded['id'] ?? '')) !== '') {
                        return $decoded;
                    }
                }
                continue;
            }
            foreach (glob(rtrim($dir, '/') . '/*.bak-clear-*') ?: [] as $bak) {
                $data = @unserialize((string)file_get_contents($bak));
                if (!is_array($data)) {
                    continue;
                }
                $book = $data['checkout_guest_delivery_addresses'] ?? null;
                if (!is_array($book) || $book === []) {
                    continue;
                }
                $first = $book[0] ?? null;
                if (is_array($first) && trim((string)($first['id'] ?? '')) !== '') {
                    return $first;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, string|int>
     */
    public function checkoutFormAddress(): array
    {
        $selected = $this->readSelected();
        if (!is_array($selected) || $selected === []) {
            $countryCode = $this->currentCountryCode();
            if ($countryCode === '') {
                return [];
            }

            return self::toFormAddress([
                'country_code' => $countryCode,
                'country' => $this->countryName($countryCode, $this->listCountries()),
            ], $this->currentEmail());
        }

        return self::toFormAddress($selected, $this->currentEmail());
    }

    /**
     * @param array<string, mixed> $address
     * @return array<string, string>
     */
    public static function toFormAddress(array $address, string $email = ''): array
    {
        $countryCode = self::normalizeCountryCode((string)($address['country_code'] ?? $address['countryCode'] ?? ''));
        $street = trim((string)($address['street'] ?? $address['address1'] ?? ''));

        return [
            'address_id' => (string)($address['id'] ?? $address['delivery_address_id'] ?? $address['address_id'] ?? ''),
            'name' => trim((string)($address['contact_name'] ?? $address['name'] ?? '')),
            'phone' => trim((string)($address['contact_phone'] ?? $address['phone'] ?? '')),
            'email' => trim($email),
            'country_code' => $countryCode,
            'province' => trim((string)($address['province'] ?? '')),
            'province_code' => trim((string)($address['province_code'] ?? '')),
            'province_region_id' => (string)(int)($address['province_region_id'] ?? $address['province_id'] ?? 0),
            'city' => trim((string)($address['city'] ?? '')),
            'city_code' => trim((string)($address['city_code'] ?? '')),
            'city_region_id' => (string)(int)($address['city_region_id'] ?? $address['city_id'] ?? 0),
            'district' => trim((string)($address['district'] ?? '')),
            'district_code' => trim((string)($address['district_code'] ?? '')),
            'district_region_id' => (string)(int)($address['district_region_id'] ?? $address['district_id'] ?? 0),
            'address1' => $street,
            'street_id' => (string)(int)($address['street_id'] ?? 0),
            'postal_code' => trim((string)($address['postal_code'] ?? '')),
            'delivery_point_type' => strtolower(trim((string)($address['delivery_point_type']
                ?? $address['point_type']
                ?? 'residential'))) ?: 'residential',
        ];
    }

    /**
     * @param array<string, mixed> $address
     */
    private function assertDestinationAllowed(array $address): void
    {
        try {
            /** @var EmbargoService $embargo */
            $embargo = ObjectManager::getInstance(EmbargoService::class);
            $embargo->assertAllowed($address, [
                'website_id' => (int)RequestContext::getWelineWebsiteId(),
                'store_id' => (int)RequestContext::getWelineStoreId(),
                'channel_id' => (int)RequestContext::getWelineChannelId(),
            ]);
        } catch (\RuntimeException $exception) {
            throw new \InvalidArgumentException($exception->getMessage(), 0, $exception);
        } catch (\Throwable) {
            // Embargo service unavailable: fail closed for destination selection.
            throw new \InvalidArgumentException((string)__('当前地址暂不可配送，请更换收货地址。'));
        }
    }

    /**
     * @param list<array<string, mixed>> $addresses
     * @return list<array<string, mixed>>
     */
    private function annotateEmbargoList(array $addresses): array
    {
        $out = [];
        foreach ($addresses as $address) {
            if (!\is_array($address)) {
                continue;
            }
            $out[] = $this->annotateEmbargoOne($address);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $address
     * @return array<string, mixed>
     */
    private function annotateEmbargoOne(array $address): array
    {
        $fallback = (string)__('该地址所在地区暂不支持配送（禁运）。');
        try {
            /** @var EmbargoService $embargo */
            $embargo = ObjectManager::getInstance(EmbargoService::class);
            $result = $embargo->evaluateAddress($address, [
                'website_id' => (int)RequestContext::getWelineWebsiteId(),
                'store_id' => (int)RequestContext::getWelineStoreId(),
                'channel_id' => (int)RequestContext::getWelineChannelId(),
            ]);
            $blocked = !empty($result['blocked']);
            $message = trim((string)($result['message'] ?? ''));
            $address['embargo_blocked'] = $blocked;
            $address['embargo_message'] = $blocked ? ($message !== '' ? $message : $fallback) : '';
        } catch (\Throwable) {
            $address['embargo_blocked'] = false;
            $address['embargo_message'] = '';
        }

        return $address;
    }

    public static function normalizeCountryCode(string $code): string
    {
        return strtoupper(substr((string)preg_replace('/[^A-Za-z]/', '', $code), 0, 2));
    }

    /**
     * @return list<array{code:string,name:string}>
     */
    private function listCountries(): array
    {
        $names = [];
        try {
            /** @var I18n $i18n */
            $i18n = ObjectManager::getInstance(I18n::class);
            $locale = Cookie::getLangLocal() ?: 'zh_Hans_CN';
            $raw = $i18n->getCountries($locale);
            if (is_array($raw)) {
                $names = $raw;
            }
        } catch (\Throwable) {
            $names = [
                'CN' => 'China',
                'US' => 'United States',
                'GB' => 'United Kingdom',
                'JP' => 'Japan',
            ];
        }

        $list = [];
        foreach ($names as $code => $name) {
            $normalized = self::normalizeCountryCode((string)$code);
            if ($normalized === '') {
                continue;
            }
            $list[] = [
                'code' => $normalized,
                'name' => trim((string)$name) !== '' ? trim((string)$name) : $normalized,
            ];
        }
        usort($list, static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));

        return $list;
    }

    /**
     * @param list<array{code:string,name:string}> $countries
     */
    private function countryName(string $countryCode, array $countries): string
    {
        foreach ($countries as $country) {
            if ($country['code'] === $countryCode) {
                return $country['name'];
            }
        }

        return $countryCode !== '' ? $countryCode : (string)__('选择国家/地址');
    }

    /**
     * @param array<string, mixed> $params
     */
    private function wantsAllAddresses(array $params): bool
    {
        $raw = $params['list_all_addresses'] ?? $params['for_picker'] ?? null;
        if ($raw === true || $raw === 1 || $raw === '1' || $raw === 'true' || $raw === 'yes') {
            return true;
        }

        return false;
    }

    /**
     * checkout=结账地址；receiving=收货地址（Header/账户）；any=不过滤用途。
     *
     * @param array<string, mixed> $params
     */
    private function resolveAddressPurpose(array $params): string
    {
        $raw = strtolower(trim((string)($params['address_purpose'] ?? $params['purpose'] ?? '')));
        if (in_array($raw, ['checkout', 'receiving', 'any'], true)) {
            return $raw;
        }

        // Header「配送至」默认收货；结账页须显式传 address_purpose=checkout。
        return 'receiving';
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function extractPurposeMeta(array $params, array $payload): array
    {
        $source = strtolower(trim((string)(
            $params['purpose_source']
            ?? $payload['purpose_source']
            ?? ''
        )));
        if ($source === '') {
            $source = 'checkout';
        }

        $meta = ['purpose_source' => $source];
        if (array_key_exists('also_use_receiving', $params)) {
            $meta['also_use_receiving'] = $params['also_use_receiving'];
        } elseif (array_key_exists('also_use_receiving', $payload)) {
            $meta['also_use_receiving'] = $payload['also_use_receiving'];
        }
        if (array_key_exists('also_use_checkout', $params)) {
            $meta['also_use_checkout'] = $params['also_use_checkout'];
        } elseif (array_key_exists('also_use_checkout', $payload)) {
            $meta['also_use_checkout'] = $payload['also_use_checkout'];
        }

        return $meta;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listAddresses(string $countryCode, string $purpose = 'receiving'): array
    {
        $selected = $this->readSelected();
        $selectedId = is_array($selected) ? (string)($selected['id'] ?? '') : '';
        $items = [];

        $customerId = $this->currentCustomerId();
        if ($customerId > 0) {
            try {
                $filters = ['is_enabled' => 1];
                if ($purpose === 'checkout') {
                    $filters['purpose_checkout'] = 1;
                } elseif ($purpose === 'receiving') {
                    $filters['purpose_receiving'] = 1;
                }
                foreach ($this->deliveryAddressService->getListByCustomer($customerId, $filters) as $model) {
                    $row = $model instanceof DeliveryAddress ? $model->getData() : (array)$model;
                    $projected = $this->projectAddress(
                        $row,
                        'customer',
                        false,
                        (string)($row[DeliveryAddress::schema_fields_ID] ?? $row['id'] ?? '') === $selectedId,
                    );
                    if ($countryCode !== '' && $projected['country_code'] !== $countryCode) {
                        continue;
                    }
                    $items[] = $projected;
                }
            } catch (\Throwable) {
                // 客户地址读取失败时降级为空列表，避免 header 面板 500。
            }
        }

        foreach ($this->guestBook() as $row) {
            if (!$this->guestMatchesPurpose($row, $purpose)) {
                continue;
            }
            $projected = $this->projectAddress(
                $row,
                'guest',
                true,
                (string)($row['id'] ?? '') === $selectedId,
            );
            if ($countryCode !== '' && $projected['country_code'] !== $countryCode) {
                continue;
            }
            $items[] = $projected;
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function guestMatchesPurpose(array $row, string $purpose): bool
    {
        if ($purpose === 'any') {
            return true;
        }
        // 旧 session 行无用途字段：视为双标，避免突然消失。
        $checkout = array_key_exists('purpose_checkout', $row)
            ? DeliveryAddressService::isTruthy($row['purpose_checkout'])
            : true;
        $receiving = array_key_exists('purpose_receiving', $row)
            ? DeliveryAddressService::isTruthy($row['purpose_receiving'])
            : true;
        if ($purpose === 'checkout') {
            return $checkout;
        }
        if ($purpose === 'receiving') {
            return $receiving;
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $addresses
     * @return array<string, mixed>|null
     */
    private function selectedAddress(array $addresses, string $countryCode, string $countryName): ?array
    {
        foreach ($addresses as $address) {
            if (!empty($address['is_selected'])) {
                return $address;
            }
        }

        $stored = $this->readSelected();
        if (is_array($stored) && self::normalizeCountryCode((string)($stored['country_code'] ?? '')) === $countryCode) {
            return $this->projectAddress($stored, (string)($stored['source'] ?? 'guest'), !empty($stored['is_anonymous']), true);
        }

        if ($countryCode === '') {
            return null;
        }

        return [
            'id' => '',
            'source' => 'country',
            'is_anonymous' => $this->currentCustomerId() <= 0,
            'name' => '',
            'phone' => '',
            'country_code' => $countryCode,
            'country' => $countryName,
            'province' => '',
            'city' => '',
            'district' => '',
            'street' => '',
            'postal_code' => '',
            'full_address' => $countryName,
            'is_selected' => false,
            'display_label' => $countryName,
        ];
    }

    /**
     * @param array<string, mixed>|null $selected
     */
    private function displayText(?array $selected, string $countryName): string
    {
        if (!is_array($selected)) {
            return $countryName !== '' ? $countryName : (string)__('选择国家/地址');
        }

        $city = trim((string)($selected['city'] ?? ''));
        if ($city !== '') {
            return $city;
        }
        $full = trim((string)($selected['full_address'] ?? ''));
        if ($full !== '') {
            return $full;
        }

        return $countryName !== '' ? $countryName : (string)__('选择国家/地址');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function projectAddress(array $row, string $source, bool $anonymous, bool $selected): array
    {
        $normalized = $this->addressFormatter->normalize([
            'country' => (string)($row['country'] ?? ''),
            'country_code' => (string)($row['country_code'] ?? $row['countryCode'] ?? ''),
            'province' => (string)($row['province'] ?? ''),
            'province_code' => (string)($row['province_code'] ?? ''),
            'province_region_id' => (int)($row['province_region_id'] ?? 0),
            'city' => (string)($row['city'] ?? ''),
            'city_code' => (string)($row['city_code'] ?? ''),
            'city_region_id' => (int)($row['city_region_id'] ?? 0),
            'district' => (string)($row['district'] ?? ''),
            'district_code' => (string)($row['district_code'] ?? ''),
            'district_region_id' => (int)($row['district_region_id'] ?? 0),
            'street' => (string)($row['street'] ?? $row['address1'] ?? ''),
            'street_id' => (int)($row['street_id'] ?? 0),
            'postal_code' => (string)($row['postal_code'] ?? ''),
            'contact_name' => (string)($row['contact_name'] ?? $row['name'] ?? ''),
            'contact_phone' => (string)($row['contact_phone'] ?? $row['phone'] ?? ''),
        ], true);
        // Prefer PK field: empty string "id" must not mask delivery_address_id (?? only skips null).
        $id = trim((string)($row[DeliveryAddress::schema_fields_ID] ?? $row['delivery_address_id'] ?? ''));
        if ($id === '') {
            $id = trim((string)($row['id'] ?? $row['address_id'] ?? ''));
        }
        $full = $this->addressFormatter->formatSingleLine($normalized);
        $name = trim((string)$normalized['contact_name']);

        return [
            'id' => $id,
            'source' => $source,
            'is_anonymous' => $anonymous,
            'name' => $name,
            'contact_name' => $name,
            'phone' => trim((string)$normalized['contact_phone']),
            'contact_phone' => trim((string)$normalized['contact_phone']),
            'country_code' => self::normalizeCountryCode((string)$normalized['country_code']),
            'country' => (string)$normalized['country'],
            'province' => (string)$normalized['province'],
            'province_code' => (string)($normalized['province_code'] ?? ''),
            'province_region_id' => (int)($normalized['province_region_id'] ?? 0),
            'city' => (string)$normalized['city'],
            'city_code' => (string)($normalized['city_code'] ?? ''),
            'city_region_id' => (int)($normalized['city_region_id'] ?? 0),
            'district' => (string)$normalized['district'],
            'district_code' => (string)($normalized['district_code'] ?? ''),
            'district_region_id' => (int)($normalized['district_region_id'] ?? 0),
            'street' => (string)$normalized['street'],
            'street_id' => (int)($normalized['street_id'] ?? 0),
            'postal_code' => (string)$normalized['postal_code'],
            'full_address' => $full,
            'is_selected' => $selected,
            'display_label' => $name !== '' ? $name . ' · ' . $full : $full,
            'purpose_checkout' => array_key_exists('purpose_checkout', $row)
                || array_key_exists(DeliveryAddress::schema_fields_PURPOSE_CHECKOUT, $row)
                ? (DeliveryAddressService::isTruthy(
                    $row[DeliveryAddress::schema_fields_PURPOSE_CHECKOUT] ?? $row['purpose_checkout'] ?? 0
                ) ? 1 : 0)
                : 1,
            'purpose_receiving' => array_key_exists('purpose_receiving', $row)
                || array_key_exists(DeliveryAddress::schema_fields_PURPOSE_RECEIVING, $row)
                ? (DeliveryAddressService::isTruthy(
                    $row[DeliveryAddress::schema_fields_PURPOSE_RECEIVING] ?? $row['purpose_receiving'] ?? 0
                ) ? 1 : 0)
                : 1,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeIncoming(array $payload): array
    {
        $countryCode = self::normalizeCountryCode((string)($payload['country_code'] ?? $payload['countryCode'] ?? $this->currentCountryCode()));
        $contactName = trim((string)($payload['contact_name'] ?? $payload['name'] ?? ''));

        return $this->addressFormatter->normalize([
            'name' => trim((string)($payload['label'] ?? $payload['address_name'] ?? $contactName)),
            'contact_name' => $contactName,
            'contact_phone' => trim((string)($payload['contact_phone'] ?? $payload['phone'] ?? '')),
            'country_code' => $countryCode,
            'country' => trim((string)($payload['country'] ?? $this->countryName($countryCode, $this->listCountries()))),
            'province' => trim((string)($payload['province'] ?? '')),
            'city' => trim((string)($payload['city'] ?? '')),
            'district' => trim((string)($payload['district'] ?? '')),
            'street' => trim((string)($payload['street'] ?? $payload['address1'] ?? '')),
            'postal_code' => trim((string)($payload['postal_code'] ?? '')),
            'is_default' => !empty($payload['is_default']),
            'id' => $payload['id'] ?? $payload['delivery_address_id'] ?? '',
        ]);
    }

    /**
     * @param array<string, mixed> $normalized
     * @return array<string, mixed>
     */
    private function saveCustomerAddress(int $customerId, array $normalized): array
    {
        $data = [
            DeliveryAddress::schema_fields_NAME => (string)($normalized['name'] ?: $normalized['contact_name']),
            DeliveryAddress::schema_fields_CONTACT_NAME => (string)$normalized['contact_name'],
            DeliveryAddress::schema_fields_CONTACT_PHONE => (string)$normalized['contact_phone'],
            DeliveryAddress::schema_fields_COUNTRY => (string)$normalized['country'],
            DeliveryAddress::schema_fields_COUNTRY_CODE => (string)$normalized['country_code'],
            DeliveryAddress::schema_fields_PROVINCE => (string)$normalized['province'],
            DeliveryAddress::schema_fields_CITY => (string)$normalized['city'],
            DeliveryAddress::schema_fields_DISTRICT => (string)$normalized['district'],
            DeliveryAddress::schema_fields_STREET => (string)$normalized['street'],
            DeliveryAddress::schema_fields_POSTAL_CODE => (string)$normalized['postal_code'],
            DeliveryAddress::schema_fields_IS_DEFAULT => !empty($normalized['is_default']) ? 1 : 0,
            DeliveryAddress::schema_fields_IS_ENABLED => 1,
            'purpose_source' => (string)($normalized['purpose_source'] ?? 'checkout'),
        ];
        if (array_key_exists('also_use_receiving', $normalized)) {
            $data['also_use_receiving'] = $normalized['also_use_receiving'];
        }
        if (array_key_exists('also_use_checkout', $normalized)) {
            $data['also_use_checkout'] = $normalized['also_use_checkout'];
        }
        if (array_key_exists(DeliveryAddress::schema_fields_PURPOSE_CHECKOUT, $normalized)) {
            $data[DeliveryAddress::schema_fields_PURPOSE_CHECKOUT] = $normalized[DeliveryAddress::schema_fields_PURPOSE_CHECKOUT];
        }
        if (array_key_exists(DeliveryAddress::schema_fields_PURPOSE_RECEIVING, $normalized)) {
            $data[DeliveryAddress::schema_fields_PURPOSE_RECEIVING] = $normalized[DeliveryAddress::schema_fields_PURPOSE_RECEIVING];
        }

        $id = (int)($normalized['id'] ?? $normalized['delivery_address_id'] ?? 0);
        $model = $id > 0
            ? $this->deliveryAddressService->update($id, $data, $customerId)
            : $this->deliveryAddressService->create($customerId, $data);

        $row = $model->getData();
        $row['id'] = (string)$model->getId();

        return $this->projectAddress($row, 'customer', false, true);
    }

    /**
     * @param array<string, mixed> $normalized
     * @return array<string, mixed>
     */
    private function saveGuestAddress(array $normalized): array
    {
        $book = $this->guestBook();
        $id = trim((string)($normalized['id'] ?? ''));
        if ($id === '' || !str_starts_with($id, 'guest_')) {
            $id = 'guest_' . bin2hex(random_bytes(8));
        }

        // array + keeps left-hand keys: normalizeIncoming may leave id='', which would
        // wipe the generated guest_* id and then guestBook() drops empty-id rows.
        $isCreate = true;
        foreach ($book as $existing) {
            if ((string)($existing['id'] ?? '') === $id) {
                $isCreate = false;
                break;
            }
        }
        $purposeFlags = DeliveryAddressService::resolvePurposeWriteFlags($normalized, $isCreate, null);
        if (!$isCreate) {
            foreach ($book as $existing) {
                if ((string)($existing['id'] ?? '') !== $id) {
                    continue;
                }
                $purposeFlags[DeliveryAddress::schema_fields_PURPOSE_CHECKOUT] = max(
                    (int)$purposeFlags[DeliveryAddress::schema_fields_PURPOSE_CHECKOUT],
                    DeliveryAddressService::isTruthy($existing['purpose_checkout'] ?? 1) ? 1 : 0,
                );
                $purposeFlags[DeliveryAddress::schema_fields_PURPOSE_RECEIVING] = max(
                    (int)$purposeFlags[DeliveryAddress::schema_fields_PURPOSE_RECEIVING],
                    DeliveryAddressService::isTruthy($existing['purpose_receiving'] ?? 1) ? 1 : 0,
                );
                break;
            }
        }

        $projected = $this->projectAddress(
            array_merge($normalized, $purposeFlags, [
                'id' => $id,
                'source' => 'guest',
                'is_anonymous' => true,
            ]),
            'guest',
            true,
            true
        );
        $replaced = false;
        foreach ($book as $index => $existing) {
            if ((string)($existing['id'] ?? '') === $id) {
                $book[$index] = $projected;
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            $book[] = $projected;
        }

        $session = $this->session();
        $session->set(self::SESSION_GUEST_BOOK, array_values($book));
        $session->save();

        return $projected;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function guestBook(): array
    {
        $book = $this->session()->get(self::SESSION_GUEST_BOOK);
        if (!is_array($book)) {
            return [];
        }

        $items = [];
        foreach ($book as $row) {
            if (is_array($row) && trim((string)($row['id'] ?? '')) !== '') {
                $items[] = $row;
            }
        }

        return $items;
    }

    private function currentCountryCode(): string
    {
        $fromSession = self::normalizeCountryCode((string)$this->session()->get(self::SESSION_COUNTRY));
        if ($fromSession !== '') {
            return $fromSession;
        }

        $selected = $this->readSelected();
        if (is_array($selected)) {
            $fromSelected = self::normalizeCountryCode((string)($selected['country_code'] ?? ''));
            if ($fromSelected !== '') {
                return $fromSelected;
            }
        }

        $shipping = $this->session()->get(self::SESSION_SHIPPING);
        if (is_array($shipping)) {
            $fromShipping = self::normalizeCountryCode((string)($shipping['country_code'] ?? $shipping['countryCode'] ?? ''));
            if ($fromShipping !== '') {
                return $fromShipping;
            }
        }

        $cookie = Cookie::get(self::COOKIE_LOCATION);
        if (is_string($cookie) && $cookie !== '') {
            $decoded = json_decode($cookie, true);
            if (is_array($decoded)) {
                $fromCookie = self::normalizeCountryCode((string)($decoded['country_code'] ?? $decoded['countryCode'] ?? ''));
                if ($fromCookie !== '') {
                    return $fromCookie;
                }
            }
        }

        return 'CN';
    }

    private function persistCountry(string $countryCode): void
    {
        $session = $this->session();
        $session->set(self::SESSION_COUNTRY, $countryCode);
        $session->save();
    }

    private function ensureRegionCascade(string $countryCode): void
    {
        $countryCode = self::normalizeCountryCode($countryCode);
        if ($countryCode === '') {
            return;
        }
        try {
            /** @var \Weline\Shipping\Service\RegionService $regionService */
            $regionService = ObjectManager::getInstance(\Weline\Shipping\Service\RegionService::class);
            $regionService->ensureCountryCascade($countryCode);
        } catch (\Throwable) {
            // 级联补齐失败不影响配送上下文主流程；前端仍可手填。
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readSelected(): ?array
    {
        $selected = $this->session()->get(self::SESSION_SELECTED);

        return is_array($selected) && $selected !== [] ? $selected : null;
    }

    /**
     * @param array<string, mixed>|null $address
     */
    private function writeSelected(?array $address): void
    {
        $session = $this->session();
        $session->set(self::SESSION_SELECTED, $address);
        $session->save();
    }

    /**
     * @param array<string, mixed> $address
     */
    private function syncShippingSession(array $address): void
    {
        $shipping = [
            'country' => (string)($address['country'] ?? ''),
            'country_code' => (string)($address['country_code'] ?? ''),
            'province' => (string)($address['province'] ?? ''),
            'city' => (string)($address['city'] ?? ''),
            'district' => (string)($address['district'] ?? ''),
            'street' => (string)($address['street'] ?? ''),
            'postal_code' => (string)($address['postal_code'] ?? ''),
            'contact_name' => (string)($address['contact_name'] ?? $address['name'] ?? ''),
            'contact_phone' => (string)($address['contact_phone'] ?? $address['phone'] ?? ''),
            'full_address' => (string)($address['full_address'] ?? ''),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $session = $this->session();
        $session->set(self::SESSION_SHIPPING, $shipping);
        $session->save();

        try {
            Cookie::set(self::COOKIE_LOCATION, json_encode([
                'country' => $shipping['country'],
                'countryCode' => $shipping['country_code'],
                'country_code' => $shipping['country_code'],
                'province' => $shipping['province'],
                'city' => $shipping['city'],
                'district' => $shipping['district'],
                'street' => $shipping['street'],
                'full_address' => $shipping['full_address'],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 86400 * 30);
        } catch (\Throwable) {
        }
    }

    private function currentCustomerId(): int
    {
        try {
            $frontend = $this->sessionFactory->createFrontendSession();
            if ($frontend->isLoggedIn()) {
                return max(0, (int)($frontend->getUserId() ?? 0));
            }
        } catch (\Throwable) {
        }

        $customerData = $this->session()->get('weshop_customer');

        return is_array($customerData) ? max(0, (int)($customerData['customer_id'] ?? 0)) : 0;
    }

    private function currentEmail(): string
    {
        try {
            $frontend = $this->sessionFactory->createFrontendSession();
            if (method_exists($frontend, 'getLoginUser')) {
                $user = $frontend->getLoginUser();
                if (is_object($user) && method_exists($user, 'getEmail')) {
                    return trim((string)$user->getEmail());
                }
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private function canAutoDetect(): bool
    {
        try {
            return (bool)Env::getInstance()->getModuleStatus('Weline_Location');
        } catch (\Throwable) {
            return false;
        }
    }

    private function session(): SessionInterface
    {
        return $this->sessionFactory->createSession();
    }
}
