<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Model\Domain;
use Weline\Framework\Manager\ObjectManager;

/**
 * Domain模型单元测试
 */
class DomainTest extends TestCase
{
    private Domain $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = ObjectManager::getInstance(Domain::class);
    }

    /**
     * 测试：模型实例化
     */
    public function testModelInstantiation(): void
    {
        $this->assertInstanceOf(Domain::class, $this->model);
    }

    /**
     * 测试：字段常量定义
     */
    public function testFieldConstants(): void
    {
        $this->assertEquals('domain_id', Domain::fields_DOMAIN_ID);
        $this->assertEquals('site_id', Domain::fields_SITE_ID);
        $this->assertEquals('adapter', Domain::fields_ADAPTER);
        $this->assertEquals('zone_id', Domain::fields_ZONE_ID);
        $this->assertEquals('domain_name', Domain::fields_DOMAIN_NAME);
        $this->assertEquals('account_id', Domain::fields_ACCOUNT_ID);
        $this->assertEquals('inherit_default', Domain::fields_INHERIT_DEFAULT);
        $this->assertEquals('credentials', Domain::fields_CREDENTIALS);
        $this->assertEquals('rules_override', Domain::fields_RULES_OVERRIDE);
        $this->assertEquals('warmup_interval_seconds', Domain::fields_WARMUP_INTERVAL_SECONDS);
        $this->assertEquals('enabled', Domain::fields_ENABLED);
    }

    /**
     * 测试：数据设置和获取
     */
    public function testSetAndGetData(): void
    {
        $testData = [
            Domain::fields_SITE_ID => 1,
            Domain::fields_ADAPTER => 'cloudflare',
            Domain::fields_ZONE_ID => 'zone-123',
            Domain::fields_DOMAIN_NAME => 'example.com',
            Domain::fields_ENABLED => 1,
            Domain::fields_WARMUP_INTERVAL_SECONDS => 300
        ];

        $this->model->setData($testData);

        $this->assertEquals(1, $this->model->getData(Domain::fields_SITE_ID));
        $this->assertEquals('cloudflare', $this->model->getData(Domain::fields_ADAPTER));
        $this->assertEquals('zone-123', $this->model->getData(Domain::fields_ZONE_ID));
        $this->assertEquals('example.com', $this->model->getData(Domain::fields_DOMAIN_NAME));
        $this->assertEquals(1, $this->model->getData(Domain::fields_ENABLED));
        $this->assertEquals(300, $this->model->getData(Domain::fields_WARMUP_INTERVAL_SECONDS));
    }

    /**
     * 测试：规则覆盖数组设置和获取
     */
    public function testRulesOverrideArray(): void
    {
        $rules = [
            'cache_level' => 'aggressive',
            'browser_cache_ttl' => 3600
        ];

        $this->model->setRulesOverrideArray($rules);
        $result = $this->model->getRulesOverrideArray();

        $this->assertIsArray($result);
        $this->assertEquals('aggressive', $result['cache_level']);
        $this->assertEquals(3600, $result['browser_cache_ttl']);
    }

    /**
     * 测试：获取主键字段名
     */
    public function testGetIdFieldName(): void
    {
        $this->assertEquals('domain_id', $this->model->getIdFieldName());
    }

    /**
     * 测试：表名常量
     */
    public function testTableConstant(): void
    {
        $this->assertEquals('cdn_domain', Domain::table);
    }
}

