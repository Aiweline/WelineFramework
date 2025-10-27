<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiAssistant;
use Weline\Framework\Manager\ObjectManager;

/**
 * 测试 AiAssistant 模型
 */
class AiAssistantTest extends TestCase
{
    private AiAssistant $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = ObjectManager::getInstance(AiAssistant::class);
    }

    /**
     * 测试模型实例化
     */
    public function testModelInstantiation()
    {
        $this->assertInstanceOf(AiAssistant::class, $this->model);
    }

    /**
     * 测试模型字段常量定义
     */
    public function testFieldConstants()
    {
        $this->assertEquals('id', AiAssistant::fields_ID);
        $this->assertEquals('tenant_id', AiAssistant::fields_TENANT_ID);
        $this->assertEquals('model_id', AiAssistant::fields_MODEL_ID);
        $this->assertEquals('user_id', AiAssistant::fields_USER_ID);
        $this->assertEquals('name', AiAssistant::fields_NAME);
        $this->assertEquals('description', AiAssistant::fields_DESCRIPTION);
        $this->assertEquals('prompt_template', AiAssistant::fields_PROMPT_TEMPLATE);
        $this->assertEquals('config', AiAssistant::fields_CONFIG);
        $this->assertEquals('is_public', AiAssistant::fields_IS_PUBLIC);
        $this->assertEquals('usage_count', AiAssistant::fields_USAGE_COUNT);
        $this->assertEquals('status', AiAssistant::fields_STATUS);
        $this->assertEquals('created_at', AiAssistant::fields_CREATED_AT);
        $this->assertEquals('updated_at', AiAssistant::fields_UPDATED_AT);
    }

    /**
     * 测试数据设置和获取
     */
    public function testSetAndGetData()
    {
        $testData = [
            AiAssistant::fields_TENANT_ID => 1,
            AiAssistant::fields_MODEL_ID => 1,
            AiAssistant::fields_USER_ID => 1,
            AiAssistant::fields_NAME => 'Code Assistant',
            AiAssistant::fields_DESCRIPTION => 'AI助手用于代码生成',
            AiAssistant::fields_PROMPT_TEMPLATE => 'You are a helpful coding assistant',
            AiAssistant::fields_IS_PUBLIC => 1,
            AiAssistant::fields_STATUS => AiAssistant::STATUS_ACTIVE,
            AiAssistant::fields_USAGE_COUNT => 100
        ];

        $this->model->setData($testData);

        $this->assertEquals(1, $this->model->getData(AiAssistant::fields_TENANT_ID));
        $this->assertEquals(1, $this->model->getData(AiAssistant::fields_MODEL_ID));
        $this->assertEquals(1, $this->model->getData(AiAssistant::fields_USER_ID));
        $this->assertEquals('Code Assistant', $this->model->getData(AiAssistant::fields_NAME));
        $this->assertEquals('AI助手用于代码生成', $this->model->getData(AiAssistant::fields_DESCRIPTION));
        $this->assertEquals('You are a helpful coding assistant', $this->model->getData(AiAssistant::fields_PROMPT_TEMPLATE));
        $this->assertEquals(1, $this->model->getData(AiAssistant::fields_IS_PUBLIC));
        $this->assertEquals(AiAssistant::STATUS_ACTIVE, $this->model->getData(AiAssistant::fields_STATUS));
        $this->assertEquals(100, $this->model->getData(AiAssistant::fields_USAGE_COUNT));
    }

    /**
     * 测试isPublic方法
     */
    public function testIsPublic()
    {
        $this->model->setData(AiAssistant::fields_IS_PUBLIC, 1);
        $this->assertTrue((bool)$this->model->getData(AiAssistant::fields_IS_PUBLIC));

        $this->model->setData(AiAssistant::fields_IS_PUBLIC, 0);
        $this->assertFalse((bool)$this->model->getData(AiAssistant::fields_IS_PUBLIC));
    }

    /**
     * 测试isActive方法
     */
    public function testIsActive()
    {
        $this->model->setData(AiAssistant::fields_STATUS, AiAssistant::STATUS_ACTIVE);
        $this->assertTrue($this->model->isActive());

        $this->model->setData(AiAssistant::fields_STATUS, AiAssistant::STATUS_INACTIVE);
        $this->assertFalse($this->model->isActive());
    }

    /**
     * 测试getConfig方法
     */
    public function testGetConfig()
    {
        $config = [
            'max_tokens' => 2000,
            'temperature' => 0.7,
            'top_p' => 0.9
        ];
        $this->model->setData(AiAssistant::fields_CONFIG, json_encode($config));

        $result = $this->model->getConfig();
        $this->assertIsArray($result);
        $this->assertEquals($config, $result);
    }

    /**
     * 测试使用次数增加
     */
    public function testUsageCountIncrement()
    {
        $initialCount = 100;
        $this->model->setData(AiAssistant::fields_USAGE_COUNT, $initialCount);

        // 模拟使用次数增加
        $newCount = $this->model->getData(AiAssistant::fields_USAGE_COUNT) + 1;
        $this->model->setData(AiAssistant::fields_USAGE_COUNT, $newCount);

        $this->assertEquals($initialCount + 1, $this->model->getData(AiAssistant::fields_USAGE_COUNT));
    }

    /**
     * 测试状态管理
     */
    public function testStatusManagement()
    {
        $statuses = [
            AiAssistant::STATUS_ACTIVE,
            AiAssistant::STATUS_INACTIVE,
            AiAssistant::STATUS_ARCHIVED
        ];

        foreach ($statuses as $status) {
            $this->model->setData(AiAssistant::fields_STATUS, $status);
            $this->assertEquals($status, $this->model->getData(AiAssistant::fields_STATUS));
        }
    }

    /**
     * 测试时间戳字段
     */
    public function testTimestampFields()
    {
        $currentTime = time();

        $this->model->setData(AiAssistant::fields_CREATED_AT, $currentTime);
        $this->model->setData(AiAssistant::fields_UPDATED_AT, $currentTime);

        $this->assertEquals($currentTime, $this->model->getData(AiAssistant::fields_CREATED_AT));
        $this->assertEquals($currentTime, $this->model->getData(AiAssistant::fields_UPDATED_AT));
    }

    protected function tearDown(): void
    {
        unset($this->model);
        parent::tearDown();
    }
}

