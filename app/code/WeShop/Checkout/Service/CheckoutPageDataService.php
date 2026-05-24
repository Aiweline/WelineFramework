<?php

declare(strict_types=1);

namespace WeShop\Checkout\Service;

use Weline\Currency\Helper\CurrencyFormatter;
use Weline\Framework\App\State;
use Weline\Checkout\Service\CheckoutIdentityService;
use WeShop\Address\Service\AddressService;
use WeShop\Cart\Service\CartIdentityService;
use WeShop\Cart\Service\CartService;
use WeShop\Order\Service\OrderService;
use WeShop\Payment\Service\PaymentMethodLocalDescriptionService;
use WeShop\Shipping\Service\ShippingMethodLocalDescriptionService;
use WeShop\Shipping\Service\ShippingService;
use Weline\I18n\Model\I18n;

class CheckoutPageDataService
{
    private const US_STATE_NAMES = [
        'AL' => 'Alabama',
        'AK' => 'Alaska',
        'AZ' => 'Arizona',
        'AR' => 'Arkansas',
        'CA' => 'California',
        'CO' => 'Colorado',
        'CT' => 'Connecticut',
        'DE' => 'Delaware',
        'DC' => 'District of Columbia',
        'FL' => 'Florida',
        'GA' => 'Georgia',
        'HI' => 'Hawaii',
        'ID' => 'Idaho',
        'IL' => 'Illinois',
        'IN' => 'Indiana',
        'IA' => 'Iowa',
        'KS' => 'Kansas',
        'KY' => 'Kentucky',
        'LA' => 'Louisiana',
        'ME' => 'Maine',
        'MD' => 'Maryland',
        'MA' => 'Massachusetts',
        'MI' => 'Michigan',
        'MN' => 'Minnesota',
        'MS' => 'Mississippi',
        'MO' => 'Missouri',
        'MT' => 'Montana',
        'NE' => 'Nebraska',
        'NV' => 'Nevada',
        'NH' => 'New Hampshire',
        'NJ' => 'New Jersey',
        'NM' => 'New Mexico',
        'NY' => 'New York',
        'NC' => 'North Carolina',
        'ND' => 'North Dakota',
        'OH' => 'Ohio',
        'OK' => 'Oklahoma',
        'OR' => 'Oregon',
        'PA' => 'Pennsylvania',
        'RI' => 'Rhode Island',
        'SC' => 'South Carolina',
        'SD' => 'South Dakota',
        'TN' => 'Tennessee',
        'TX' => 'Texas',
        'UT' => 'Utah',
        'VT' => 'Vermont',
        'VA' => 'Virginia',
        'WA' => 'Washington',
        'WV' => 'West Virginia',
        'WI' => 'Wisconsin',
        'WY' => 'Wyoming',
    ];

    private const US_STATE_ZH_NAMES = [
        'AL' => '亚拉巴马州',
        'AK' => '阿拉斯加州',
        'AZ' => '亚利桑那州',
        'AR' => '阿肯色州',
        'CA' => '加利福尼亚州',
        'CO' => '科罗拉多州',
        'CT' => '康涅狄格州',
        'DE' => '特拉华州',
        'DC' => '哥伦比亚特区',
        'FL' => '佛罗里达州',
        'GA' => '佐治亚州',
        'HI' => '夏威夷州',
        'ID' => '爱达荷州',
        'IL' => '伊利诺伊州',
        'IN' => '印第安纳州',
        'IA' => '艾奥瓦州',
        'KS' => '堪萨斯州',
        'KY' => '肯塔基州',
        'LA' => '路易斯安那州',
        'ME' => '缅因州',
        'MD' => '马里兰州',
        'MA' => '马萨诸塞州',
        'MI' => '密歇根州',
        'MN' => '明尼苏达州',
        'MS' => '密西西比州',
        'MO' => '密苏里州',
        'MT' => '蒙大拿州',
        'NE' => '内布拉斯加州',
        'NV' => '内华达州',
        'NH' => '新罕布什尔州',
        'NJ' => '新泽西州',
        'NM' => '新墨西哥州',
        'NY' => '纽约州',
        'NC' => '北卡罗来纳州',
        'ND' => '北达科他州',
        'OH' => '俄亥俄州',
        'OK' => '俄克拉荷马州',
        'OR' => '俄勒冈州',
        'PA' => '宾夕法尼亚州',
        'RI' => '罗德岛州',
        'SC' => '南卡罗来纳州',
        'SD' => '南达科他州',
        'TN' => '田纳西州',
        'TX' => '得克萨斯州',
        'UT' => '犹他州',
        'VT' => '佛蒙特州',
        'VA' => '弗吉尼亚州',
        'WA' => '华盛顿州',
        'WV' => '西弗吉尼亚州',
        'WI' => '威斯康星州',
        'WY' => '怀俄明州',
    ];

    public function __construct(
        private readonly CartService $cartService,
        private readonly AddressService $addressService,
        private readonly ShippingService $shippingService,
        private readonly CheckoutService $checkoutService,
        private readonly I18n $i18n,
        private readonly OrderService $orderService,
        private readonly ?ShippingMethodLocalDescriptionService $shippingMethodLocalDescriptionService = null,
        private readonly ?PaymentMethodLocalDescriptionService $paymentMethodLocalDescriptionService = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $customerId, int $currentStep = 1, int $retryOrderId = 0, array $checkoutData = []): array
    {
        $checkoutCurrency = $this->resolveCheckoutCurrency();
        $isGuestCheckout = $this->resolveIsGuestCheckout($customerId, $checkoutData);
        $addressCustomerId = $this->resolveAddressCustomerId($customerId, $checkoutData, $isGuestCheckout);
        $retryContext = $retryOrderId > 0 ? $this->orderService->getRetryPaymentContext($retryOrderId, $customerId) : null;
        $isRetryPayment = \is_array($retryContext);

        if ($isRetryPayment) {
            $cartItems = $this->mapRetryOrderItems((array) ($retryContext['items'] ?? []), $checkoutCurrency);
            $summary = $this->convertSummaryForDisplay(
                $this->normalizeSummary((array) ($retryContext['summary'] ?? [])),
                $checkoutCurrency
            );
        } else {
            $items = $this->cartService->getCartItems($customerId);
            $cartItems = $this->mapCartItems($items, $checkoutCurrency);
            $summary = $this->convertSummaryForDisplay(
                $this->normalizeSummary($this->cartService->calculateTotals($customerId)),
                $checkoutCurrency
            );
        }

        $savedAddresses = $this->loadSavedAddresses($addressCustomerId);
        $methodData = $this->buildMethodDataPayload($customerId, $savedAddresses, $checkoutData + [
            'address_customer_id' => $addressCustomerId,
            'is_guest_checkout' => $isGuestCheckout,
        ]);

        $itemCount = array_reduce(
            $cartItems,
            static fn(int $count, array $item): int => $count + (int) ($item['qty'] ?? 0),
            0
        );

        return [
            'cart_items' => $cartItems,
            'cart_count' => $itemCount,
            'item_count' => $itemCount,
            'cart_total' => (float) ($summary['subtotal'] ?? 0),
            'shipping' => (float) ($summary['shipping'] ?? 0),
            'tax' => (float) ($summary['tax'] ?? 0),
            'cart_summary' => $summary,
            'checkout_currency' => $checkoutCurrency,
            'saved_addresses' => $savedAddresses,
            'shipping_addresses' => $savedAddresses,
            'billing_addresses' => $savedAddresses,
            'address_customer_id' => $addressCustomerId,
            'selected_shipping_address_id' => (int) ($methodData['selected_shipping_address_id'] ?? 0),
            'selected_billing_address_id' => (int) ($methodData['selected_billing_address_id'] ?? 0),
            'billing_same_as_shipping' => (bool) ($methodData['billing_same_as_shipping'] ?? true),
            'shipping_methods' => $methodData['shipping_methods'] ?? [],
            'payment_methods' => $methodData['payment_methods'] ?? [],
            'current_step' => max(1, min(3, $currentStep)),
            'countries' => $this->buildCountries(),
            'states' => [],
            'is_retry_payment' => $isRetryPayment,
            'is_guest_checkout' => $isGuestCheckout,
            'retry_order_id' => $isRetryPayment ? (int) ($retryContext['order_id'] ?? 0) : 0,
            'retry_order_increment_id' => $isRetryPayment ? (string) ($retryContext['increment_id'] ?? '') : '',
        ];
    }

    /**
     * @param array<string, mixed> $checkoutData
     * @return array<string, mixed>
     */
    public function buildDynamicMethodData(int $customerId, array $checkoutData = []): array
    {
        $isGuestCheckout = $this->resolveIsGuestCheckout($customerId, $checkoutData);
        $addressCustomerId = $this->resolveAddressCustomerId($customerId, $checkoutData, $isGuestCheckout);
        $savedAddresses = $this->loadSavedAddresses($addressCustomerId);

        return $this->buildMethodDataPayload($customerId, $savedAddresses, $checkoutData + [
            'address_customer_id' => $addressCustomerId,
            'is_guest_checkout' => $isGuestCheckout,
        ], true);
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, array<string, mixed>>
     */
    protected function mapCartItems(array $items, ?string $targetCurrency = null): array
    {
        $targetCurrency = $targetCurrency ?: $this->resolveCheckoutCurrency();
        $result = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $product = \is_array($item['product'] ?? null) ? $item['product'] : [];
            $qty = (int) ($item['quantity'] ?? $item['qty'] ?? 1);
            $price = (float) ($item['price'] ?? 0);
            $rowTotal = $price * $qty;
            $result[] = [
                'item_id' => (int) ($item['item_id'] ?? $item['cart_id'] ?? 0),
                'product_id' => (int) ($item['product_id'] ?? 0),
                'name' => (string) ($product['name'] ?? $item['product_name'] ?? ''),
                'image' => (string) ($product['image'] ?? $item['image'] ?? ''),
                'price' => $this->convertDisplayAmount($price, $targetCurrency),
                'qty' => $qty,
                'row_total' => $this->convertDisplayAmount($rowTotal, $targetCurrency),
                'options' => $this->normalizeOptions($item['options'] ?? $item['product_options'] ?? null),
            ];
        }

        return $result;
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, array<string, mixed>>
     */
    protected function mapRetryOrderItems(array $items, ?string $targetCurrency = null): array
    {
        $targetCurrency = $targetCurrency ?: $this->resolveCheckoutCurrency();
        $result = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $price = (float) ($item['price'] ?? 0);
            $rowTotal = (float) ($item['row_total'] ?? $item['total'] ?? 0);
            $result[] = [
                'item_id' => (int) ($item['item_id'] ?? 0),
                'product_id' => (int) ($item['product_id'] ?? 0),
                'name' => (string) ($item['name'] ?? $item['product_name'] ?? ''),
                'image' => (string) ($item['image'] ?? ''),
                'price' => $this->convertDisplayAmount($price, $targetCurrency),
                'qty' => (int) ($item['qty'] ?? $item['quantity'] ?? 0),
                'row_total' => $this->convertDisplayAmount($rowTotal, $targetCurrency),
                'options' => $this->normalizeOptions($item['options'] ?? $item['product_options'] ?? null),
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function normalizeOptions(mixed $rawOptions): array
    {
        if (\is_string($rawOptions)) {
            $rawOptions = \trim($rawOptions);
            if ($rawOptions === '') {
                return [];
            }

            $decoded = \json_decode($rawOptions, true);
            if (\is_array($decoded)) {
                return $this->normalizeOptions($decoded);
            }

            return [[
                'label' => (string) __('规格'),
                'value' => $rawOptions,
            ]];
        }

        if (!\is_array($rawOptions) || $rawOptions === []) {
            return [];
        }

        $isAssoc = \array_keys($rawOptions) !== \range(0, \count($rawOptions) - 1);
        if ($isAssoc) {
            $options = [];
            foreach ($rawOptions as $label => $value) {
                if (\is_scalar($value) && \trim((string) $value) !== '') {
                    $options[] = [
                        'label' => \trim((string) $label) !== '' ? \trim((string) $label) : (string) __('规格'),
                        'value' => \trim((string) $value),
                    ];
                }
            }

            return $options;
        }

        $options = [];
        foreach ($rawOptions as $option) {
            if (!\is_array($option)) {
                continue;
            }

            $value = \trim((string) ($option['value'] ?? ''));
            if ($value === '') {
                continue;
            }

            $normalized = [
                'label' => \trim((string) ($option['label'] ?? '')) !== ''
                    ? \trim((string) ($option['label'] ?? ''))
                    : (string) __('规格'),
                'value' => $value,
            ];

            foreach (['attribute_id', 'option_id'] as $idKey) {
                $id = (int) ($option[$idKey] ?? 0);
                if ($id > 0) {
                    $normalized[$idKey] = $id;
                }
            }

            $code = \trim((string) ($option['code'] ?? ''));
            if ($code !== '') {
                $normalized['code'] = $code;
            }

            $swatchType = \trim((string) ($option['swatch_type'] ?? ''));
            $swatchValue = \trim((string) ($option['swatch_value'] ?? ''));
            if ($swatchType !== '' && $swatchValue !== '') {
                $normalized['swatch_type'] = $swatchType;
                $normalized['swatch_value'] = $swatchValue;
            }

            $options[] = $normalized;
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    protected function normalizeSummary(array $summary): array
    {
        $normalized = [
            'subtotal' => (float) ($summary['subtotal'] ?? 0),
            'shipping' => (float) ($summary['shipping'] ?? 0),
            'discount' => (float) ($summary['discount'] ?? 0),
            'tax' => (float) ($summary['tax'] ?? 0),
            'grand_total' => (float) ($summary['grand_total'] ?? $summary['total'] ?? 0),
        ];

        foreach (['coupon_code', 'coupon_discount'] as $key) {
            if (array_key_exists($key, $summary)) {
                $normalized[$key] = $summary[$key];
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    protected function convertSummaryForDisplay(array $summary, string $targetCurrency): array
    {
        foreach (['subtotal', 'shipping', 'discount', 'tax', 'grand_total', 'coupon_discount'] as $key) {
            if (!array_key_exists($key, $summary)) {
                continue;
            }
            $summary[$key] = $this->convertDisplayAmount((float) ($summary[$key] ?? 0), $targetCurrency);
        }

        return $summary;
    }

    protected function convertDisplayAmount(float $amount, string $targetCurrency): float
    {
        $targetCurrency = strtoupper(trim($targetCurrency));
        if ($targetCurrency === '') {
            return round($amount, 2);
        }

        try {
            return round(CurrencyFormatter::convert($amount, null, $targetCurrency), 2);
        } catch (\Throwable) {
            return round($amount, 2);
        }
    }

    /**
     * @param array<string, mixed> $address
     */
    protected function localizeAddressRegionForDisplay(array $address, string $rawRegion): string
    {
        $rawRegion = trim($rawRegion);
        if ($rawRegion === '') {
            return '';
        }

        $country = strtoupper(trim((string) ($address['country_id'] ?? $address['country'] ?? '')));
        if ($country !== 'US') {
            return $rawRegion;
        }

        $stateCode = $this->resolveUsStateCode(
            (string) ($address['province_code'] ?? $address['region_code'] ?? ''),
            $rawRegion
        );
        if ($stateCode === '') {
            return $rawRegion;
        }

        $locale = \Weline\Framework\Http\Cookie::getLangLocal() ?: 'zh_Hans_CN';
        if (str_starts_with($locale, 'zh')) {
            return self::US_STATE_ZH_NAMES[$stateCode] ?? $rawRegion;
        }

        return self::US_STATE_NAMES[$stateCode] ?? $rawRegion;
    }

    protected function resolveUsStateCode(string $stateCode, string $stateName): string
    {
        $stateCode = strtoupper(trim($stateCode));
        if (isset(self::US_STATE_NAMES[$stateCode])) {
            return $stateCode;
        }

        $stateName = trim($stateName);
        $normalizedStateName = strtoupper($stateName);
        if (isset(self::US_STATE_NAMES[$normalizedStateName])) {
            return $normalizedStateName;
        }

        foreach (self::US_STATE_NAMES as $code => $name) {
            if (strcasecmp($stateName, $name) === 0) {
                return $code;
            }
        }

        foreach (self::US_STATE_ZH_NAMES as $code => $name) {
            if ($stateName === $name) {
                return $code;
            }
        }

        return '';
    }

    /**
     * @param array<int, mixed> $addresses
     * @return array<int, array<string, mixed>>
     */
    protected function mapSavedAddresses(array $addresses): array
    {
        $result = [];
        foreach ($addresses as $address) {
            if (!\is_array($address)) {
                continue;
            }

            $firstName = (string) ($address['firstname'] ?? '');
            $lastName = (string) ($address['lastname'] ?? '');
            $region = (string) ($address['region'] ?? $address['province'] ?? $address['state'] ?? '');
            $result[] = [
                'address_id' => (int) ($address['address_id'] ?? 0),
                'name' => trim($firstName . ' ' . $lastName),
                'firstname' => $firstName,
                'lastname' => $lastName,
                'street' => (string) ($address['street'] ?? ''),
                'city' => (string) ($address['city'] ?? ''),
                'state' => $this->localizeAddressRegionForDisplay($address, $region),
                'region' => $region,
                'country' => (string) ($address['country'] ?? $address['country_id'] ?? ''),
                'country_id' => (string) ($address['country_id'] ?? $address['country'] ?? ''),
                'postcode' => (string) ($address['postcode'] ?? ''),
                'telephone' => (string) ($address['telephone'] ?? ''),
                'is_default' => (bool) ($address['is_default'] ?? false),
            ];
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $savedAddresses
     * @return array<string, string>
     */
    protected function resolveInitialShippingContext(array $savedAddresses): array
    {
        $address = $this->resolvePrimaryShippingAddress($savedAddresses);

        return $this->resolveShippingContext($address);
    }

    /**
     * @param array<int, array<string, mixed>> $savedAddresses
     * @return array<string, mixed>
     */
    protected function resolvePrimaryShippingAddress(array $savedAddresses): array
    {
        foreach ($savedAddresses as $address) {
            if (!empty($address['is_default'])) {
                return $address;
            }
        }

        return $savedAddresses[0] ?? [];
    }

    /**
     * @param array<int, array<string, mixed>> $savedAddresses
     * @param array<string, mixed> $checkoutData
     * @return array<string, mixed>
     */
    protected function buildMethodDataPayload(
        int $customerId,
        array $savedAddresses,
        array $checkoutData,
        bool $includeSummaryPreview = false
    ): array
    {
        $checkoutCurrency = $this->resolveCheckoutCurrency();
        $isGuestCheckout = array_key_exists('is_guest_checkout', $checkoutData)
            ? (bool) $checkoutData['is_guest_checkout']
            : CartIdentityService::isGuestCartCustomerId($customerId);
        $selectedAddress = $this->resolveSelectedShippingAddress(
            $savedAddresses,
            $isGuestCheckout ? 0 : (int) ($checkoutData['shipping_address_id'] ?? 0)
        );
        $selectedShippingAddressId = (int) ($selectedAddress['address_id'] ?? 0);
        $inlineAddress = \is_array($checkoutData['shipping_address'] ?? null) ? $checkoutData['shipping_address'] : [];
        $resolvedAddress = $this->mergeShippingAddress($selectedAddress, $inlineAddress);
        $shippingContext = [
            'area' => 'frontend',
            'currency' => $checkoutCurrency,
            'customer_id' => $customerId,
            'cart_customer_id' => (int) ($checkoutData['cart_customer_id'] ?? $customerId),
            'address_customer_id' => (int) ($checkoutData['address_customer_id'] ?? 0),
            'authenticated_customer_id' => (int) ($checkoutData['authenticated_customer_id'] ?? 0),
            'checkout_mode' => $isGuestCheckout ? 'guest' : 'customer',
            'is_guest_checkout' => $isGuestCheckout,
            'guest_email' => (string) ($checkoutData['guest_email'] ?? $checkoutData['email'] ?? ''),
            'locale' => State::getLangLocal(),
        ] + $this->resolveShippingContext($resolvedAddress !== [] ? $resolvedAddress : $selectedAddress);

        $billingSameAsShipping = $this->normalizeBillingSameAsShipping($checkoutData);
        $selectedBillingAddress = $billingSameAsShipping
            ? $selectedAddress
            : $this->resolveSelectedShippingAddress(
                $savedAddresses,
                $isGuestCheckout ? 0 : (int) ($checkoutData['billing_address_id'] ?? 0)
            );
        $selectedBillingAddressId = $billingSameAsShipping ? 0 : (int) ($selectedBillingAddress['address_id'] ?? 0);

        $shippingMethods = $this->checkoutService->getCheckoutShippingMethods($customerId, $shippingContext);
        if ($shippingMethods === []) {
            $shippingMethods = $this->shippingService->getAvailableShippingMethods($shippingContext);
        }

        $prioritizedShippingMethods = $this->prioritizeSelectedMethod(
            $this->mapShippingMethods($shippingMethods),
            (string) ($checkoutData['shipping_method'] ?? '')
        );
        $prioritizedPaymentMethods = $this->prioritizeSelectedMethod(
            $this->mapPaymentMethods($this->filterGuestPaymentMethods(
                $this->checkoutService->getCheckoutPaymentMethods($customerId, $shippingContext),
                $isGuestCheckout
            )),
            (string) ($checkoutData['payment_method'] ?? '')
        );

        $payload = [
            'selected_shipping_address_id' => $selectedShippingAddressId,
            'selected_billing_address_id' => $selectedBillingAddressId,
            'billing_same_as_shipping' => $billingSameAsShipping,
            'shipping_methods' => $prioritizedShippingMethods,
            'payment_methods' => $prioritizedPaymentMethods,
        ];

        if (!$includeSummaryPreview) {
            return $payload;
        }

        $previewCheckoutData = $checkoutData;
        $previewCheckoutData['shipping_address_id'] = $selectedShippingAddressId;
        $previewCheckoutData['shipping_address'] = $resolvedAddress !== [] ? $resolvedAddress : $selectedAddress;
        $previewCheckoutData['shipping_method'] = $this->resolveSelectedMethodCode($prioritizedShippingMethods);
        $previewCheckoutData['payment_method'] = $this->resolveSelectedMethodCode($prioritizedPaymentMethods);
        $previewCheckoutData['billing_same_as_shipping'] = $billingSameAsShipping;
        $previewCheckoutData['billing_address_id'] = $selectedBillingAddressId;
        $previewCheckoutData['billing_address'] = $billingSameAsShipping
            ? []
            : (\is_array($checkoutData['billing_address'] ?? null) ? $checkoutData['billing_address'] : []);
        $previewCheckoutData['checkout_mode'] = $isGuestCheckout ? 'guest' : 'customer';
        $previewCheckoutData['is_guest_checkout'] = $isGuestCheckout;

        $payload['cart_summary'] = $this->convertSummaryForDisplay(
            $this->normalizeSummary(
                $this->checkoutService->previewCheckoutSummary($customerId, $previewCheckoutData)
            ),
            $checkoutCurrency
        );

        return $payload;
    }

    /**
     * @param array<int, array<string, mixed>> $savedAddresses
     * @return array<string, mixed>
     */
    protected function resolveSelectedShippingAddress(array $savedAddresses, int $selectedAddressId): array
    {
        if ($selectedAddressId > 0) {
            foreach ($savedAddresses as $address) {
                if ((int) ($address['address_id'] ?? 0) === $selectedAddressId) {
                    return $address;
                }
            }
        }

        return $this->resolvePrimaryShippingAddress($savedAddresses);
    }

    /**
     * @param array<string, mixed> $savedAddress
     * @param array<string, mixed> $inlineAddress
     * @return array<string, mixed>
     */
    protected function mergeShippingAddress(array $savedAddress, array $inlineAddress): array
    {
        $filteredInlineAddress = array_filter(
            $inlineAddress,
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []
        );

        if ($savedAddress === []) {
            return $filteredInlineAddress;
        }

        if ($filteredInlineAddress === []) {
            return $savedAddress;
        }

        return array_merge($savedAddress, $filteredInlineAddress);
    }

    /**
     * @param array<string, mixed> $address
     * @return array<string, string>
     */
    protected function resolveShippingContext(array $address): array
    {
        if ($address === []) {
            return [];
        }

        $country = (string) ($address['country_id'] ?? $address['country'] ?? '');
        $region = (string) ($address['region'] ?? $address['state'] ?? '');

        return array_filter([
            'country' => $country,
            'country_id' => $country,
            'region' => $region,
        ], static fn (string $value): bool => $value !== '');
    }

    /**
     * @param array<string, mixed> $methods
     * @return array<int, array<string, mixed>>
     */
    protected function mapShippingMethods(array $methods): array
    {
        $result = [];
        $position = 0;
        foreach ($methods as $index => $method) {
            $position++;
            $sortOrder = $position * 10;
            $isFirst = $position === 1;
            if (\is_array($method)) {
                $method = $this->getShippingMethodLocalDescriptionService()->localize($method, State::getLangLocal());
                $code = (string) ($method['code'] ?? '');
                if ($code === '') {
                    continue;
                }
                $name = (string) ($method['name'] ?? $method['title'] ?? $code);
                $result[] = [
                    'code' => $code,
                    'name' => $name,
                    'description' => (string) ($method['description'] ?? __('可用配送方式：%{1}', [$name])),
                    'price' => (float) ($method['price'] ?? 0),
                    'is_default' => (bool) ($method['is_default'] ?? $isFirst),
                    'sort_order' => (int) ($method['sort_order'] ?? $sortOrder),
                ];
                continue;
            }

            $code = (string) $index;
            $label = (string) $method;
            if ($code === '' || $label === '') {
                continue;
            }
            $localized = $this->getShippingMethodLocalDescriptionService()->localize([
                'code' => $code,
                'name' => $label,
            ], State::getLangLocal());
            $label = (string) ($localized['name'] ?? $label);
            $result[] = [
                'code' => $code,
                'name' => $label,
                'description' => (string) __('可用配送方式：%{1}', [$label]),
                'price' => 0.0,
                'is_default' => $isFirst,
                'sort_order' => $sortOrder,
            ];
        }

        usort(
            $result,
            static fn(array $left, array $right): int => ((int) ($left['sort_order'] ?? 0)) <=> ((int) ($right['sort_order'] ?? 0))
        );

        if ($result !== []) {
            $hasDefault = false;
            foreach ($result as $method) {
                if (!empty($method['is_default'])) {
                    $hasDefault = true;
                    break;
                }
            }
            if (!$hasDefault) {
                $result[0]['is_default'] = true;
            }
        }

        return $result;
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function buildCountries(): array
    {
        $countries = [];
        $displayLocale = \Weline\Framework\Http\Cookie::getLangLocal() ?: 'zh_Hans_CN';
        foreach ($this->i18n->getCountries($displayLocale) as $code => $name) {
            $countries[] = [
                'code' => (string) $code,
                'name' => (string) $name,
            ];
        }

        return $countries;
    }

    /**
     * @param array<int, mixed> $methods
     * @return array<int, array<string, mixed>>
     */
    protected function mapPaymentMethods(array $methods): array
    {
        $result = [];
        $hasExplicitDefault = false;

        foreach ($methods as $method) {
            if (!\is_array($method)) {
                continue;
            }

            $code = (string) ($method['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $method = $this->getPaymentMethodLocalDescriptionService()->localize($method, State::getLangLocal());

            $flow = $this->resolvePaymentFlow($code);
            $isDefault = (bool) ($method['is_default'] ?? false);
            $hasExplicitDefault = $hasExplicitDefault || $isDefault;
            $config = \is_array($method['config'] ?? null) ? $method['config'] : [];
            $title = (string) ($method['title'] ?? $method['name'] ?? $code);

            $result[] = [
                'code' => $code,
                'title' => $title,
                'name' => $title,
                'description' => (string) ($method['description'] ?? ''),
                'is_default' => $isDefault,
                'sort_order' => (int) ($method['sort_order'] ?? 0),
                'icon' => (string) ($method['icon'] ?? ''),
                'flow' => $flow,
                'flow_label' => $this->resolvePaymentFlowLabel($flow),
                'badge' => $this->resolvePaymentBadge($code, $flow),
                'checkout_note' => $this->resolvePaymentCheckoutNote($code, $config, $method),
            ];
        }

        usort(
            $result,
            static fn(array $left, array $right): int => ((int) ($left['sort_order'] ?? 0)) <=> ((int) ($right['sort_order'] ?? 0))
        );

        if (!$hasExplicitDefault && $result !== []) {
            $result[0]['is_default'] = true;
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function loadSavedAddresses(int $addressCustomerId): array
    {
        if ($addressCustomerId <= 0) {
            return [];
        }

        return $this->mapSavedAddresses($this->addressService->getCustomerAddresses($addressCustomerId));
    }

    /**
     * @param array<string, mixed> $checkoutData
     */
    protected function resolveIsGuestCheckout(int $customerId, array $checkoutData): bool
    {
        $mode = strtolower(trim((string) ($checkoutData['checkout_mode'] ?? '')));
        if ($mode === CheckoutIdentityService::MODE_GUEST) {
            return true;
        }

        if ($mode === CheckoutIdentityService::MODE_CUSTOMER) {
            return false;
        }

        if (array_key_exists('is_guest_checkout', $checkoutData)) {
            return (bool) $checkoutData['is_guest_checkout'];
        }

        return CartIdentityService::isGuestCartCustomerId($customerId);
    }

    /**
     * @param array<string, mixed> $checkoutData
     */
    protected function resolveAddressCustomerId(int $customerId, array $checkoutData, bool $isGuestCheckout): int
    {
        if ($isGuestCheckout) {
            return 0;
        }

        $authenticatedCustomerId = (int) ($checkoutData['authenticated_customer_id'] ?? 0);
        if ($authenticatedCustomerId > 0) {
            return $authenticatedCustomerId;
        }

        $explicitCustomerId = (int) ($checkoutData['customer_id'] ?? 0);
        if ($explicitCustomerId > 0 && !CartIdentityService::isGuestCartCustomerId($explicitCustomerId)) {
            return $explicitCustomerId;
        }

        return CartIdentityService::isGuestCartCustomerId($customerId) ? 0 : $customerId;
    }

    /**
     * @param array<int, mixed> $methods
     * @return array<int, mixed>
     */
    protected function filterGuestPaymentMethods(array $methods, bool $isGuest): array
    {
        if (!$isGuest) {
            return $methods;
        }

        return array_values(array_filter($methods, static function (mixed $method): bool {
            if (!\is_array($method)) {
                return false;
            }

            return (string) ($method['code'] ?? '') !== 'b2b_credit_account';
        }));
    }

    /**
     * @param array<int, array<string, mixed>> $methods
     * @return array<int, array<string, mixed>>
     */
    protected function prioritizeSelectedMethod(array $methods, string $selectedCode): array
    {
        $selectedCode = trim($selectedCode);
        if ($selectedCode === '' || $methods === []) {
            return $methods;
        }

        $matched = false;
        foreach ($methods as $index => $method) {
            if ((string) ($method['code'] ?? '') === $selectedCode) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            return $methods;
        }

        foreach ($methods as $index => $method) {
            $methods[$index]['is_default'] = (string) ($method['code'] ?? '') === $selectedCode;
        }

        return $methods;
    }

    /**
     * @param array<int, array<string, mixed>> $methods
     */
    protected function resolveSelectedMethodCode(array $methods): string
    {
        foreach ($methods as $method) {
            if (!empty($method['is_default'])) {
                return (string) ($method['code'] ?? '');
            }
        }

        return (string) ($methods[0]['code'] ?? '');
    }

    protected function resolvePaymentFlow(string $code): string
    {
        return match (strtolower($code)) {
            'paypal', 'alipay', 'wechatpay' => 'redirect',
            'manual_transfer' => 'offline',
            'cash_on_delivery' => 'offline_collection',
            default => 'direct',
        };
    }

    protected function resolvePaymentFlowLabel(string $flow): string
    {
        return match ($flow) {
            'redirect' => (string) __('下单后跳转支付'),
            'offline' => (string) __('下单后支付'),
            'offline_collection' => (string) __('送达后付款'),
            default => (string) __('结账时支付'),
        };
    }

    protected function resolvePaymentBadge(string $code, string $flow): string
    {
        return match (strtolower($code)) {
            'paypal' => (string) __('常用'),
            'manual_transfer' => (string) __('线下'),
            'cash_on_delivery' => (string) __('送达收款'),
            default => $flow === 'redirect' ? (string) __('跳转支付') : '',
        };
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $method
     */
    protected function resolvePaymentCheckoutNote(string $code, array $config, array $method): string
    {
        $methodNote = trim((string) ($method['checkout_note'] ?? ''));
        if ($methodNote !== '') {
            return $methodNote;
        }

        $instructions = trim((string) ($config['instructions'] ?? ''));
        $referenceNote = trim((string) ($config['reference_note'] ?? ''));

        if ($instructions !== '' && $referenceNote !== '') {
            return $instructions . ' ' . $referenceNote;
        }

        if ($instructions !== '') {
            return $instructions;
        }

        return match (strtolower($code)) {
            'paypal' => (string) __('下单后将跳转到 PayPal 安全完成支付。'),
            'cash_on_delivery' => (string) __('请在配送到达时准备好订单金额。'),
            default => trim((string) ($method['description'] ?? '')),
        };
    }

    /**
     * @param array<string, mixed> $checkoutData
     */
    private function normalizeBillingSameAsShipping(array $checkoutData): bool
    {
        if (!array_key_exists('billing_same_as_shipping', $checkoutData)) {
            return true;
        }

        $value = $checkoutData['billing_same_as_shipping'];
        if (\is_bool($value)) {
            return $value;
        }

        $value = strtolower(trim((string) $value));

        return !\in_array($value, ['0', 'false', 'off', 'no'], true);
    }

    private function getShippingMethodLocalDescriptionService(): ShippingMethodLocalDescriptionService
    {
        return $this->shippingMethodLocalDescriptionService ?? new ShippingMethodLocalDescriptionService();
    }

    private function getPaymentMethodLocalDescriptionService(): PaymentMethodLocalDescriptionService
    {
        return $this->paymentMethodLocalDescriptionService ?? new PaymentMethodLocalDescriptionService();
    }

    protected function resolveCheckoutCurrency(): string
    {
        $currency = trim((string) (\Weline\Framework\Env\WelineEnv::server('WELINE_USER_CURRENCY', '')));
        if ($currency !== '') {
            return strtoupper($currency);
        }

        return strtoupper((string) State::getCurrency());
    }
}
