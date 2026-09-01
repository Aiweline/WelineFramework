<?php

declare(strict_types=1);

namespace Weline\DataTable\Test\Integration;

use Weline\DataTable\Model\TestOrder;
use Weline\DataTable\Model\TestUser;
use Weline\DataTable\Service\DemoTableService;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;

final class CompositeWriteRuntimeTest extends TestCore
{
    private DemoTableService $service;

    /** @var list<string> */
    private array $testEmails = [];

    private bool $createdUsersTable = false;

    private bool $createdOrdersTable = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSandboxTables();
        $this->service = new DemoTableService();
    }

    protected function tearDown(): void
    {
        foreach ($this->testEmails as $email) {
            foreach ($this->findUsersByEmail($email) as $user) {
                $id = $user['id'] ?? null;
                if ($id === null || $id === '') {
                    continue;
                }

                try {
                    $orders = $this->service->getTableData([
                        'model' => TestOrder::class,
                        'pageSize' => 100,
                    ]);
                    foreach (($orders['data'] ?? []) as $order) {
                        if ((string)($order['user_id'] ?? '') !== (string)$id) {
                            continue;
                        }
                        $this->deleteModel(TestOrder::class, $order['id'] ?? null);
                    }
                    $this->deleteModel(TestUser::class, $id);
                } catch (\Throwable) {
                    // Preserve the original assertion or runtime failure.
                }
            }
        }

        try {
            if ($this->createdOrdersTable) {
                $this->connector()->query('DROP TABLE IF EXISTS datatable_test_orders')->fetch();
            }
            if ($this->createdUsersTable) {
                $this->connector()->query('DROP TABLE IF EXISTS datatable_test_users')->fetch();
            }
        } catch (\Throwable) {
            // Preserve the original assertion or runtime failure.
        }

        parent::tearDown();
    }

    public function testCompositeWriteUsesConfirmedPayloadOnceAndRollsBackOnSecondStepFailure(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $model = TestUser::class . ' as u, ' . TestOrder::class . ' as o';

        $successEmail = 'datatable-runtime-' . $suffix . '@example.test';
        $successOrderNo = 'DT-RUNTIME-' . strtoupper($suffix);
        $successParams = $this->compositeParams($model, $successEmail, $successOrderNo);
        $this->testEmails[] = $successEmail;

        $preview = $this->service->previewWrite($successParams);
        self::assertSame('datatable.write-plan.v1', $preview['schema_version']);
        self::assertTrue($preview['can_proceed']);
        self::assertTrue($preview['transaction']);
        self::assertSame(['u', 'o'], $preview['models']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string)$preview['plan_token']);
        self::assertSame('u.id', $preview['steps'][1]['depends_on']['user_id']);

        $result = $this->service->executeWrite($successParams + [
            'plan_token' => $preview['plan_token'],
        ]);
        $savedUser = $result['record']['u'] ?? [];
        $savedOrder = $result['record']['o'] ?? [];
        self::assertNotEmpty($savedUser['id'] ?? null);
        self::assertNotEmpty($savedOrder['id'] ?? null);
        self::assertSame((string)$savedUser['id'], (string)($savedOrder['user_id'] ?? ''));
        self::assertSame($successOrderNo, $savedOrder['order_no'] ?? null);

        $reuseRejected = false;
        try {
            $this->service->executeWrite($successParams + [
                'plan_token' => $preview['plan_token'],
            ]);
        } catch (\Throwable) {
            $reuseRejected = true;
        }
        self::assertTrue($reuseRejected, 'A consumed plan token must not execute twice.');

        $mismatchEmail = 'datatable-mismatch-' . $suffix . '@example.test';
        $mismatchParams = $this->compositeParams(
            $model,
            $mismatchEmail,
            'DT-MISMATCH-' . strtoupper($suffix)
        );
        $this->testEmails[] = $mismatchEmail;
        $mismatchPreview = $this->service->previewWrite($mismatchParams);
        $changedParams = $mismatchParams;
        $changedParams['data']['u']['name'] = 'Changed after preview';

        $mismatchRejected = false;
        try {
            $this->service->executeWrite($changedParams + [
                'plan_token' => $mismatchPreview['plan_token'],
            ]);
        } catch (\Throwable) {
            $mismatchRejected = true;
        }
        self::assertTrue($mismatchRejected, 'A changed payload must not execute under the confirmed token.');
        self::assertSame([], $this->findUsersByEmail($mismatchEmail));

        $rollbackEmail = 'datatable-rollback-' . $suffix . '@example.test';
        $rollbackParams = $this->compositeParams($model, $rollbackEmail, $successOrderNo);
        $this->testEmails[] = $rollbackEmail;
        $rollbackPreview = $this->service->previewWrite($rollbackParams);

        try {
            $this->service->executeWrite($rollbackParams + [
                'plan_token' => $rollbackPreview['plan_token'],
            ]);
            self::fail('The duplicate order number must fail the second write step.');
        } catch (\Throwable $exception) {
            self::assertNotSame('', trim($exception->getMessage()));
        }
        self::assertSame(
            [],
            $this->findUsersByEmail($rollbackEmail),
            'The user inserted before the failed order must be rolled back.'
        );
    }

    /** @return array<string,mixed> */
    private function compositeParams(string $model, string $email, string $orderNo): array
    {
        return [
            'model' => $model,
            'data' => [
                'u' => [
                    'name' => 'DataTable runtime acceptance',
                    'email' => $email,
                ],
                'o' => [
                    'order_no' => $orderNo,
                ],
            ],
            'dependencies' => 'u.id->o.user_id',
            'transaction' => true,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function findUsersByEmail(string $email): array
    {
        $result = $this->service->getTableData([
            'model' => TestUser::class,
            'search' => $email,
            'pageSize' => 100,
        ]);

        return array_values(array_filter(
            is_array($result['data'] ?? null) ? $result['data'] : [],
            static fn (array $row): bool => (string)($row['email'] ?? '') === $email
        ));
    }

    private function ensureSandboxTables(): void
    {
        $connector = $this->connector();
        if (!$connector->tableExist(TestUser::schema_table)) {
            $connector->query(
                'CREATE TABLE datatable_test_users ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'name VARCHAR(100) NOT NULL, '
                . 'email VARCHAR(255) NOT NULL UNIQUE'
                . ')'
            )->fetch();
            $this->createdUsersTable = true;
        }
        if (!$connector->tableExist(TestOrder::schema_table)) {
            $connector->query(
                'CREATE TABLE datatable_test_orders ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'order_no VARCHAR(50) NOT NULL UNIQUE, '
                . 'user_id INTEGER NOT NULL'
                . ')'
            )->fetch();
            $this->createdOrdersTable = true;
        }
    }

    private function connector(): ConnectorInterface
    {
        /** @var ConnectionFactory $factory */
        $factory = ObjectManager::getInstance(ConnectionFactory::class);
        return $factory->getConnector();
    }

    /** @param class-string<Model> $modelClass */
    private function deleteModel(string $modelClass, mixed $id): void
    {
        if ($id === null || $id === '') {
            return;
        }
        /** @var Model $model */
        $model = ObjectManager::make($modelClass);
        $model->load($id);
        if ($model->getId()) {
            $model->delete();
        }
    }
}
