<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service\Provider;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\Provider\MockProvider;

final class ProviderConnectionTestVisionGateTest extends TestCase
{
    public function test_text_primary_with_vision_capability_uses_plain_text_prompt(): void
    {
        $provider = new class extends MockProvider {
            /** @var array{prompt:string,params:array<string,mixed>}|null */
            public ?array $lastCall = null;

            public function generate(AiModel $model, string $prompt, array $params = []): array
            {
                $this->lastCall = ['prompt' => $prompt, 'params' => $params];
                return [
                    'content' => 'OK',
                    'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
                    'model' => $model->getModelCode(),
                    'finish_reason' => 'stop',
                ];
            }
        };

        $model = new AiModel();
        $model->setData([
            AiModel::schema_fields_MODEL_CODE => 'deepseek-flash',
            AiModel::schema_fields_PRIMARY_MODALITY => AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT,
            AiModel::schema_fields_CAPABILITIES => json_encode(['chat', 'vision', 'reasoning']),
        ]);

        $result = $provider->testConnection($model, ['test_mode' => true, 'timeout' => 1]);

        self::assertTrue($result['success'] ?? false);
        self::assertNotNull($provider->lastCall);
        self::assertStringContainsString('connection test', strtolower((string)$provider->lastCall['prompt']));
        self::assertArrayNotHasKey('messages', $provider->lastCall['params']);
    }

    public function test_vision_named_model_still_uses_image_payload(): void
    {
        $provider = new class extends MockProvider {
            /** @var array{prompt:string,params:array<string,mixed>}|null */
            public ?array $lastCall = null;

            public function generate(AiModel $model, string $prompt, array $params = []): array
            {
                $this->lastCall = ['prompt' => $prompt, 'params' => $params];
                return [
                    'content' => 'OK',
                    'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
                    'model' => $model->getModelCode(),
                    'finish_reason' => 'stop',
                ];
            }
        };

        $model = new AiModel();
        $model->setData([
            AiModel::schema_fields_MODEL_CODE => 'qwen2.5-vl-72b',
            AiModel::schema_fields_PRIMARY_MODALITY => AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT,
            AiModel::schema_fields_CAPABILITIES => json_encode(['chat', 'vision']),
        ]);

        $result = $provider->testConnection($model, ['test_mode' => true, 'timeout' => 1]);

        self::assertTrue($result['success'] ?? false);
        self::assertNotNull($provider->lastCall);
        self::assertArrayHasKey('messages', $provider->lastCall['params']);
    }
}
