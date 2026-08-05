<?php

declare(strict_types=1);

/**
 * TASK-MIG-P3B full-clone fixture.
 *
 * stdin:
 * - {"action":"seed","database":"mig_clone_*","count":100}
 * - {"action":"inspect","database":"mig_clone_*","count":100}
 *
 * The fixture binds only a migration-registry clone. It never accepts the
 * configured source database and stores no customer identity.
 */

use Weline\Checkout\Model\CheckoutSession;
use Weline\Checkout\Service\OrmCheckoutSessionStore;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Migration\Service\MigrationTargetBinder;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\ConfigReader;
use Weline\SystemConfig\Api\ConfigStore;
use Weline\Tax\Api\TaxShadowQuoteSourceInterface;
use Weline\Tax\Model\TaxClass;
use Weline\Tax\Model\TaxRule;
use Weline\Tax\Service\TaxEngine;
use Weline\Tax\Service\TaxScopeConfig;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

/** @return array<string,mixed> */
function mig_p3b_input(): array
{
    $raw = stream_get_contents(STDIN);
    $data = json_decode($raw !== false && trim($raw) !== '' ? $raw : '{}', true);

    return is_array($data) ? $data : [];
}

/** @param array<string,mixed> $payload */
function mig_p3b_output(array $payload, int $exitCode = 0): never
{
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ), "\n";
    exit($exitCode);
}

/** @return array<string,mixed> */
function mig_p3b_target(string $database): array
{
    if (preg_match('/^mig_clone_[a-z0-9_]+$/D', $database) !== 1) {
        throw new RuntimeException('mig_p3b_fixture_clone_required');
    }
    $env = include BP . 'app/etc/env.php';
    $db = is_array($env) ? ($env['db']['master'] ?? $env['db'] ?? []) : [];
    if (!is_array($db)) {
        $db = [];
    }

    return [
        'type' => (string)($db['type'] ?? 'pgsql'),
        'hostname' => (string)($db['hostname'] ?? '127.0.0.1'),
        'hostport' => (string)($db['hostport'] ?? '5432'),
        'database' => $database,
        'username' => (string)($db['username'] ?? ''),
        'password' => (string)($db['password'] ?? ''),
        'prefix' => (string)($db['prefix'] ?? ''),
    ];
}

/** @return array<string,mixed> */
function mig_p3b_payload(string $prefix, int $index): array
{
    $lineId = $prefix . 'line-' . $index;
    $token = $prefix . 'quote-' . $index;
    $payload = [
        'quote_token' => $token,
        'state' => CheckoutSession::STATE_QUOTED,
        'currency' => 'CNY',
        'config_version' => 'mig-p3b',
        'scope' => [
            'website_id' => 0,
            'store_id' => 1,
            'channel_id' => 1,
        ],
        'address' => [
            'country' => 'CN',
            'region' => '',
        ],
        'service_code' => 'mig-p3b',
        'orders' => [[
            'split_key' => 'mig-p3b',
            'items' => [[
                'line_uuid' => $lineId,
                'tax_class_code' => 'standard',
                'row_total_minor' => 1000 + ($index * 17),
            ]],
        ]],
        'allocation' => [],
        'quote' => [],
        'tax' => [
            'mode' => 'none',
            'engine' => 'none',
            'tax_amount_minor' => 0,
            'note' => 'mig_p3b_read_only_fact',
        ],
    ];
    $payload['request_hash'] = hash(
        'sha256',
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    );

    return $payload;
}

function mig_p3b_seed_tax_rules(): void
{
    $config = ConfigStore::forConnection(ConnectionFactory::getInstance());
    foreach (
        [
            TaxScopeConfig::KEY_ENABLED => true,
            TaxScopeConfig::KEY_DEFAULT_JURISDICTION => 'CN|',
            TaxScopeConfig::KEY_SCHEMA_VERSION => TaxEngine::SCHEMA_VERSION,
            TaxScopeConfig::KEY_ROUNDING => TaxRule::ROUNDING_HALF_UP,
        ] as $key => $value
    ) {
        if (!$config->setScopedConfig(
            $key,
            $value,
            TaxScopeConfig::MODULE,
            TaxScopeConfig::AREA,
            ConfigReader::SCOPE_GLOBAL,
            ConfigReader::LOCALE_DEFAULT,
        )) {
            throw new RuntimeException('mig_p3b_fixture_config_write_failed:' . $key);
        }
    }

    $class = (new TaxClass())
        ->clear()
        ->where(TaxClass::schema_fields_WEBSITE_ID, 0)
        ->where(TaxClass::schema_fields_CLASS_CODE, 'standard')
        ->find()
        ->fetch();
    if (!$class instanceof TaxClass) {
        $class = new TaxClass();
    }
    $class->setData([
        TaxClass::schema_fields_WEBSITE_ID => 0,
        TaxClass::schema_fields_CLASS_CODE => 'standard',
        TaxClass::schema_fields_NAME => 'MIG-P3B standard',
        TaxClass::schema_fields_ENABLED => 1,
        TaxClass::schema_fields_UPDATED_AT => gmdate('Y-m-d H:i:s'),
    ])->save();

    $rule = (new TaxRule())
        ->clear()
        ->where(TaxRule::schema_fields_WEBSITE_ID, 0)
        ->where(TaxRule::schema_fields_CLASS_CODE, 'standard')
        ->where(TaxRule::schema_fields_JURISDICTION_KEY, 'CN|')
        ->where(TaxRule::schema_fields_RULE_VERSION, 1)
        ->find()
        ->fetch();
    if (!$rule instanceof TaxRule) {
        $rule = new TaxRule();
    }
    $rule->setData([
        TaxRule::schema_fields_WEBSITE_ID => 0,
        TaxRule::schema_fields_CLASS_CODE => 'standard',
        TaxRule::schema_fields_JURISDICTION_KEY => 'CN|',
        TaxRule::schema_fields_RATE_BPS => 1300,
        TaxRule::schema_fields_RULE_VERSION => 1,
        TaxRule::schema_fields_ROUNDING => TaxRule::ROUNDING_HALF_UP,
        TaxRule::schema_fields_ENABLED => 1,
        TaxRule::schema_fields_UPDATED_AT => gmdate('Y-m-d H:i:s'),
    ])->save();
}

try {
    $input = mig_p3b_input();
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $database = strtolower(trim((string)($input['database'] ?? '')));
    $count = (int)($input['count'] ?? 100);
    if (!in_array($action, ['seed', 'inspect'], true)) {
        throw new InvalidArgumentException('mig_p3b_fixture_action_invalid');
    }
    if ($count < 100 || $count > 1000) {
        throw new InvalidArgumentException('mig_p3b_fixture_count_invalid');
    }

    $target = mig_p3b_target($database);
    (new MigrationTargetBinder())->bindIsolated($target);
    $prefix = 'migp3b_' . substr(hash('sha256', $database), 0, 10) . '_';

    if ($action === 'seed') {
        mig_p3b_seed_tax_rules();
        $store = new OrmCheckoutSessionStore();
        for ($index = 0; $index < $count; $index++) {
            $payload = mig_p3b_payload($prefix, $index);
            $store->put((string)$payload['quote_token'], $payload, '2099-12-31 23:59:59');
        }
    }

    $source = ObjectManager::create(TaxShadowQuoteSourceInterface::class, [], false);
    if (!$source instanceof TaxShadowQuoteSourceInterface) {
        throw new RuntimeException('tax_shadow_quote_source_unavailable');
    }
    $window = $source->observationWindow(0, 1, 1, $count);
    mig_p3b_output([
        'ok' => count($window['requests']) === $count,
        'action' => $action,
        'database' => $database,
        'requested_count' => $count,
        'sample_count' => count($window['requests']),
        'scanned_count' => $window['scanned_count'],
        'rejected_count' => $window['rejected_count'],
        'duplicate_count' => $window['duplicate_count'],
        'first_request_hash' => $window['request_hashes'][0] ?? null,
        'last_request_hash' => $window['request_hashes'][$count - 1] ?? null,
        'contains_customer_identity' => array_filter(
            $window['requests'],
            static fn (array $request): bool => isset($request['customer_id'])
                || isset($request['address'])
                || isset($request['cart_hash']),
        ) !== [],
    ], count($window['requests']) === $count ? 0 : 2);
} catch (Throwable $exception) {
    mig_p3b_output([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], 2);
}
