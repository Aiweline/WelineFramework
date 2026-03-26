<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiModel;
use Weline\Framework\Manager\ObjectManager;

class AiModelSchemaTest extends TestCase
{
    private AiModel $model;

    /** @var string[] */
    private array $tableColumns = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->model = ObjectManager::getInstance(AiModel::class);
        $columnsInfo = $this->model->columns();
        $this->tableColumns = array_column($columnsInfo, 'Field');
    }

    public function testTableExists(): void
    {
        $this->assertStringContainsString('ai', $this->model->getTable());
        $this->assertNotEmpty($this->tableColumns);
    }

    public function testAllFieldConstantsExist(): void
    {
        $requiredFields = [
            AiModel::schema_fields_ID,
            AiModel::schema_fields_SUPPLIER,
            AiModel::schema_fields_MODEL_CODE,
            AiModel::schema_fields_NAME,
            AiModel::schema_fields_VERSION,
            AiModel::schema_fields_IS_COPY,
            AiModel::schema_fields_ORIGIN_MODEL_ID,
            AiModel::schema_fields_CONFIG,
            AiModel::schema_fields_CAPABILITIES,
            AiModel::schema_fields_MAX_TOKENS,
            AiModel::schema_fields_COST_PER_TOKEN,
            AiModel::schema_fields_TOKEN_PRICE_INPUT,
            AiModel::schema_fields_TOKEN_PRICE_OUTPUT,
            AiModel::schema_fields_PROXY_INFO,
            AiModel::schema_fields_STATUS,
            AiModel::schema_fields_IS_ACTIVE,
            AiModel::schema_fields_IS_DEFAULT,
            AiModel::schema_fields_CREATED_AT,
            AiModel::schema_fields_UPDATED_AT,
        ];

        foreach ($requiredFields as $field) {
            $this->assertContains($field, $this->tableColumns, sprintf('Missing DB column: %s', $field));
        }
    }

    public function testCriticalFieldsExist(): void
    {
        foreach (['id', 'supplier', 'model_code', 'name'] as $field) {
            $this->assertContains($field, $this->tableColumns);
        }
    }

    public function testNewlyAddedFieldsExist(): void
    {
        foreach ([
            'token_price_input',
            'token_price_output',
            'proxy_info',
            'is_active',
            'is_default',
        ] as $field) {
            $this->assertContains($field, $this->tableColumns);
        }
    }

    public function testFieldConstantValuesMatchDatabaseColumns(): void
    {
        $fieldMap = [
            'schema_fields_ID' => 'id',
            'schema_fields_SUPPLIER' => 'supplier',
            'schema_fields_MODEL_CODE' => 'model_code',
            'schema_fields_TOKEN_PRICE_INPUT' => 'token_price_input',
            'schema_fields_TOKEN_PRICE_OUTPUT' => 'token_price_output',
            'schema_fields_IS_ACTIVE' => 'is_active',
            'schema_fields_IS_DEFAULT' => 'is_default',
        ];

        foreach ($fieldMap as $constantName => $expectedValue) {
            $actualValue = constant(AiModel::class . '::' . $constantName);
            $this->assertSame($expectedValue, $actualValue);
        }
    }

    public function testUniqueIndexExists(): void
    {
        $model1 = ObjectManager::getInstance(AiModel::class);
        $model1->clearData()->reset();

        $testSupplier = 'test-unique-supplier';
        $testModelCode = 'test-unique-model-' . uniqid('', false);

        $model1->setData(AiModel::schema_fields_SUPPLIER, $testSupplier)
            ->setData(AiModel::schema_fields_MODEL_CODE, $testModelCode)
            ->setData(AiModel::schema_fields_NAME, 'Unique Test 1')
            ->setData(AiModel::schema_fields_VERSION, '1.0.0')
            ->setData(AiModel::schema_fields_IS_COPY, 0)
            ->setData(AiModel::schema_fields_CONFIG, json_encode(['test' => true]))
            ->setData(AiModel::schema_fields_CAPABILITIES, json_encode(['chat' => true]))
            ->setData(AiModel::schema_fields_MAX_TOKENS, 4096)
            ->setData(AiModel::schema_fields_COST_PER_TOKEN, '0.0015')
            ->setData(AiModel::schema_fields_TOKEN_PRICE_INPUT, 0.03)
            ->setData(AiModel::schema_fields_TOKEN_PRICE_OUTPUT, 0.06)
            ->setData(AiModel::schema_fields_PROXY_INFO, null)
            ->setData(AiModel::schema_fields_STATUS, 'active')
            ->setData(AiModel::schema_fields_IS_ACTIVE, 1)
            ->setData(AiModel::schema_fields_IS_DEFAULT, 0)
            ->save();

        $this->assertNotEmpty($model1->getId());

        $model2 = ObjectManager::getInstance(AiModel::class);
        $model2->clearData()->reset();
        $model2->setData(AiModel::schema_fields_SUPPLIER, $testSupplier)
            ->setData(AiModel::schema_fields_MODEL_CODE, $testModelCode)
            ->setData(AiModel::schema_fields_NAME, 'Unique Test 2')
            ->setData(AiModel::schema_fields_VERSION, '2.0.0')
            ->setData(AiModel::schema_fields_IS_COPY, 0)
            ->setData(AiModel::schema_fields_CONFIG, json_encode(['test' => true]))
            ->setData(AiModel::schema_fields_CAPABILITIES, json_encode(['chat' => true]))
            ->setData(AiModel::schema_fields_MAX_TOKENS, 8192)
            ->setData(AiModel::schema_fields_COST_PER_TOKEN, '0.003')
            ->setData(AiModel::schema_fields_TOKEN_PRICE_INPUT, 0.05)
            ->setData(AiModel::schema_fields_TOKEN_PRICE_OUTPUT, 0.10)
            ->setData(AiModel::schema_fields_PROXY_INFO, null)
            ->setData(AiModel::schema_fields_STATUS, 'active')
            ->setData(AiModel::schema_fields_IS_ACTIVE, 1)
            ->setData(AiModel::schema_fields_IS_DEFAULT, 0);

        try {
            $model2->save();
            $this->fail('Expected duplicate model_code insert to fail.');
        } catch (\Exception $e) {
            $this->assertMatchesRegularExpression(
                '/duplicate key value violates unique constraint|UNIQUE constraint failed/i',
                $e->getMessage()
            );
        } finally {
            if ($model1->getId()) {
                $model1->delete()->fetch();
            }
            if ($model2->getId()) {
                $model2->delete()->fetch();
            }
        }
    }

    public function testCanCreateAndReadModelWithAllFields(): void
    {
        $testData = [
            AiModel::schema_fields_SUPPLIER => 'test-supplier',
            AiModel::schema_fields_MODEL_CODE => 'test-model-' . uniqid('', false),
            AiModel::schema_fields_NAME => '测试模型',
            AiModel::schema_fields_VERSION => '1.0.0',
            AiModel::schema_fields_IS_COPY => 0,
            AiModel::schema_fields_CONFIG => json_encode(['test' => true]),
            AiModel::schema_fields_CAPABILITIES => json_encode(['chat' => true]),
            AiModel::schema_fields_MAX_TOKENS => 4096,
            AiModel::schema_fields_COST_PER_TOKEN => '0.0015',
            AiModel::schema_fields_TOKEN_PRICE_INPUT => 0.03,
            AiModel::schema_fields_TOKEN_PRICE_OUTPUT => 0.06,
            AiModel::schema_fields_PROXY_INFO => json_encode(['host' => 'proxy.example.com']),
            AiModel::schema_fields_STATUS => 'active',
            AiModel::schema_fields_IS_ACTIVE => 1,
            AiModel::schema_fields_IS_DEFAULT => 0,
        ];

        $model = ObjectManager::getInstance(AiModel::class);
        foreach ($testData as $field => $value) {
            $model->setData($field, $value);
        }
        $model->save();

        $this->assertNotEmpty($model->getId());

        $loadedModel = ObjectManager::getInstance(AiModel::class);
        $loadedModel->load($model->getId());

        $this->assertSame($testData[AiModel::schema_fields_SUPPLIER], $loadedModel->getData(AiModel::schema_fields_SUPPLIER));
        $this->assertSame($testData[AiModel::schema_fields_MODEL_CODE], $loadedModel->getData(AiModel::schema_fields_MODEL_CODE));
        $this->assertSame($testData[AiModel::schema_fields_IS_ACTIVE], $loadedModel->getData(AiModel::schema_fields_IS_ACTIVE));
        $this->assertSame($testData[AiModel::schema_fields_IS_DEFAULT], $loadedModel->getData(AiModel::schema_fields_IS_DEFAULT));

        $loadedModel->delete()->fetch();
    }

    public function testModelCollectorCanInsertDataWithNewFields(): void
    {
        $model = ObjectManager::getInstance(AiModel::class);
        $model->setData(AiModel::schema_fields_SUPPLIER, 'openai')
            ->setData(AiModel::schema_fields_MODEL_CODE, 'gpt-4-schema-test-' . uniqid('', false))
            ->setData(AiModel::schema_fields_NAME, 'GPT-4 Schema Test')
            ->setData(AiModel::schema_fields_VERSION, '1.0.0')
            ->setData(AiModel::schema_fields_CONFIG, json_encode(['temperature' => 0.7]))
            ->setData(AiModel::schema_fields_CAPABILITIES, json_encode(['chat' => true, 'completion' => true]))
            ->setData(AiModel::schema_fields_MAX_TOKENS, 8192)
            ->setData(AiModel::schema_fields_COST_PER_TOKEN, '0.00003')
            ->setData(AiModel::schema_fields_TOKEN_PRICE_INPUT, 0.03)
            ->setData(AiModel::schema_fields_TOKEN_PRICE_OUTPUT, 0.06)
            ->setData(AiModel::schema_fields_PROXY_INFO, null)
            ->setData(AiModel::schema_fields_STATUS, 'active')
            ->setData(AiModel::schema_fields_IS_ACTIVE, 1)
            ->setData(AiModel::schema_fields_IS_DEFAULT, 0);

        $model->save();

        $this->assertNotEmpty($model->getId());

        $model->delete()->fetch();
    }
}
