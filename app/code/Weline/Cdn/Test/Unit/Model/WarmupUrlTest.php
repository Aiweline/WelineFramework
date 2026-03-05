<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Model\WarmupUrl;
use Weline\Framework\Manager\ObjectManager;

/**
 * WarmupUrl模型单元测试
 */
class WarmupUrlTest extends TestCase
{
    private WarmupUrl $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = ObjectManager::getInstance(WarmupUrl::class);
    }

    /**
     * 测试：模型实例化
     */
    public function testModelInstantiation(): void
    {
        $this->assertInstanceOf(WarmupUrl::class, $this->model);
    }

    /**
     * 测试：字段常量定义
     */
    public function testFieldConstants(): void
    {
        $this->assertEquals('warmup_url_id', WarmupUrl::schema_fields_WARMUP_URL_ID);
        $this->assertEquals('module', WarmupUrl::schema_fields_MODULE);
        $this->assertEquals('provider', WarmupUrl::schema_fields_PROVIDER);
        $this->assertEquals('url', WarmupUrl::schema_fields_URL);
        $this->assertEquals('site_id', WarmupUrl::schema_fields_SITE_ID);
        $this->assertEquals('domain_id', WarmupUrl::schema_fields_DOMAIN_ID);
        $this->assertEquals('status', WarmupUrl::schema_fields_STATUS);
        $this->assertEquals('target_count', WarmupUrl::schema_fields_TARGET_COUNT);
        $this->assertEquals('processed_count', WarmupUrl::schema_fields_PROCESSED_COUNT);
        $this->assertEquals('success_count', WarmupUrl::schema_fields_SUCCESS_COUNT);
        $this->assertEquals('fail_count', WarmupUrl::schema_fields_FAIL_COUNT);
        $this->assertEquals('retries', WarmupUrl::schema_fields_RETRIES);
        $this->assertEquals('enabled', WarmupUrl::schema_fields_ENABLED);
        $this->assertEquals('last_warmed_at', WarmupUrl::schema_fields_LAST_WARMED_AT);
        $this->assertEquals('created_at', WarmupUrl::schema_fields_CREATED_AT);
        $this->assertEquals('updated_at', WarmupUrl::schema_fields_UPDATED_AT);
    }

    /**
     * 测试：状态常量
     */
    public function testStatusConstants(): void
    {
        $this->assertEquals('pending', WarmupUrl::STATUS_PENDING);
        $this->assertEquals('success', WarmupUrl::STATUS_SUCCESS);
        $this->assertEquals('fail', WarmupUrl::STATUS_FAIL);
    }

    /**
     * 测试：数据设置和获取
     */
    public function testSetAndGetData(): void
    {
        $testData = [
            WarmupUrl::schema_fields_MODULE => 'TestModule',
            WarmupUrl::schema_fields_PROVIDER => 'test_provider',
            WarmupUrl::schema_fields_URL => 'https://example.com/page',
            WarmupUrl::schema_fields_SITE_ID => 1,
            WarmupUrl::schema_fields_DOMAIN_ID => 1,
            WarmupUrl::schema_fields_STATUS => WarmupUrl::STATUS_PENDING,
            WarmupUrl::schema_fields_TARGET_COUNT => 10,
            WarmupUrl::schema_fields_PROCESSED_COUNT => 0,
            WarmupUrl::schema_fields_SUCCESS_COUNT => 0,
            WarmupUrl::schema_fields_FAIL_COUNT => 0,
            WarmupUrl::schema_fields_RETRIES => 0,
            WarmupUrl::schema_fields_ENABLED => 1
        ];

        $this->model->setData($testData);

        $this->assertEquals('TestModule', $this->model->getData(WarmupUrl::schema_fields_MODULE));
        $this->assertEquals('test_provider', $this->model->getData(WarmupUrl::schema_fields_PROVIDER));
        $this->assertEquals('https://example.com/page', $this->model->getData(WarmupUrl::schema_fields_URL));
        $this->assertEquals(1, $this->model->getData(WarmupUrl::schema_fields_SITE_ID));
        $this->assertEquals(1, $this->model->getData(WarmupUrl::schema_fields_DOMAIN_ID));
        $this->assertEquals(WarmupUrl::STATUS_PENDING, $this->model->getData(WarmupUrl::schema_fields_STATUS));
        $this->assertEquals(10, $this->model->getData(WarmupUrl::schema_fields_TARGET_COUNT));
        $this->assertEquals(0, $this->model->getData(WarmupUrl::schema_fields_PROCESSED_COUNT));
    }

    /**
     * 测试：isEnabled方法
     */
    public function testIsEnabled(): void
    {
        $this->model->setData(WarmupUrl::schema_fields_ENABLED, 1);
        $this->assertTrue($this->model->isEnabled());

        $this->model->setData(WarmupUrl::schema_fields_ENABLED, 0);
        $this->assertFalse($this->model->isEnabled());
    }

    /**
     * 测试：beforeSave方法设置时间戳
     */
    public function testBeforeSaveSetsTimestamps(): void
    {
        $this->model->setData([
            WarmupUrl::schema_fields_URL => 'https://example.com/test',
            WarmupUrl::schema_fields_MODULE => 'TestModule',
            WarmupUrl::schema_fields_PROVIDER => 'test'
        ]);

        $beforeSave = $this->model->beforeSave();

        $this->assertInstanceOf(WarmupUrl::class, $beforeSave);
        $this->assertNotNull($this->model->getData(WarmupUrl::schema_fields_CREATED_AT));
        $this->assertNotNull($this->model->getData(WarmupUrl::schema_fields_UPDATED_AT));
        $this->assertIsInt($this->model->getData(WarmupUrl::schema_fields_CREATED_AT));
        $this->assertIsInt($this->model->getData(WarmupUrl::schema_fields_UPDATED_AT));
    }

    /**
     * 测试：获取主键字段名
     */
    public function testGetIdFieldName(): void
    {
        $this->assertEquals('warmup_url_id', $this->model->getIdFieldName());
    }

    /**
     * 测试：表名常量
     */
    public function testTableConstant(): void
    {
        $this->assertEquals('cdn_warmup_url', WarmupUrl::schema_table);
    }
}

