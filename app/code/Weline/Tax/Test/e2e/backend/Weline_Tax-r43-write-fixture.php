<?php

declare(strict_types=1);

use Weline\Framework\Manager\ObjectManager;
use Weline\Tax\Model\TaxClass;
use Weline\Tax\Model\TaxRule;
use Weline\Tax\Service\TaxConfigurationAdminService;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

/** @return array<string,mixed> */
function r43_tax_input(): array
{
    $decoded = json_decode((string)file_get_contents('php://stdin'), true);
    if (!is_array($decoded) || array_is_list($decoded)) throw new InvalidArgumentException('stdin_must_be_json_object');
    return $decoded;
}

/** @param array<string,mixed> $payload */
function r43_tax_output(array $payload, int $code = 0): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit($code);
}

/** @template T of object @param class-string<T> $class @return T */
function r43_tax_model(string $class): object
{
    return ObjectManager::getInstance($class, [], false);
}

/** @return array{connector:string,database:string} */
function r43_tax_assert_isolated_pgsql(): array
{
    if (getenv('WELINE_E2E_ISOLATED_DB') !== '1') throw new RuntimeException('r43_tax_requires_isolated_database_opt_in');
    /** @var TaxClass $model */
    $model = r43_tax_model(TaxClass::class);
    $connectorObject = $model->getConnection()->getConnector();
    $connector = get_class($connectorObject);
    if (!str_contains(strtolower($connector), 'pgsql') && !str_contains(strtolower($connector), 'postgres')) throw new RuntimeException('r43_tax_requires_postgresql:' . $connector);
    $database = (string)$connectorObject->getConfigProvider()->getDatabase();
    if (!str_starts_with($database, 'mig_clone_')) throw new RuntimeException('r43_tax_requires_migration_clone:' . $database);
    return ['connector' => $connector, 'database' => $database];
}

function r43_tax_token(mixed $value): string
{
    $token = strtoupper(trim((string)$value));
    if (preg_match('/^[A-F0-9]{12}$/D', $token) !== 1) throw new InvalidArgumentException('invalid_r43_tax_token');
    return $token;
}

function r43_tax_class_code(string $token): string
{
    return 'r43_' . strtolower($token);
}

function r43_tax_cleanup(string $token): void
{
    $classCode = r43_tax_class_code($token);
    /** @var TaxRule $rules */
    $rules = r43_tax_model(TaxRule::class);
    $rules->where(TaxRule::schema_fields_WEBSITE_ID, 0)->where(TaxRule::schema_fields_CLASS_CODE, $classCode)->delete()->fetch();
    /** @var TaxClass $classes */
    $classes = r43_tax_model(TaxClass::class);
    $classes->where(TaxClass::schema_fields_WEBSITE_ID, 0)->where(TaxClass::schema_fields_CLASS_CODE, $classCode)->delete()->fetch();
}

/** @return array<string,mixed>|null */
function r43_tax_class_row(string $classCode): ?array
{
    /** @var TaxClass $class */
    $class = r43_tax_model(TaxClass::class);
    $class->where(TaxClass::schema_fields_WEBSITE_ID, 0)->where(TaxClass::schema_fields_CLASS_CODE, $classCode)->find()->fetch();
    return $class->getId() ? $class->getData() : null;
}

/** @return array<string,mixed>|null */
function r43_tax_rule_row(string $classCode, string $jurisdiction, int $version): ?array
{
    /** @var TaxRule $rule */
    $rule = r43_tax_model(TaxRule::class);
    $rule->where(TaxRule::schema_fields_WEBSITE_ID, 0)
        ->where(TaxRule::schema_fields_CLASS_CODE, $classCode)
        ->where(TaxRule::schema_fields_JURISDICTION_KEY, $jurisdiction)
        ->where(TaxRule::schema_fields_RULE_VERSION, $version)->find()->fetch();
    return $rule->getId() ? $rule->getData() : null;
}

try {
    $input = r43_tax_input();
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $isolation = r43_tax_assert_isolated_pgsql();
    $base = ['connector' => $isolation['connector'], 'database' => $isolation['database']];

    if (str_starts_with($action, 'prepare_')) {
        $kind = substr($action, strlen('prepare_'));
        if (!in_array($kind, ['class', 'rate', 'rule'], true)) throw new InvalidArgumentException('unknown_tax_prepare:' . $kind);
        $token = strtoupper(bin2hex(random_bytes(6)));
        $classCode = r43_tax_class_code($token);
        r43_tax_cleanup($token);
        if ($kind !== 'class') {
            /** @var TaxConfigurationAdminService $admin */
            $admin = ObjectManager::getInstance(TaxConfigurationAdminService::class);
            $admin->createClass(['website_id' => 0, 'class_code' => $classCode, 'name' => 'R43 Tax Prerequisite ' . $token, 'enabled' => 1]);
        }
        r43_tax_output([
            'ok' => true,
            ...$base,
            'token' => $token,
            'class_code' => $classCode,
            'class_name' => 'R43 Tax Class ' . $token,
            'jurisdiction_key' => ($kind === 'rule' ? 'US|' : 'CN|') . $token,
        ]);
    }
    if ($action === 'inspect_class') {
        $token = r43_tax_token($input['token'] ?? '');
        $row = r43_tax_class_row(r43_tax_class_code($token));
        $ok = $row !== null
            && (string)($row[TaxClass::schema_fields_NAME] ?? '') === 'R43 Tax Class ' . $token
            && (int)($row[TaxClass::schema_fields_ENABLED] ?? 0) === 1;
        r43_tax_output(['ok' => $ok, ...$base, 'tax_class_id' => (int)($row[TaxClass::schema_fields_ID] ?? 0)], $ok ? 0 : 1);
    }
    if ($action === 'inspect_rate' || $action === 'inspect_rule') {
        $kind = substr($action, strlen('inspect_'));
        $token = r43_tax_token($input['token'] ?? '');
        $jurisdiction = ($kind === 'rule' ? 'US|' : 'CN|') . $token;
        $version = $kind === 'rule' ? 7 : 1;
        $expectedRate = $kind === 'rule' ? 825 : 725;
        $row = r43_tax_rule_row(r43_tax_class_code($token), $jurisdiction, $version);
        $ok = $row !== null
            && (int)($row[TaxRule::schema_fields_RATE_BPS] ?? -1) === $expectedRate
            && (string)($row[TaxRule::schema_fields_ROUNDING] ?? '') === TaxRule::ROUNDING_HALF_UP
            && (int)($row[TaxRule::schema_fields_ENABLED] ?? 0) === 1;
        r43_tax_output(['ok' => $ok, ...$base, 'tax_rule_id' => (int)($row[TaxRule::schema_fields_ID] ?? 0), 'rate_bps' => (int)($row[TaxRule::schema_fields_RATE_BPS] ?? -1), 'rule_version' => (int)($row[TaxRule::schema_fields_RULE_VERSION] ?? 0)], $ok ? 0 : 1);
    }
    if ($action === 'cleanup') {
        $token = r43_tax_token($input['token'] ?? '');
        r43_tax_cleanup($token);
        r43_tax_output(['ok' => true, ...$base, 'cleaned' => true]);
    }
    throw new InvalidArgumentException('unknown_action:' . $action);
} catch (Throwable $throwable) {
    r43_tax_output(['ok' => false, 'error' => $throwable->getMessage()], 1);
}
