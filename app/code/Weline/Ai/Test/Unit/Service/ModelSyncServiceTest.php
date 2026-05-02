<?php
declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service;

use Weline\Ai\Service\Provider\ModelSyncService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;

/**
 * ModelSyncService 单元测试
 */
class ModelSyncServiceTest extends TestCore
{
    private ModelSyncService $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = ObjectManager::getInstance(ModelSyncService::class);
    }

    public function testMergeModelMetaPrefersNewValues(): void
    {
        $old = [
            'code' => 'gpt-4',
            'name' => 'GPT-4',
            'description' => 'old',
            'max_tokens' => 0,
        ];
        $new = [
            'code' => 'gpt-4',
            'name' => 'GPT-4',
            'description' => 'new',
            'max_tokens' => 8192,
        ];

        $merged = $this->service->mergeModelMeta($old, $new);
        $this->assertSame('gpt-4', $merged['code'] ?? '');
        $this->assertSame('new', $merged['description'] ?? '');
        $this->assertSame(8192, $merged['max_tokens'] ?? 0);
    }

    public function testBuildModelConfigUsesDefaults(): void
    {
        $providerConfig = [
            'base_url' => 'https://api.openai.com/v1',
            'model_field' => 'model',
        ];
        $defaults = [
            'max_tokens' => 4096,
            'temperature' => 0.7,
            'top_p' => 1.0,
            'stream' => true,
            'timeout' => 180,
            'max_retries' => 3,
            'capabilities' => ['chat', 'code'],
        ];
        $modelMeta = [
            'code' => 'gpt-4',
            'name' => 'GPT-4',
            'max_tokens' => 8192,
        ];

        $config = $this->service->buildModelConfig('openai', $modelMeta, $providerConfig, $defaults);
        $this->assertSame('openai', $config['vendor'] ?? '');
        $this->assertSame('gpt-4', $config['model_code'] ?? '');
        $this->assertSame('GPT-4', $config['model_name'] ?? '');
        $this->assertSame(8192, $config['max_tokens'] ?? 0);

        $providerConfigData = $config['config'] ?? [];
        $this->assertSame('https://api.openai.com/v1', $providerConfigData['base_url'] ?? '');
        $this->assertSame('gpt-4', $providerConfigData['model'] ?? '');
        $this->assertSame(180, $providerConfigData['timeout'] ?? 0);
    }

    public function testBuildModelConfigKeepsImageModelModalityAndStatus(): void
    {
        $providerConfig = [
            'base_url' => 'https://api.openai.com/v1',
            'model_field' => 'model',
        ];
        $modelMeta = [
            'code' => 'gpt-image-1',
            'name' => 'GPT Image 1',
            'primary_modality' => 'text2image',
            'capabilities' => ['image_generation', 'image_output'],
            'is_active' => 1,
            'is_default' => 1,
        ];

        $config = $this->service->buildModelConfig('openai', $modelMeta, $providerConfig);

        $this->assertSame('text2image', $config['primary_modality'] ?? '');
        $this->assertSame(1, $config['is_active'] ?? 0);
        $this->assertSame(1, $config['is_default'] ?? 0);
        $this->assertSame(['image_generation', 'image_output'], $config['capabilities'] ?? []);
    }
}
