<?php

declare(strict_types=1);

use Weline\Framework\Manager\ObjectManager;
use Weline\Frontend\Api\User\FrontendUserAdministrationInterface;
use Weline\Frontend\Api\User\FrontendUserMutationResult;
use Weline\Frontend\Api\User\FrontendUserSaveCommand;
use Weline\Frontend\Model\FrontendUser;
use Weline\Shipping\Model\Carrier;
use Weline\Shipping\Model\DeliveryAddress;
use Weline\Shipping\Model\FreeShippingRule;
use Weline\Shipping\Model\RateTemplate;
use Weline\Shipping\Model\Region;
use Weline\Shipping\Model\ShippingAddress;
use Weline\Shipping\Model\ShippingService;
use Weline\Shipping\Model\Zone;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

/** @return array<string,mixed> */
function r43_shipping_input(): array
{
    $decoded = json_decode((string)file_get_contents('php://stdin'), true);
    if (!is_array($decoded) || array_is_list($decoded)) throw new InvalidArgumentException('stdin_must_be_json_object');
    return $decoded;
}

/** @param array<string,mixed> $payload */
function r43_shipping_output(array $payload, int $code = 0): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit($code);
}

/** @template T of object @param class-string<T> $class @return T */
function r43_shipping_model(string $class): object
{
    return ObjectManager::getInstance($class, [], false);
}

/** @return array{connector:string,database:string} */
function r43_shipping_assert_isolated_pgsql(): array
{
    if (getenv('WELINE_E2E_ISOLATED_DB') !== '1') throw new RuntimeException('r43_shipping_requires_isolated_database_opt_in');
    /** @var ShippingAddress $model */
    $model = r43_shipping_model(ShippingAddress::class);
    $connector = get_class($model->getConnection()->getConnector());
    if (!str_contains(strtolower($connector), 'pgsql') && !str_contains(strtolower($connector), 'postgres')) throw new RuntimeException('r43_shipping_requires_postgresql:' . $connector);
    $database = (string)$model->getConnection()->getConnector()->getConfigProvider()->getDatabase();
    if (!str_starts_with($database, 'mig_clone_')) throw new RuntimeException('r43_shipping_requires_migration_clone:' . $database);
    return ['connector' => $connector, 'database' => $database];
}

function r43_shipping_delete(string $class, string $field, string|int $value): void
{
    $model = r43_shipping_model($class);
    $model->where($field, $value)->delete()->fetch();
}

/** @return array<string,mixed>|null */
function r43_shipping_find(string $class, string $field, string|int $value): ?array
{
    $model = r43_shipping_model($class);
    $model->load($field, $value);
    return $model->getId() ? $model->getData() : null;
}

function r43_shipping_customer(string $username): int
{
    /** @var FrontendUser $existing */
    $existing = r43_shipping_model(FrontendUser::class);
    $existing->load(FrontendUser::schema_fields_username, $username);
    if ($existing->getId()) return (int)$existing->getId();
    /** @var FrontendUserAdministrationInterface $users */
    $users = ObjectManager::getInstance(FrontendUserAdministrationInterface::class);
    $result = $users->save(new FrontendUserSaveCommand(0, $username, 'R43Shipping9!', '', true, true));
    if ($result->getStatus() !== FrontendUserMutationResult::SAVED) throw new RuntimeException('shipping_customer_prepare_failed:' . $result->getStatus());
    /** @var FrontendUser $created */
    $created = r43_shipping_model(FrontendUser::class);
    $created->load(FrontendUser::schema_fields_username, $username);
    if (!$created->getId()) throw new RuntimeException('shipping_customer_missing_after_save');
    return (int)$created->getId();
}

function r43_shipping_cleanup(string $case, string $token, array $input): void
{
    if (preg_match('/^[A-F0-9]{12}$/D', $token) !== 1) throw new InvalidArgumentException('invalid_r43_shipping_token');
    $code = 'R43_' . $token;
    match ($case) {
        'address' => r43_shipping_delete(ShippingAddress::class, ShippingAddress::schema_fields_NAME, 'R43 Shipping ' . $token),
        'delivery' => (function () use ($token, $input): void {
            r43_shipping_delete(DeliveryAddress::class, DeliveryAddress::schema_fields_NAME, 'R43 Delivery ' . $token);
            $username = (string)($input['username'] ?? 'r43.shipping.' . strtolower($token) . '@example.test');
            /** @var FrontendUser $user */
            $user = r43_shipping_model(FrontendUser::class);
            $user->load(FrontendUser::schema_fields_username, $username);
            if ($user->getId() && (int)$user->getId() > 1) ObjectManager::getInstance(FrontendUserAdministrationInterface::class)->delete((int)$user->getId());
        })(),
        'region' => r43_shipping_delete(Region::class, Region::schema_fields_REGION_CODE, $code),
        'zone' => r43_shipping_delete(Zone::class, Zone::schema_fields_ZONE_CODE, $code),
        'carrier' => r43_shipping_delete(Carrier::class, Carrier::schema_fields_CARRIER_CODE, $code),
        'rate' => r43_shipping_delete(RateTemplate::class, RateTemplate::schema_fields_TEMPLATE_CODE, $code),
        'free' => r43_shipping_delete(FreeShippingRule::class, FreeShippingRule::schema_fields_RULE_CODE, $code),
        'service' => (function () use ($code): void {
            r43_shipping_delete(ShippingService::class, ShippingService::schema_fields_SERVICE_CODE, $code);
            r43_shipping_delete(Carrier::class, Carrier::schema_fields_CARRIER_CODE, $code . '_C');
            r43_shipping_delete(Zone::class, Zone::schema_fields_ZONE_CODE, $code . '_Z');
        })(),
        default => throw new InvalidArgumentException('unknown_shipping_case:' . $case),
    };
}

try {
    $input = r43_shipping_input();
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $case = strtolower(trim((string)($input['case'] ?? '')));
    $isolation = r43_shipping_assert_isolated_pgsql();
    $connector = $isolation['connector'];
    $database = $isolation['database'];
    $token = strtoupper((string)($input['token'] ?? ''));
    if ($action === 'prepare') {
        $token = strtoupper(bin2hex(random_bytes(6)));
        r43_shipping_cleanup($case, $token, []);
        $result = ['ok' => true, 'connector' => $connector, 'database' => $database, 'token' => $token, 'code' => 'R43_' . $token];
        if ($case === 'delivery') {
            $username = 'r43.shipping.' . strtolower($token) . '@example.test';
            $result['username'] = $username;
            $result['customer_id'] = r43_shipping_customer($username);
        }
        if ($case === 'service') {
            /** @var Carrier $carrier */
            $carrier = r43_shipping_model(Carrier::class);
            $carrier->setData([
                Carrier::schema_fields_CARRIER_CODE => $result['code'] . '_C', Carrier::schema_fields_CARRIER_NAME => 'R43 Service Carrier ' . $token,
                Carrier::schema_fields_CARRIER_TYPE => Carrier::TYPE_MANUAL, Carrier::schema_fields_TRACKING_URL_TEMPLATE => 'https://example.test/track/{tracking_number}',
                Carrier::schema_fields_TRACKING_API_METHOD => 'GET', Carrier::schema_fields_TRACKING_SUPPORT_STATUS => Carrier::TRACKING_SUPPORTED,
                Carrier::schema_fields_IS_ACTIVE => 1, Carrier::schema_fields_SORT_ORDER => 0,
            ])->save();
            /** @var Zone $zone */
            $zone = r43_shipping_model(Zone::class);
            $zone->setData([Zone::schema_fields_ZONE_NAME => 'R43 Service Zone ' . $token, Zone::schema_fields_ZONE_CODE => $result['code'] . '_Z', Zone::schema_fields_IS_ACTIVE => 1, Zone::schema_fields_SORT_ORDER => 0])->save();
            $result['carrier_id'] = (int)$carrier->getId();
            $result['zone_id'] = (int)$zone->getId();
        }
        r43_shipping_output($result);
    }
    if ($action === 'inspect') {
        $code = 'R43_' . $token;
        $row = match ($case) {
            'address' => r43_shipping_find(ShippingAddress::class, ShippingAddress::schema_fields_NAME, 'R43 Shipping ' . $token),
            'delivery' => r43_shipping_find(DeliveryAddress::class, DeliveryAddress::schema_fields_NAME, 'R43 Delivery ' . $token),
            'region' => r43_shipping_find(Region::class, Region::schema_fields_REGION_CODE, $code),
            'zone' => r43_shipping_find(Zone::class, Zone::schema_fields_ZONE_CODE, $code),
            'carrier' => r43_shipping_find(Carrier::class, Carrier::schema_fields_CARRIER_CODE, $code),
            'rate' => r43_shipping_find(RateTemplate::class, RateTemplate::schema_fields_TEMPLATE_CODE, $code),
            'free' => r43_shipping_find(FreeShippingRule::class, FreeShippingRule::schema_fields_RULE_CODE, $code),
            'service' => r43_shipping_find(ShippingService::class, ShippingService::schema_fields_SERVICE_CODE, $code),
            default => throw new InvalidArgumentException('unknown_shipping_case:' . $case),
        };
        r43_shipping_output(['ok' => $row !== null, 'connector' => $connector, 'row_id' => $row === null ? 0 : (int)reset($row), 'persisted' => $row !== null], $row !== null ? 0 : 1);
    }
    if ($action === 'cleanup') {
        r43_shipping_cleanup($case, $token, $input);
        r43_shipping_output(['ok' => true, 'connector' => $connector, 'cleaned' => true]);
    }
    throw new InvalidArgumentException('unknown_action:' . $action);
} catch (Throwable $throwable) {
    r43_shipping_output(['ok' => false, 'error' => $throwable->getMessage()], 1);
}
