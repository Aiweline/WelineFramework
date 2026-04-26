<?php
declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\Provider\ProviderTimeoutPolicy;

class AiModelLegacyTimeoutNormalizationTest extends TestCase
{
    public function testLegacyPresetTimeoutIsNormalizedFromConfig(): void
    {
        $model = new AiModel();
        $model->setData(AiModel::schema_fields_CONFIG, json_encode([
            'timeout' => 180,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $model->setData(AiModel::schema_fields_PROVIDER_CONFIG, json_encode([
            'selected_model_preset' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $config = $model->getConfig();

        $this->assertSame(ProviderTimeoutPolicy::DEFAULT_REQUEST_TIMEOUT, $config['timeout'] ?? null);
    }

    public function testLegacyPresetTimeoutIsNormalizedFromProviderConfig(): void
    {
        $model = new AiModel();
        $model->setData(AiModel::schema_fields_PROVIDER_CONFIG, json_encode([
            'timeout' => 30,
            'selected_model_preset' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $providerConfig = $model->getProviderConfig();

        $this->assertSame(ProviderTimeoutPolicy::DEFAULT_REQUEST_TIMEOUT, $providerConfig['timeout'] ?? null);
    }

    public function testCustomTimeoutIsPreservedWhenNotPreset(): void
    {
        $model = new AiModel();
        $model->setData(AiModel::schema_fields_CONFIG, json_encode([
            'timeout' => 180,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $model->setData(AiModel::schema_fields_PROVIDER_CONFIG, json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $config = $model->getConfig();

        $this->assertSame(180, $config['timeout'] ?? null);
    }
}
