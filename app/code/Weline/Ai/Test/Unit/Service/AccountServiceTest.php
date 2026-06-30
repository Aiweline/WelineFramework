<?php
declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\Provider\AccountService;

class AccountServiceTest extends TestCase
{
    private AccountService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AccountService();
    }

    public function testSupportsModelReturnsTrueForConfiguredStaticModel(): void
    {
        $this->assertTrue($this->service->supportsModel('openai', 'gpt-4o-mini'));
    }

    public function testSupportsModelFallsBackToProviderPrefixForUnsyncedModel(): void
    {
        $this->assertTrue($this->service->supportsModel('openai', 'gpt-4.1'));
    }

    public function testSupportsModelReturnsFalseForWrongProvider(): void
    {
        $this->assertFalse($this->service->supportsModel('anthropic', 'gpt-4o-mini'));
    }

    public function testGetProviderByModelPrefersSavedSupplier(): void
    {
        $model = $this->createMock(AiModel::class);
        $model->method('getData')->willReturnCallback(static function (string $key) {
            return match ($key) {
                AiModel::schema_fields_SUPPLIER => 'deepseek',
                AiModel::schema_fields_MODEL_CODE => 'deepseek-v4-flash',
                default => null,
            };
        });

        $this->assertSame('deepseek', $this->service->getProviderByModel($model));
    }

    public function testGetProviderByModelFallsBackToModelCodeWhenSupplierMissing(): void
    {
        $model = $this->createMock(AiModel::class);
        $model->method('getData')->willReturnCallback(static function (string $key) {
            return match ($key) {
                AiModel::schema_fields_SUPPLIER => '',
                AiModel::schema_fields_MODEL_CODE => 'gpt-4.1',
                default => null,
            };
        });

        $this->assertSame('openai', $this->service->getProviderByModel($model));
    }
}
