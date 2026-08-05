<?php

declare(strict_types=1);

/**
 * P2E-002 current-source fixture.
 *
 * Shipping configuration, CheckoutSession, Inventory reservations and Orders
 * all use the configured database. Only catalog Offer snapshots use the
 * documented Cart V2 E2E harness.
 *
 * stdin JSON:
 * - {"action":"prepare"}
 * - {"action":"mutate_shipping","fixture":{...}}
 * - {"action":"verify","quote_token":"...","checkout_group_uuid":"..."}
 * - {"action":"atomic_failure","fixture":{...}}
 * - {"action":"cleanup","fixture":{...},"quote_tokens":[],"group_uuids":[]}
 */

use Weline\Cart\Api\Development\CartV2HarnessCatalog;
use Weline\Checkout\Api\CheckoutSessionStoreInterface;
use Weline\Checkout\Model\CheckoutSession;
use Weline\Checkout\Service\CheckoutGroupSubmitService;
use Weline\Checkout\Service\OrmCheckoutSessionStore;
use Weline\Checkout\Service\ShippingAllocationService;
use Weline\Customer\Model\Customer;
use Weline\Customer\Service\CustomerAccountService;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Api\InventoryCapabilityInterface;
use Weline\Inventory\Model\InventoryLedger;
use Weline\Inventory\Model\InventoryStock;
use Weline\Inventory\Model\Reservation;
use Weline\Inventory\Service\InventoryService;
use Weline\Order\Api\Data\CreateCheckoutGroupCommand;
use Weline\Order\Api\Data\CreateCheckoutGroupResult;
use Weline\Order\Api\Data\OrderPlan;
use Weline\Order\Api\Data\OrderReadResult;
use Weline\Order\Api\OrderFacadeInterface;
use Weline\Order\Model\CheckoutGroup;
use Weline\Order\Model\DisplayNumberRegistry;
use Weline\Order\Model\FulfillmentUnit;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderItem;
use Weline\Order\Service\OrderFacade;
use Weline\Shipping\Api\Quote\ShippingQuoteServiceInterface;
use Weline\Shipping\Model\Carrier;
use Weline\Shipping\Model\RateTemplate;
use Weline\Shipping\Model\Region;
use Weline\Shipping\Model\ShippingService;
use Weline\Shipping\Model\Zone;
use Weline\Shipping\Model\ZoneRegion;
use Weline\Shipping\Service\ScopedShippingQuoteService;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

/** @return array<string, mixed> */
function p2e002_input(): array
{
    $raw = stream_get_contents(STDIN);
    $decoded = json_decode($raw !== false && trim($raw) !== '' ? $raw : '{}', true);

    return is_array($decoded) ? $decoded : [];
}

/** @param array<string, mixed> $payload */
function p2e002_output(array $payload, int $exitCode = 0): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit($exitCode);
}

function p2e002_uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/** @param class-string $modelClass */
function p2e002_count(string $modelClass): int
{
    $rows = (new $modelClass())->reset()->select()->fetch();

    return count($rows->getItems());
}

/**
 * Newly inserted models are re-read by their fixture-unique key because the
 * ORM does not guarantee that auto-increment identifiers are hydrated on the
 * same in-memory object for every connector.
 *
 * @param class-string<AbstractModel> $modelClass
 */
function p2e002_lookup_id(
    string $modelClass,
    string $lookupField,
    int|string $lookupValue,
    string $idField,
): int {
    $row = (new $modelClass())
        ->where($lookupField, $lookupValue)
        ->find()
        ->fetch();
    $id = $row instanceof AbstractModel ? (int)$row->getData($idField) : 0;
    if ($id <= 0) {
        throw new RuntimeException('P2E002 fixture row was not persisted: ' . $modelClass);
    }

    return $id;
}

/** @return array<string, mixed> */
function p2e002_prepare(): array
{
    $run = 'p2e002_' . substr(bin2hex(random_bytes(8)), 0, 12);
    $now = gmdate('Y-m-d H:i:s');
    $country = 'XZ';

    $region = new Region();
    $region->setData([
        Region::schema_fields_COUNTRY_CODE => $country,
        Region::schema_fields_PARENT_REGION_ID => null,
        Region::schema_fields_REGION_CODE => $run,
        Region::schema_fields_REGION_NAME => 'P2E002 Test Country',
        Region::schema_fields_REGION_TYPE => Region::TYPE_COUNTRY,
        Region::schema_fields_IS_ACTIVE => 1,
        Region::schema_fields_SORT_ORDER => 0,
        Region::schema_fields_CREATED_AT => $now,
        Region::schema_fields_UPDATED_AT => $now,
    ])->save();
    $regionId = p2e002_lookup_id(
        Region::class,
        Region::schema_fields_REGION_CODE,
        $run,
        Region::schema_fields_ID,
    );

    $zone = new Zone();
    $zone->setData([
        Zone::schema_fields_ZONE_NAME => 'P2E002 Zone',
        Zone::schema_fields_ZONE_CODE => $run,
        Zone::schema_fields_DESCRIPTION => 'P2E-002 isolated fixture',
        Zone::schema_fields_IS_ACTIVE => 1,
        Zone::schema_fields_SORT_ORDER => 0,
        Zone::schema_fields_CREATED_AT => $now,
        Zone::schema_fields_UPDATED_AT => $now,
    ])->save();
    $zoneId = p2e002_lookup_id(
        Zone::class,
        Zone::schema_fields_ZONE_CODE,
        $run,
        Zone::schema_fields_ID,
    );

    $zoneRegion = new ZoneRegion();
    $zoneRegion->setData([
        ZoneRegion::schema_fields_ZONE_ID => $zoneId,
        ZoneRegion::schema_fields_REGION_ID => $regionId,
        ZoneRegion::schema_fields_CREATED_AT => $now,
    ])->save();
    $zoneRegionId = p2e002_lookup_id(
        ZoneRegion::class,
        ZoneRegion::schema_fields_ZONE_ID,
        $zoneId,
        ZoneRegion::schema_fields_ID,
    );

    $carrier = new Carrier();
    $carrier->setData([
        Carrier::schema_fields_CARRIER_CODE => $run,
        Carrier::schema_fields_CARRIER_NAME => 'P2E002 Carrier',
        Carrier::schema_fields_CARRIER_TYPE => Carrier::TYPE_MANUAL,
        Carrier::schema_fields_TRACKING_URL_TEMPLATE => 'https://example.test/track/{tracking_number}',
        Carrier::schema_fields_TRACKING_API_METHOD => 'GET',
        Carrier::schema_fields_TRACKING_SUPPORT_STATUS => Carrier::TRACKING_SUPPORTED,
        Carrier::schema_fields_IS_ACTIVE => 1,
        Carrier::schema_fields_SORT_ORDER => 0,
        Carrier::schema_fields_CREATED_AT => $now,
        Carrier::schema_fields_UPDATED_AT => $now,
    ])->save();
    $carrierId = p2e002_lookup_id(
        Carrier::class,
        Carrier::schema_fields_CARRIER_CODE,
        $run,
        Carrier::schema_fields_ID,
    );

    $template = new RateTemplate();
    $template->setData([
        RateTemplate::schema_fields_TEMPLATE_NAME => 'P2E002 Fixed',
        RateTemplate::schema_fields_TEMPLATE_CODE => $run,
        RateTemplate::schema_fields_CALCULATION_TYPE => RateTemplate::CALC_TYPE_FIXED,
        RateTemplate::schema_fields_BASE_FEE => '15.00',
        RateTemplate::schema_fields_WEIGHT_UNIT => 'kg',
        RateTemplate::schema_fields_WEIGHT_RATE => '0',
        RateTemplate::schema_fields_VOLUME_UNIT => 'm3',
        RateTemplate::schema_fields_VOLUME_RATE => '0',
        RateTemplate::schema_fields_QUANTITY_RATE => '0',
        RateTemplate::schema_fields_CURRENCY_CODE => 'CNY',
        RateTemplate::schema_fields_IS_ACTIVE => 1,
        RateTemplate::schema_fields_CREATED_AT => $now,
        RateTemplate::schema_fields_UPDATED_AT => $now,
    ])->save();
    $templateId = p2e002_lookup_id(
        RateTemplate::class,
        RateTemplate::schema_fields_TEMPLATE_CODE,
        $run,
        RateTemplate::schema_fields_ID,
    );

    $service = new ShippingService();
    $service->setData([
        ShippingService::schema_fields_SERVICE_NAME => 'P2E002 Standard',
        ShippingService::schema_fields_SERVICE_CODE => $run,
        ShippingService::schema_fields_CARRIER_ID => $carrierId,
        ShippingService::schema_fields_ZONE_ID => $zoneId,
        ShippingService::schema_fields_RATE_TEMPLATE_ID => $templateId,
        ShippingService::schema_fields_FREE_SHIPPING_RULE_ID => null,
        ShippingService::schema_fields_ESTIMATED_DAYS_MIN => 1,
        ShippingService::schema_fields_ESTIMATED_DAYS_MAX => 3,
        ShippingService::schema_fields_IS_FREE_SHIPPING => 0,
        ShippingService::schema_fields_IS_ACTIVE => 1,
        ShippingService::schema_fields_SORT_ORDER => 0,
        ShippingService::schema_fields_CREATED_AT => $now,
        ShippingService::schema_fields_UPDATED_AT => $now,
    ])->save();
    $serviceId = p2e002_lookup_id(
        ShippingService::class,
        ShippingService::schema_fields_SERVICE_CODE,
        $run,
        ShippingService::schema_fields_ID,
    );

    $offerBase = random_int(20_000_000, 80_000_000);
    $offers = [
        'physical_a' => [
            'uuid' => p2e002_uuid(),
            'offer_id' => $offerBase,
            'product_id' => $offerBase + 100,
            'name' => 'P2E002 Physical A',
            'unit_price_minor' => 10000,
            'split_key' => 'a-physical',
            'legal_entity' => 'entity-shared',
            'requires_shipping' => true,
            'weight_minor' => 500,
            'volume_minor' => 1000,
        ],
        'physical_b' => [
            'uuid' => p2e002_uuid(),
            'offer_id' => $offerBase + 1,
            'product_id' => $offerBase + 101,
            'name' => 'P2E002 Physical B',
            'unit_price_minor' => 5000,
            'split_key' => 'b-physical',
            'legal_entity' => 'entity-shared',
            'requires_shipping' => true,
            'weight_minor' => 250,
            'volume_minor' => 500,
        ],
        'virtual' => [
            'uuid' => p2e002_uuid(),
            'offer_id' => $offerBase + 2,
            'product_id' => $offerBase + 102,
            'name' => 'P2E002 Virtual',
            'unit_price_minor' => 2500,
            'split_key' => 'a-digital',
            'legal_entity' => 'entity-shared',
            'requires_shipping' => false,
            'product_type' => 'virtual',
        ],
        'blocked_a' => [
            'uuid' => p2e002_uuid(),
            'offer_id' => $offerBase + 3,
            'product_id' => $offerBase + 103,
            'name' => 'P2E002 Blocked A',
            'unit_price_minor' => 3000,
            'split_key' => 'blocked-a',
            'legal_entity' => 'entity-a',
            'requires_shipping' => true,
        ],
        'blocked_b' => [
            'uuid' => p2e002_uuid(),
            'offer_id' => $offerBase + 4,
            'product_id' => $offerBase + 104,
            'name' => 'P2E002 Blocked B',
            'unit_price_minor' => 4000,
            'split_key' => 'blocked-b',
            'legal_entity' => 'entity-b',
            'requires_shipping' => true,
        ],
        'usd' => [
            'uuid' => p2e002_uuid(),
            'offer_id' => $offerBase + 5,
            'product_id' => $offerBase + 105,
            'name' => 'P2E002 USD',
            'unit_price_minor' => 1000,
            'split_key' => 'usd',
            'legal_entity' => 'entity-shared',
            'requires_shipping' => true,
            'currency' => 'USD',
        ],
    ];
    foreach ($offers as $offer) {
        CartV2HarnessCatalog::put((string)$offer['uuid'], [
            'name' => (string)$offer['name'],
            'sku' => strtoupper(str_replace('_', '-', $run)),
            'currency' => (string)($offer['currency'] ?? 'CNY'),
            'unit_price_minor' => (int)$offer['unit_price_minor'],
            'stock' => 20,
            'sellable' => true,
            'found' => true,
            'offer_id' => (int)$offer['offer_id'],
            'product_id' => (int)$offer['product_id'],
            'split_key' => (string)$offer['split_key'],
            'legal_entity' => (string)$offer['legal_entity'],
            'requires_shipping' => (bool)$offer['requires_shipping'],
            'product_type' => (string)($offer['product_type'] ?? 'simple'),
            'weight_minor' => (int)($offer['weight_minor'] ?? 0),
            'volume_minor' => (int)($offer['volume_minor'] ?? 0),
            'tax_class_code' => 'standard',
        ]);
    }

    /** @var InventoryService $inventory */
    $inventory = ObjectManager::getInstance(InventoryService::class);
    foreach ($offers as $offer) {
        if (!(bool)$offer['requires_shipping']) {
            continue;
        }
        foreach ([0, 1] as $storeId) {
            $key = $run . '-stock-' . $storeId . '-' . (int)$offer['offer_id'];
            $inventory->setOnHand(
                0,
                $storeId,
                (int)$offer['offer_id'],
                20,
                $key,
                hash('sha256', $key),
            );
        }
    }

    $email = $run . '@example.test';
    $password = 'P2e002CheckoutPass9';
    /** @var CustomerAccountService $accounts */
    $accounts = ObjectManager::getInstance(CustomerAccountService::class);
    $registered = $accounts->register($email, $password, [
        'firstname' => 'P2E002',
        'lastname' => 'Checkout',
    ]);
    $customer = is_array($registered) ? ($registered['customer'] ?? $registered['user'] ?? null) : $registered;
    if (!is_object($customer) || !method_exists($customer, 'getId') || (int)$customer->getId() <= 0) {
        $customer = $accounts->findByEmail($email);
    }
    if (!is_object($customer) || !method_exists($customer, 'getId') || (int)$customer->getId() <= 0) {
        throw new RuntimeException('P2E002 customer fixture failed');
    }

    /** @var ShippingQuoteServiceInterface $quotes */
    $quotes = ObjectManager::getInstance(ShippingQuoteServiceInterface::class);

    return [
        'run' => $run,
        'country_code' => $country,
        'address' => ['country_code' => $country],
        'currency' => 'CNY',
        'service_code' => $run,
        'shipping_amount_minor' => 1500,
        'config_version' => $quotes->activeConfigVersion(),
        'region_id' => $regionId,
        'zone_id' => $zoneId,
        'zone_region_id' => $zoneRegionId,
        'template_id' => $templateId,
        'service_id' => $serviceId,
        'carrier_id' => $carrierId,
        'offers' => $offers,
        'email' => $email,
        'password' => $password,
        'customer_id' => (int)$customer->getId(),
    ];
}

/** @param array<string, mixed> $fixture */
function p2e002_mutate_shipping(array $fixture): array
{
    $templateId = (int)($fixture['template_id'] ?? 0);
    if ($templateId <= 0) {
        throw new RuntimeException('P2E002 rate template missing');
    }
    ConnectionFactory::getInstance()
        ->getConnector()
        ->getQuery()
        ->clearQuery()
        ->table(RateTemplate::schema_table)
        ->where(RateTemplate::schema_fields_ID, $templateId)
        ->update([
            RateTemplate::schema_fields_BASE_FEE => '16.00',
            RateTemplate::schema_fields_UPDATED_AT => gmdate('Y-m-d H:i:s'),
        ])
        ->fetch();
    /** @var ShippingQuoteServiceInterface $quotes */
    $quotes = ObjectManager::getInstance(ShippingQuoteServiceInterface::class);

    return ['config_version' => $quotes->activeConfigVersion()];
}

/**
 * @return array<string, mixed>
 */
function p2e002_verify(string $quoteToken, string $groupUuid): array
{
    $session = (new CheckoutSession())
        ->where(CheckoutSession::schema_fields_QUOTE_TOKEN, $quoteToken)
        ->find()
        ->fetch();
    $sessionPayload = $session instanceof CheckoutSession && $session->getId()
        ? json_decode((string)$session->getData(CheckoutSession::schema_fields_PAYLOAD_JSON), true)
        : null;
    $sessionPayload = is_array($sessionPayload) ? $sessionPayload : [];

    $group = $groupUuid !== ''
        ? (new CheckoutGroup())
            ->where(CheckoutGroup::schema_fields_CHECKOUT_GROUP_UUID, $groupUuid)
            ->find()
            ->fetch()
        : null;
    $orders = [];
    if ($groupUuid !== '') {
        $collection = (new Order())
            ->where(Order::schema_fields_CHECKOUT_GROUP_UUID, $groupUuid)
            ->order(Order::schema_fields_ID, 'ASC')
            ->select()
            ->fetch();
        foreach ($collection->getItems() as $order) {
            if (!$order instanceof Order) {
                continue;
            }
            $catalog = json_decode((string)$order->getData(Order::schema_fields_CATALOG_SNAPSHOT_JSON), true);
            $money = json_decode((string)$order->getData(Order::schema_fields_MONEY_SNAPSHOT_JSON), true);
            $tax = json_decode((string)$order->getData(Order::schema_fields_TAX_SNAPSHOT_JSON), true);
            $orders[] = [
                'order_uuid' => (string)$order->getData(Order::schema_fields_ORDER_UUID),
                'customer_id' => $order->getData(Order::schema_fields_CUSTOMER_ID),
                'split_key' => (string)$order->getData(Order::schema_fields_SPLIT_KEY),
                'is_shipping_charge_owner' => (bool)(int)$order->getData(
                    Order::schema_fields_IS_SHIPPING_CHARGE_OWNER,
                ),
                'money' => is_array($money) ? $money : [],
                'tax' => is_array($tax) ? $tax : [],
                'lines' => is_array($catalog['lines'] ?? null) ? $catalog['lines'] : [],
            ];
        }
    }

    $reservations = [];
    foreach ((array)($sessionPayload['reservations'] ?? []) as $frozen) {
        if (!is_array($frozen)) {
            continue;
        }
        $uuid = (string)($frozen['reservation_uuid'] ?? '');
        $row = $uuid !== ''
            ? (new Reservation())
                ->where(Reservation::schema_fields_RESERVATION_UUID, $uuid)
                ->find()
                ->fetch()
            : null;
        $reservations[] = [
            'reservation_uuid' => $uuid,
            'found' => $row instanceof Reservation && (bool)$row->getId(),
            'offer_id' => $row instanceof Reservation
                ? (int)$row->getData(Reservation::schema_fields_OFFER_ID)
                : 0,
            'quantity_minor' => $row instanceof Reservation
                ? (int)$row->getData(Reservation::schema_fields_QUANTITY_MINOR)
                : 0,
            'state' => $row instanceof Reservation
                ? (string)$row->getData(Reservation::schema_fields_STATE)
                : '',
        ];
    }

    return [
        'session_found' => $session instanceof CheckoutSession && (bool)$session->getId(),
        'session_state' => $sessionPayload['state'] ?? null,
        'session_idempotency_key' => $sessionPayload['idempotency_key'] ?? null,
        'session_customer_id' => $sessionPayload['customer_id'] ?? null,
        'session_config_version' => $sessionPayload['config_version'] ?? null,
        'session_tax' => $sessionPayload['tax'] ?? null,
        'session_cart_hash' => $sessionPayload['cart_hash'] ?? null,
        'session_reservations' => $sessionPayload['reservations'] ?? [],
        'group_found' => $group instanceof CheckoutGroup && (bool)$group->getId(),
        'group_count' => $groupUuid === '' ? 0 : ($group instanceof CheckoutGroup && $group->getId() ? 1 : 0),
        'order_count' => count($orders),
        'orders' => $orders,
        'reservations' => $reservations,
    ];
}

/** @param array<string, mixed> $fixture */
function p2e002_atomic_failure(array $fixture): array
{
    $before = [
        'groups' => p2e002_count(CheckoutGroup::class),
        'orders' => p2e002_count(Order::class),
        'reservations' => p2e002_count(Reservation::class),
    ];
    $delegate = new OrderFacade();
    $failing = new class($delegate) implements OrderFacadeInterface {
        public function __construct(
            private readonly OrderFacadeInterface $delegate,
        ) {
        }

        public function plan(CreateCheckoutGroupCommand $command): OrderPlan
        {
            return $this->delegate->plan($command);
        }

        public function create(CreateCheckoutGroupCommand $command): CreateCheckoutGroupResult
        {
            throw new RuntimeException('p2e002_injected_order_failure');
        }

        public function get(string $orderUuid): OrderReadResult
        {
            return $this->delegate->get($orderUuid);
        }

        public function notifyOrderPaid(string $orderUuid, array $context = []): void
        {
            $this->delegate->notifyOrderPaid($orderUuid, $context);
        }
    };
    $inventory = ObjectManager::getInstance(InventoryService::class);
    $sessions = ObjectManager::getInstance(OrmCheckoutSessionStore::class);
    $quotes = ObjectManager::getInstance(ScopedShippingQuoteService::class);
    $transactions = ObjectManager::getInstance(DatabaseTransactionRunnerInterface::class);
    $checkout = new CheckoutGroupSubmitService(
        $quotes,
        new ShippingAllocationService(),
        $failing,
        $sessions,
        inventory: $inventory,
        transactions: $transactions,
        connectionFactory: ConnectionFactory::getInstance(),
        resolveRuntimeInventory: false,
    );
    $offer = (array)($fixture['offers']['physical_a'] ?? []);
    $frozen = $checkout->freezeAndQuote(
        lines: [[
            'line_uuid' => p2e002_uuid(),
            'offer_id' => (int)($offer['offer_id'] ?? 0),
            'product_id' => (int)($offer['product_id'] ?? 0),
            'name' => (string)($offer['name'] ?? 'P2E002 Atomic'),
            'qty_minor' => 1,
            'unit_price_minor' => (int)($offer['unit_price_minor'] ?? 10000),
            'split_key' => 'atomic',
            'legal_entity' => 'entity-shared',
            'requires_shipping' => true,
        ]],
        address: (array)($fixture['address'] ?? []),
        scope: ['website_id' => 0, 'store_id' => 0],
        serviceCode: (string)($fixture['service_code'] ?? ''),
        currency: 'CNY',
        configVersion: (string)($fixture['config_version'] ?? ''),
        cartHash: hash('sha256', (string)($fixture['run'] ?? 'atomic')),
    );
    $error = '';
    try {
        $checkout->submit($frozen['quote_token'], (string)$fixture['run'] . '-atomic');
    } catch (Throwable $throwable) {
        $error = $throwable->getMessage();
    }
    $afterSession = $checkout->getSession((string)$frozen['quote_token']);
    $after = [
        'groups' => p2e002_count(CheckoutGroup::class),
        'orders' => p2e002_count(Order::class),
        'reservations' => p2e002_count(Reservation::class),
    ];
    $sessions->delete((string)$frozen['quote_token']);

    return [
        'error' => $error,
        'before' => $before,
        'after' => $after,
        'session_state_after_failure' => $afterSession['state'] ?? null,
        'quote_token' => $frozen['quote_token'],
    ];
}

/**
 * @param array<string, mixed> $fixture
 * @param list<string> $quoteTokens
 * @param list<string> $groupUuids
 */
function p2e002_cleanup(array $fixture, array $quoteTokens, array $groupUuids): void
{
    foreach ($quoteTokens as $token) {
        $session = (new CheckoutSession())
            ->where(CheckoutSession::schema_fields_QUOTE_TOKEN, $token)
            ->find()
            ->fetch();
        $payload = $session instanceof CheckoutSession && $session->getId()
            ? json_decode((string)$session->getData(CheckoutSession::schema_fields_PAYLOAD_JSON), true)
            : null;
        foreach ((array)($payload['reservations'] ?? []) as $reservation) {
            $uuid = is_array($reservation) ? (string)($reservation['reservation_uuid'] ?? '') : '';
            if ($uuid !== '') {
                (new InventoryLedger())
                    ->where(InventoryLedger::schema_fields_RESERVATION_UUID, $uuid)
                    ->delete();
                (new Reservation())
                    ->where(Reservation::schema_fields_RESERVATION_UUID, $uuid)
                    ->delete();
            }
        }
        (new CheckoutSession())
            ->where(CheckoutSession::schema_fields_QUOTE_TOKEN, $token)
            ->delete();
    }

    foreach ($groupUuids as $groupUuid) {
        $orders = (new Order())
            ->where(Order::schema_fields_CHECKOUT_GROUP_UUID, $groupUuid)
            ->select()
            ->fetch();
        foreach ($orders->getItems() as $order) {
            if (!$order instanceof Order) {
                continue;
            }
            $orderUuid = (string)$order->getData(Order::schema_fields_ORDER_UUID);
            (new OrderItem())->where(OrderItem::schema_fields_ORDER_UUID, $orderUuid)->delete();
            (new FulfillmentUnit())->where(FulfillmentUnit::schema_fields_ORDER_UUID, $orderUuid)->delete();
            (new DisplayNumberRegistry())
                ->where(DisplayNumberRegistry::schema_fields_ENTITY_UUID, $orderUuid)
                ->delete();
        }
        (new Order())->where(Order::schema_fields_CHECKOUT_GROUP_UUID, $groupUuid)->delete();
        (new CheckoutGroup())
            ->where(CheckoutGroup::schema_fields_CHECKOUT_GROUP_UUID, $groupUuid)
            ->delete();
    }

    foreach ((array)($fixture['offers'] ?? []) as $offer) {
        if (!is_array($offer)) {
            continue;
        }
        $offerId = (int)($offer['offer_id'] ?? 0);
        if ($offerId > 0) {
            (new Reservation())->where(Reservation::schema_fields_OFFER_ID, $offerId)->delete();
            (new InventoryLedger())->where(InventoryLedger::schema_fields_OFFER_ID, $offerId)->delete();
            (new InventoryStock())->where(InventoryStock::schema_fields_OFFER_ID, $offerId)->delete();
        }
        $uuid = (string)($offer['uuid'] ?? '');
        if ($uuid !== '') {
            CartV2HarnessCatalog::delete($uuid);
        }
    }

    $serviceId = (int)($fixture['service_id'] ?? 0);
    $carrierId = (int)($fixture['carrier_id'] ?? 0);
    $templateId = (int)($fixture['template_id'] ?? 0);
    $zoneRegionId = (int)($fixture['zone_region_id'] ?? 0);
    $zoneId = (int)($fixture['zone_id'] ?? 0);
    $regionId = (int)($fixture['region_id'] ?? 0);
    if ($serviceId > 0) {
        (new ShippingService())->where(ShippingService::schema_fields_ID, $serviceId)->delete();
    }
    if ($carrierId > 0) {
        (new Carrier())->where(Carrier::schema_fields_ID, $carrierId)->delete();
    }
    if ($templateId > 0) {
        (new RateTemplate())->where(RateTemplate::schema_fields_ID, $templateId)->delete();
    }
    if ($zoneRegionId > 0) {
        (new ZoneRegion())->where(ZoneRegion::schema_fields_ID, $zoneRegionId)->delete();
    }
    if ($zoneId > 0) {
        (new Zone())->where(Zone::schema_fields_ID, $zoneId)->delete();
    }
    if ($regionId > 0) {
        (new Region())->where(Region::schema_fields_ID, $regionId)->delete();
    }

    $customerId = (int)($fixture['customer_id'] ?? 0);
    if ($customerId > 0) {
        (new Customer())
            ->where(Customer::schema_fields_ID, $customerId)
            ->delete();
        $remainingCustomer = (new Customer())
            ->where(Customer::schema_fields_ID, $customerId)
            ->find()
            ->fetch();
        if ($remainingCustomer instanceof Customer && $remainingCustomer->getId()) {
            throw new RuntimeException('P2E002 customer fixture cleanup failed: ' . $customerId);
        }
    }
}

try {
    $input = p2e002_input();
    $action = (string)($input['action'] ?? '');
    $fixture = is_array($input['fixture'] ?? null) ? $input['fixture'] : [];
    if ($action === 'prepare') {
        p2e002_output(['ok' => true, 'fixture' => p2e002_prepare()]);
    }
    if ($action === 'mutate_shipping') {
        p2e002_output(['ok' => true] + p2e002_mutate_shipping($fixture));
    }
    if ($action === 'verify') {
        p2e002_output([
            'ok' => true,
            'data' => p2e002_verify(
                trim((string)($input['quote_token'] ?? '')),
                trim((string)($input['checkout_group_uuid'] ?? '')),
            ),
        ]);
    }
    if ($action === 'atomic_failure') {
        p2e002_output(['ok' => true, 'data' => p2e002_atomic_failure($fixture)]);
    }
    if ($action === 'cleanup') {
        p2e002_cleanup(
            $fixture,
            array_values(array_map('strval', (array)($input['quote_tokens'] ?? []))),
            array_values(array_map('strval', (array)($input['group_uuids'] ?? []))),
        );
        p2e002_output(['ok' => true, 'cleaned' => true]);
    }
    throw new InvalidArgumentException('unknown fixture action: ' . $action);
} catch (Throwable $throwable) {
    p2e002_output([
        'ok' => false,
        'error' => $throwable->getMessage(),
        'type' => $throwable::class,
        'trace' => $throwable->getTraceAsString(),
    ], 1);
}
