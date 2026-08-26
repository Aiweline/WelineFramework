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
            $addresses = $this->listAddresses($countryCode);
            $selected = $this->selectedAddress($addresses, $countryCode, $countryName);

            return [
                'country_code' => $countryCode,
                'country_name' => $countryName,
                'countries' => $countries,
                'addresses' => $addresses,
                'selected' => $selected,
                'is_logged_in' => $this->currentCustomerId() > 0,
                'can_auto_detect' => $this->canAutoDetect(),
                'display_text' => $this->displayText($selected, $countryName),
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
        foreach ($this->listAddresses($this->currentCountryCode()) as $address) {
            if ((string)$address['id'] === $addressId) {
                $match = $address;
                break;
            }
        }
        if ($match === null) {
            throw new \InvalidArgumentException((string)__('地址不存在'));
        }

        $this->persistCountry((string)$match['country_code']);
        $this->writeSelected($match);
        $this->syncShippingSession($match);

        return $this->getContext(['country_code' => (string)$match['country_code']]);
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

        $customerId = $this->currentCustomerId();
        $saved = $customerId > 0
            ? $this->saveCustomerAddress($customerId, $normalized)
            : $this->saveGuestAddress($normalized);

        $this->persistCountry((string)$saved['country_code']);
        $this->writeSelected($saved);
        $this->syncShippingSession($saved);

        return $this->getContext(['country_code' => (string)$saved['country_code']]);
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
            'city' => trim((string)($address['city'] ?? '')),
            'address1' => $street,
            'postal_code' => trim((string)($address['postal_code'] ?? '')),
        ];
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
     * @return list<array<string, mixed>>
     */
    private function listAddresses(string $countryCode): array
    {
        $selected = $this->readSelected();
        $selectedId = is_array($selected) ? (string)($selected['id'] ?? '') : '';
        $items = [];

        $customerId = $this->currentCustomerId();
        if ($customerId > 0) {
            try {
                foreach ($this->deliveryAddressService->getListByCustomer($customerId, ['is_enabled' => 1]) as $model) {
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
            'city' => (string)($row['city'] ?? ''),
            'district' => (string)($row['district'] ?? ''),
            'street' => (string)($row['street'] ?? $row['address1'] ?? ''),
            'postal_code' => (string)($row['postal_code'] ?? ''),
            'contact_name' => (string)($row['contact_name'] ?? $row['name'] ?? ''),
            'contact_phone' => (string)($row['contact_phone'] ?? $row['phone'] ?? ''),
        ]);
        $id = (string)($row['id'] ?? $row['delivery_address_id'] ?? $row[DeliveryAddress::schema_fields_ID] ?? '');
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
            'city' => (string)$normalized['city'],
            'district' => (string)$normalized['district'],
            'street' => (string)$normalized['street'],
            'postal_code' => (string)$normalized['postal_code'],
            'full_address' => $full,
            'is_selected' => $selected,
            'display_label' => $name !== '' ? $name . ' · ' . $full : $full,
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
        ];

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

        $projected = $this->projectAddress(
            $normalized + ['id' => $id, 'source' => 'guest', 'is_anonymous' => true],
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
