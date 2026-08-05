<?php

declare(strict_types=1);

use Weline\Currency\Model\Config;
use Weline\Currency\Model\Currency;
use Weline\Currency\Model\Currency\LocalDescription;
use Weline\Framework\Manager\ObjectManager;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

/** @return array<string,mixed> */
function r43_currency_input(): array
{
    $decoded = json_decode((string)file_get_contents('php://stdin'), true);
    if (!is_array($decoded) || array_is_list($decoded)) throw new InvalidArgumentException('stdin_must_be_json_object');
    return $decoded;
}

/** @param array<string,mixed> $payload */
function r43_currency_output(array $payload, int $code = 0): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit($code);
}

function r43_currency_model(string $class): object
{
    return ObjectManager::getInstance($class, [], false);
}

/** @return array{connector:string,database:string} */
function r43_currency_assert_isolated_pgsql(): array
{
    if (getenv('WELINE_E2E_ISOLATED_DB') !== '1') throw new RuntimeException('r43_currency_requires_isolated_database_opt_in');
    /** @var Currency $model */
    $model = r43_currency_model(Currency::class);
    $connector = get_class($model->getConnection()->getConnector());
    if (!str_contains(strtolower($connector), 'pgsql') && !str_contains(strtolower($connector), 'postgres')) throw new RuntimeException('r43_currency_requires_postgresql:' . $connector);
    $database = (string)$model->getConnection()->getConnector()->getConfigProvider()->getDatabase();
    if (!str_starts_with($database, 'mig_clone_')) throw new RuntimeException('r43_currency_requires_migration_clone:' . $database);
    return ['connector' => $connector, 'database' => $database];
}

function r43_currency_unused_code(): string
{
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $code = 'X' . chr(65 + random_int(0, 25)) . chr(65 + random_int(0, 25));
        /** @var Currency $model */
        $model = r43_currency_model(Currency::class);
        $model->load(Currency::schema_fields_CODE, $code);
        if (!$model->getId()) return $code;
    }
    throw new RuntimeException('r43_currency_code_exhausted');
}

function r43_currency_cleanup_code(string $code): void
{
    if (preg_match('/^X[A-Z]{2}$/D', $code) !== 1) throw new InvalidArgumentException('refusing_non_r43_currency_cleanup');
    /** @var Currency $currency */
    $currency = r43_currency_model(Currency::class);
    $currency->load(Currency::schema_fields_CODE, $code);
    if (!$currency->getId()) return;
    $id = (int)$currency->getId();
    /** @var LocalDescription $local */
    $local = r43_currency_model(LocalDescription::class);
    $local->where(LocalDescription::schema_fields_ID, $id)->delete()->fetch();
    $currency->delete()->fetch();
}

try {
    $input = r43_currency_input();
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $isolation = r43_currency_assert_isolated_pgsql();
    $connector = $isolation['connector'];
    $database = $isolation['database'];
    if ($action === 'prepare_currency') {
        $code = r43_currency_unused_code();
        r43_currency_cleanup_code($code);
        r43_currency_output(['ok' => true, 'connector' => $connector, 'database' => $database, 'code' => $code, 'name' => 'R43 Currency ' . $code]);
    }
    if ($action === 'inspect_currency') {
        $code = strtoupper((string)($input['code'] ?? ''));
        /** @var Currency $currency */
        $currency = r43_currency_model(Currency::class);
        $currency->load(Currency::schema_fields_CODE, $code);
        $ok = $currency->getId()
            && (string)$currency->getData(Currency::schema_fields_NAME) === (string)($input['name'] ?? '')
            && (float)$currency->getData(Currency::schema_fields_RATE) === 1.2345;
        r43_currency_output(['ok' => (bool)$ok, 'connector' => $connector, 'currency_id' => (int)$currency->getId(), 'code' => $code, 'rate' => (float)$currency->getData(Currency::schema_fields_RATE)], $ok ? 0 : 1);
    }
    if ($action === 'cleanup_currency') {
        r43_currency_cleanup_code(strtoupper((string)($input['code'] ?? '')));
        r43_currency_output(['ok' => true, 'connector' => $connector, 'cleaned' => true]);
    }
    /** @var Config $config */
    $config = ObjectManager::getInstance(Config::class);
    if ($action === 'prepare_config') {
        $mode = $config->getRateMode();
        r43_currency_output([
            'ok' => true,
            'connector' => $connector,
            'database' => $database,
            'original_mode' => $mode,
            'original_import_enabled' => $config->isImportEnabled(),
            'target_mode' => $mode === Config::RATE_MODE_AUTO ? Config::RATE_MODE_MANUAL : Config::RATE_MODE_AUTO,
        ]);
    }
    if ($action === 'inspect_config') {
        $expected = (string)($input['expected_mode'] ?? '');
        $mode = $config->getRateMode();
        $ok = $mode === $expected && $config->isImportEnabled() === false;
        r43_currency_output(['ok' => $ok, 'connector' => $connector, 'mode' => $mode, 'import_enabled' => $config->isImportEnabled()], $ok ? 0 : 1);
    }
    if ($action === 'cleanup_config') {
        $mode = (string)($input['original_mode'] ?? Config::RATE_MODE_MANUAL);
        $enabled = !empty($input['original_import_enabled']);
        $config->setRateMode($mode);
        $config->setImportEnabled($enabled);
        $ok = $config->getRateMode() === $mode && $config->isImportEnabled() === $enabled;
        r43_currency_output(['ok' => $ok, 'connector' => $connector, 'restored_mode' => $config->getRateMode(), 'restored_import_enabled' => $config->isImportEnabled()], $ok ? 0 : 1);
    }
    throw new InvalidArgumentException('unknown_action:' . $action);
} catch (Throwable $throwable) {
    r43_currency_output(['ok' => false, 'error' => $throwable->getMessage()], 1);
}
