<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Model\Account;
use Weline\Framework\Manager\ObjectManager;

/**
 * Account模型单元测试
 */
class AccountTest extends TestCase
{
    private Account $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = ObjectManager::getInstance(Account::class);
    }

    /**
     * 测试：模型实例化
     */
    public function testModelInstantiation(): void
    {
        $this->assertInstanceOf(Account::class, $this->model);
    }

    /**
     * 测试：字段常量定义
     */
    public function testFieldConstants(): void
    {
        $this->assertEquals('account_id', Account::schema_fields_ACCOUNT_ID);
        $this->assertEquals('adapter', Account::schema_fields_ADAPTER);
        $this->assertEquals('name', Account::schema_fields_NAME);
        $this->assertEquals('description', Account::schema_fields_DESCRIPTION);
        $this->assertEquals('credentials', Account::schema_fields_CREDENTIALS);
        $this->assertEquals('is_default', Account::schema_fields_IS_DEFAULT);
        $this->assertEquals('status', Account::schema_fields_STATUS);
        $this->assertEquals('created_at', Account::schema_fields_CREATED_AT);
        $this->assertEquals('updated_at', Account::schema_fields_UPDATED_AT);
    }

    /**
     * 测试：状态常量
     */
    public function testStatusConstants(): void
    {
        $this->assertEquals('active', Account::STATUS_ACTIVE);
        $this->assertEquals('inactive', Account::STATUS_INACTIVE);
    }

    /**
     * 测试：数据设置和获取
     */
    public function testSetAndGetData(): void
    {
        $testData = [
            Account::schema_fields_ADAPTER => 'cloudflare',
            Account::schema_fields_NAME => 'Test Account',
            Account::schema_fields_DESCRIPTION => 'Test Description',
            Account::schema_fields_STATUS => Account::STATUS_ACTIVE,
            Account::schema_fields_IS_DEFAULT => 0
        ];

        $this->model->setData($testData);

        $this->assertEquals('cloudflare', $this->model->getData(Account::schema_fields_ADAPTER));
        $this->assertEquals('Test Account', $this->model->getData(Account::schema_fields_NAME));
        $this->assertEquals('Test Description', $this->model->getData(Account::schema_fields_DESCRIPTION));
        $this->assertEquals(Account::STATUS_ACTIVE, $this->model->getData(Account::schema_fields_STATUS));
        $this->assertEquals(0, $this->model->getData(Account::schema_fields_IS_DEFAULT));
    }

    /**
     * 测试：凭据数组设置和获取
     */
    public function testCredentialsArray(): void
    {
        $credentials = [
            'api_token' => 'test-token-123',
            'api_key' => 'test-key'
        ];

        $this->model->setCredentialsArray($credentials);
        $result = $this->model->getCredentialsArray();

        $this->assertIsArray($result);
        $this->assertEquals('test-token-123', $result['api_token']);
        $this->assertEquals('test-key', $result['api_key']);
    }

    /**
     * 测试：空凭据数组
     */
    public function testEmptyCredentialsArray(): void
    {
        $this->model->setCredentialsArray([]);
        $result = $this->model->getCredentialsArray();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * 测试：获取主键字段名
     */
    public function testGetIdFieldName(): void
    {
        $this->assertEquals('account_id', $this->model->getIdFieldName());
    }

    /**
     * 测试：表名常量
     */
    public function testTableConstant(): void
    {
        $this->assertEquals('cdn_account', Account::schema_table);
    }
}

