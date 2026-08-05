<?php

declare(strict_types=1);

use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\FakeProvider;
use Weline\Payment\Model\PaymentMethod;
use Weline\Payment\Model\PaymentTransaction;
use Weline\Payment\Service\PaymentMethodManager;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

/** @return array<string,mixed> */
function r43_payment_input(): array
{
    $decoded = json_decode((string)file_get_contents('php://stdin'), true);
    if (!is_array($decoded) || array_is_list($decoded)) throw new InvalidArgumentException('stdin_must_be_json_object');
    return $decoded;
}

/** @param array<string,mixed> $payload */
function r43_payment_output(array $payload, int $code = 0): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit($code);
}

function r43_payment_model(string $class): object
{
    return ObjectManager::getInstance($class, [], false);
}

/** @return array{connector:string,database:string} */
function r43_payment_assert_isolated_pgsql(): array
{
    if (getenv('WELINE_E2E_ISOLATED_DB') !== '1') throw new RuntimeException('r43_payment_requires_isolated_database_opt_in');
    /** @var PaymentMethod $model */
    $model = r43_payment_model(PaymentMethod::class);
    $connector = get_class($model->getConnection()->getConnector());
    if (!str_contains(strtolower($connector), 'pgsql') && !str_contains(strtolower($connector), 'postgres')) {
        throw new RuntimeException('r43_payment_requires_postgresql:' . $connector);
    }
    $database = (string)$model->getConnection()->getConnector()->getConfigProvider()->getDatabase();
    if (!str_starts_with($database, 'mig_clone_')) {
        throw new RuntimeException('r43_payment_requires_migration_clone:' . $database);
    }
    return ['connector' => $connector, 'database' => $database];
}

/** @return array<string,mixed>|null */
function r43_payment_method_row(): ?array
{
    /** @var PaymentMethod $method */
    $method = r43_payment_model(PaymentMethod::class);
    $method->load(PaymentMethod::schema_fields_CODE, 'fake_card');
    return $method->getId() ? $method->getData() : null;
}

function r43_payment_snapshot(?array $row): string
{
    $token = bin2hex(random_bytes(12));
    $file = sys_get_temp_dir() . '/weline-r43-payment-' . $token . '.json';
    file_put_contents($file, json_encode(['row' => $row], JSON_THROW_ON_ERROR), LOCK_EX);
    @chmod($file, 0600);
    return $token;
}

/** @return array<string,mixed>|null */
function r43_payment_read_snapshot(string $token): ?array
{
    if (preg_match('/^[a-f0-9]{24}$/D', $token) !== 1) throw new InvalidArgumentException('invalid_snapshot_token');
    $file = sys_get_temp_dir() . '/weline-r43-payment-' . $token . '.json';
    $decoded = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    return is_array($decoded['row'] ?? null) ? $decoded['row'] : null;
}

function r43_payment_delete_method(string $code = 'fake_card'): void
{
    /** @var PaymentMethod $method */
    $method = r43_payment_model(PaymentMethod::class);
    $method->where(PaymentMethod::schema_fields_CODE, $code)->delete()->fetch();
}

function r43_payment_restore(string $token): void
{
    $row = r43_payment_read_snapshot($token);
    r43_payment_delete_method();
    if ($row !== null) {
        /** @var PaymentMethod $method */
        $method = r43_payment_model(PaymentMethod::class);
        $method->setData($row)->save();
    }
    @unlink(sys_get_temp_dir() . '/weline-r43-payment-' . $token . '.json');
}

try {
    $input = r43_payment_input();
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $isolation = r43_payment_assert_isolated_pgsql();
    $connector = $isolation['connector'];
    $database = $isolation['database'];

    if ($action === 'prepare_method') {
        $snapshot = r43_payment_snapshot(r43_payment_method_row());
        r43_payment_delete_method();
        r43_payment_output(['ok' => true, 'connector' => $connector, 'database' => $database, 'snapshot_token' => $snapshot]);
    }
    if ($action === 'inspect_method') {
        $row = r43_payment_method_row();
        $ok = is_array($row)
            && ($row[PaymentMethod::schema_fields_CODE] ?? '') === 'fake_card'
            && ($row[PaymentMethod::schema_fields_PROVIDER_CLASS] ?? '') === FakeProvider::class;
        r43_payment_output(['ok' => $ok, 'connector' => $connector, 'code' => $row[PaymentMethod::schema_fields_CODE] ?? null, 'provider_class' => $row[PaymentMethod::schema_fields_PROVIDER_CLASS] ?? null], $ok ? 0 : 1);
    }
    if ($action === 'cleanup_method') {
        r43_payment_restore((string)($input['snapshot_token'] ?? ''));
        r43_payment_output(['ok' => true, 'connector' => $connector, 'restored' => true]);
    }
    if ($action === 'prepare_transaction') {
        $snapshot = r43_payment_snapshot(r43_payment_method_row());
        /** @var PaymentMethodManager $manager */
        $manager = ObjectManager::getInstance(PaymentMethodManager::class);
        if ($manager->registerAllProviders() < 1) throw new RuntimeException('fake_provider_not_registered');
        /** @var PaymentMethod $method */
        $method = r43_payment_model(PaymentMethod::class);
        $method->load(PaymentMethod::schema_fields_CODE, 'fake_card');
        if (!$method->getId()) throw new RuntimeException('fake_method_missing_after_registration');
        $method->setData(PaymentMethod::schema_fields_IS_ACTIVE, 1)->save();
        $token = 'R43PAY' . strtoupper(bin2hex(random_bytes(6)));
        $now = gmdate('Y-m-d H:i:s');
        /** @var PaymentTransaction $transaction */
        $transaction = r43_payment_model(PaymentTransaction::class);
        $transaction->setData(PaymentTransaction::schema_fields_ORDER_ID, 'R43-ORDER-' . $token)
            ->setData(PaymentTransaction::schema_fields_METHOD_CODE, 'fake_card')
            ->setData(PaymentTransaction::schema_fields_TRANSACTION_NO, $token)
            ->setData(PaymentTransaction::schema_fields_AMOUNT, '12.34')
            ->setData(PaymentTransaction::schema_fields_CURRENCY, 'CNY')
            ->setData(PaymentTransaction::schema_fields_STATUS, PaymentTransaction::STATUS_PENDING)
            ->setData(PaymentTransaction::schema_fields_SCOPE, 'default.default.default')
            ->setData(PaymentTransaction::schema_fields_CREATED_AT, $now)
            ->setData(PaymentTransaction::schema_fields_UPDATED_AT, $now)
            ->setRequestData(['fixture' => 'CK-R43-PAYMENT-003', 'sandbox' => true])
            ->save();
        r43_payment_output(['ok' => true, 'connector' => $connector, 'database' => $database, 'snapshot_token' => $snapshot, 'transaction_no' => $token, 'transaction_id' => (int)$transaction->getId()]);
    }
    if ($action === 'inspect_transaction') {
        /** @var PaymentTransaction $transaction */
        $transaction = r43_payment_model(PaymentTransaction::class);
        $transaction->load(PaymentTransaction::schema_fields_TRANSACTION_NO, (string)($input['transaction_no'] ?? ''));
        $status = (string)$transaction->getData(PaymentTransaction::schema_fields_STATUS);
        $paidAt = (string)$transaction->getData(PaymentTransaction::schema_fields_PAID_AT);
        $ok = $transaction->getId() && $status === PaymentTransaction::STATUS_SUCCESS && $paidAt !== '';
        r43_payment_output(['ok' => (bool)$ok, 'connector' => $connector, 'status' => $status, 'paid_at_present' => $paidAt !== ''], $ok ? 0 : 1);
    }
    if ($action === 'cleanup_transaction') {
        /** @var PaymentTransaction $transaction */
        $transaction = r43_payment_model(PaymentTransaction::class);
        $transaction->where(PaymentTransaction::schema_fields_TRANSACTION_NO, (string)($input['transaction_no'] ?? ''))->delete()->fetch();
        r43_payment_restore((string)($input['snapshot_token'] ?? ''));
        r43_payment_output(['ok' => true, 'connector' => $connector, 'cleaned' => true]);
    }
    throw new InvalidArgumentException('unknown_action:' . $action);
} catch (Throwable $throwable) {
    r43_payment_output(['ok' => false, 'error' => $throwable->getMessage()], 1);
}
