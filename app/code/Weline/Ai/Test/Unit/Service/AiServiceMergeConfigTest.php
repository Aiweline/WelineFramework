<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\AiService;
use Weline\Framework\Manager\ObjectManager;

class AiServiceMergeConfigTest extends TestCase
{
    private AiService $service;

    private \ReflectionMethod $mergeConfigMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $reflection = new \ReflectionClass(AiService::class);
        $this->service = $reflection->newInstanceWithoutConstructor();
        $this->mergeConfigMethod = $reflection->getMethod('mergeConfigToProviderConfig');
        $this->mergeConfigMethod->setAccessible(true);
    }

    public function testMergeConfigToProviderConfigKeepsProviderApiKeyWhenModelConfigValueIsEmpty(): void
    {
        /** @var AiModel $model */
        $model = clone ObjectManager::getInstance(AiModel::class);
        $model->setData(AiModel::schema_fields_CONFIG, json_encode([
            'api_key' => '',
            'temperature' => 0.3,
            'stream' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $model->setData(AiModel::schema_fields_PROVIDER_CONFIG, json_encode([
            'api_key' => 'sk-provider-correct',
            'temperature' => 0.7,
            'stream' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->mergeConfigMethod->invoke($this->service, $model);

        $providerConfig = $model->getProviderConfig();

        $this->assertSame('sk-provider-correct', $providerConfig['api_key'] ?? null);
        $this->assertSame(0.3, $providerConfig['temperature'] ?? null);
        $this->assertFalse($providerConfig['stream'] ?? true);
    }
}
