<?php

declare(strict_types=1);

namespace Weline\Ai\Service;

use Weline\Ai\Api\ScenarioImageGenerationInterface;
use Weline\Ai\Model\AiModel;

/**
 * The public scenario-only image boundary. Model/provider selection remains in
 * AiService and the configured scenario adapter.
 */
final class ScenarioImageGenerationGateway implements ScenarioImageGenerationInterface
{
    /** @var array<string,true> */
    private const MEDIA_KEYS = [
        'slot_id' => true,
        'negative_prompt' => true,
        'asset_policy' => true,
        'aspect_ratio' => true,
        'target_aspect_ratio' => true,
        'target_size' => true,
        'size' => true,
        'target_container_role' => true,
        'target_render_size' => true,
        'render_container' => true,
        'object_fit' => true,
        'safe_crop_strategy' => true,
        'width' => true,
        'height' => true,
        'output_format' => true,
        'background' => true,
        'timeout' => true,
        'image_timeout' => true,
        'connect_timeout' => true,
        'identity_asset_variant' => true,
        'identity_asset_role' => true,
        'identity_transparent_raster_required' => true,
        'transparent_raster_required' => true,
        'logo_raster_alpha_only' => true,
        'logo_text_policy' => true,
        'approved_identity_texts' => true,
        'approved_abbreviations' => true,
        'allowed_logo_text' => true,
        'brand_text' => true,
        'image' => true,
        'disable_skill_prompt_injection' => true,
        'disable_style_prompt_injection' => true,
    ];

    /** @var array<string,true> */
    private const RUNTIME_KEYS = [
        'user_id' => true,
        'admin_user_id' => true,
        'is_backend' => true,
        'user_config' => true,
        'command_id' => true,
        'execution_token' => true,
        'timeout' => true,
        'image_timeout' => true,
        'connect_timeout' => true,
        'disable_ai_timeout' => true,
        'disable_cli_timeout' => true,
    ];

    public function __construct(private readonly AiService $aiService)
    {
    }

    public function inspectReadiness(string $scenarioCode, array $requiredCapabilities = []): array
    {
        return $this->aiService->inspectScenarioReadiness(
            $this->scenario($scenarioCode),
            AiModel::PRIMARY_MODALITY_TEXT_TO_IMAGE,
            $this->capabilities($requiredCapabilities),
        );
    }

    public function generate(
        string $scenarioCode,
        array $semanticPayload,
        ?string $locale = null,
        array $mediaContract = [],
        array $runtimeContext = [],
    ): array {
        $scenarioCode = $this->scenario($scenarioCode);
        $this->assertNoRoutingFields($semanticPayload);
        $this->assertNoRoutingFields($mediaContract);
        $this->assertNoRoutingFields($runtimeContext);
        $params = $this->allowlist($mediaContract, self::MEDIA_KEYS);
        $params = \array_replace($params, $this->allowlist($runtimeContext, self::RUNTIME_KEYS));
        $params['semantic_payload_sha256'] = \hash('sha256', $this->json($semanticPayload));
        if ($locale !== null && \trim($locale) !== '') {
            $params['locale'] = \trim($locale);
        }

        return $this->aiService->generateImage(
            $this->renderFiveLayerPrompt($semanticPayload),
            null,
            $scenarioCode,
            $params,
        );
    }

    /** @param array<string,mixed> $payload */
    private function renderFiveLayerPrompt(array $payload): string
    {
        $layers = [
            'scenario_invariants' => $payload['scenario_invariants'] ?? [],
            'site_context' => $payload['site_context'] ?? [],
            'task' => $payload['task'] ?? [],
            'output_contract' => $payload['output_contract'] ?? [],
            'validation_feedback' => $payload['validation_feedback'] ?? ['status' => 'none'],
        ];

        return "[1 Scenario invariants]\n" . $this->json($layers['scenario_invariants']) . "\n"
            . "[2 Site context]\n" . $this->json($layers['site_context']) . "\n"
            . "[3 Page/block task]\n" . $this->json($layers['task']) . "\n"
            . "[4 Single output contract]\n" . $this->json($layers['output_contract']) . "\n"
            . "[5 Structured validation feedback]\n" . $this->json($layers['validation_feedback']);
    }

    private function scenario(string $scenarioCode): string
    {
        $scenarioCode = \trim($scenarioCode);
        if ($scenarioCode === '' || \strlen($scenarioCode) > 128
            || \preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/D', $scenarioCode) !== 1
        ) {
            throw new \InvalidArgumentException('A valid scenario code is required.');
        }

        return $scenarioCode;
    }

    /** @param list<string> $capabilities @return list<string> */
    private function capabilities(array $capabilities): array
    {
        $result = [];
        foreach ($capabilities as $capability) {
            if (!\is_scalar($capability)) {
                continue;
            }
            $capability = \strtolower(\trim((string)$capability));
            if ($capability !== '' && !\in_array($capability, $result, true)) {
                $result[] = $capability;
            }
        }

        return $result;
    }

    /** @param array<string,mixed> $values @param array<string,true> $allowed @return array<string,mixed> */
    private function allowlist(array $values, array $allowed): array
    {
        return \array_intersect_key($values, $allowed);
    }

    /** @param array<string,mixed> $values */
    private function assertNoRoutingFields(array $values): void
    {
        $forbidden = [
            'model', 'model_code', 'provider', 'provider_code', 'supplier', 'account',
            'account_id', 'endpoint', 'base_url', 'api_key', 'secret', 'fallback_model',
        ];
        $stack = [$values];
        while ($stack !== []) {
            $node = \array_pop($stack);
            foreach ($node as $key => $value) {
                if (\in_array(\strtolower((string)$key), $forbidden, true)) {
                    throw new \InvalidArgumentException('Model/provider routing fields are forbidden at the scenario image boundary.');
                }
                if (\is_array($value)) {
                    $stack[] = $value;
                }
            }
        }
    }

    private function json(mixed $value): string
    {
        $json = \json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return \mb_substr(\is_string($json) ? $json : '{}', 0, 12000, 'UTF-8');
    }
}
