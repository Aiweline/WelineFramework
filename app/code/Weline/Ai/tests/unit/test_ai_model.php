<?php
declare(strict_types=1);

/**
 * AI模型单元测试
 */

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiModel;

class AiModelTest extends TestCase
{
    private AiModel $aiModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->aiModel = new AiModel();
    }

    public function testModelCreation(): void
    {
        $this->aiModel->setData([
            'vendor' => 'openai',
            'model_code' => 'gpt-3.5-turbo',
            'model_name' => 'GPT-3.5 Turbo',
            'is_active' => 1
        ]);
        
        $result = $this->aiModel->save();
        $this->assertTrue($result);
    }

    public function testModelValidation(): void
    {
        $this->aiModel->setData([
            'vendor' => '',
            'model_code' => 'test-model'
        ]);
        
        $this->assertFalse($this->aiModel->validate());
    }

    protected function tearDown(): void
    {
        $this->aiModel->getCollection()
            ->where('vendor', 'openai')
            ->delete();
        
        parent::tearDown();
    }
}
