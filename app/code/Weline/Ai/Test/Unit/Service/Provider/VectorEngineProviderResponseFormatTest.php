<?php
declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service\Provider;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\Provider\VectorEngineProvider;

final class VectorEngineProviderResponseFormatTest extends TestCase
{
    public function testJsonSchemaRequestDowngradesWhenModelConfigDisablesSchemaResponseFormat(): void
    {
        $provider = new CapturingVectorEngineProvider();
        $model = $this->buildTextModel([
            'response_format_json_schema' => false,
        ]);

        $provider->generate($model, 'Return JSON.', [
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'component',
                    'schema' => ['type' => 'object'],
                ],
            ],
        ]);

        self::assertSame(['type' => 'json_object'], $provider->lastPayload['response_format'] ?? null);
    }

    public function testJsonSchemaRequestIsPreservedWhenModelSupportsSchemaResponseFormat(): void
    {
        $provider = new CapturingVectorEngineProvider();
        $model = $this->buildTextModel([
            'response_format_json_schema' => true,
        ]);
        $format = [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'component',
                'schema' => ['type' => 'object'],
            ],
        ];

        $provider->generate($model, 'Return JSON.', ['response_format' => $format]);

        self::assertSame($format, $provider->lastPayload['response_format'] ?? null);
    }

    /**
     * @param array<string,mixed> $config
     */
    private function buildTextModel(array $config): AiModel
    {
        $model = new AiModel();
        $model->setData([
            AiModel::schema_fields_MODEL_CODE => 'deepseek-v4-flash',
            AiModel::schema_fields_PRIMARY_MODALITY => AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT,
            AiModel::schema_fields_CONFIG => \array_replace([
                'api_key' => 'test-key',
                'base_url' => 'https://vector.example.test/v1',
                'model' => 'deepseek-v4-flash',
                'timeout' => 60,
            ], $config),
            AiModel::schema_fields_PROVIDER_CONFIG => [],
            AiModel::schema_fields_PROXY_INFO => [],
            AiModel::schema_fields_CAPABILITIES => ['chat'],
        ]);

        return $model;
    }
}

final class CapturingVectorEngineProvider extends VectorEngineProvider
{
    /** @var array<string,mixed> */
    public array $lastPayload = [];

    protected function postJson(string $url, string $apiKey, array $payload, int $timeout): array
    {
        $this->lastPayload = $payload;

        return [
            'choices' => [[
                'message' => ['content' => '{"ok":true}'],
                'finish_reason' => 'stop',
            ]],
            'usage' => [],
            'model' => (string)($payload['model'] ?? ''),
        ];
    }
}
