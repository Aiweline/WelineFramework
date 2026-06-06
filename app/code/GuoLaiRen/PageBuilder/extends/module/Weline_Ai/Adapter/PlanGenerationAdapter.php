<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Extends\Module\Weline_Ai\Adapter;

use GuoLaiRen\PageBuilder\Extends\Module\Weline_Ai\Style\PageBuilderStyleProvider;
use Weline\Ai\Interface\AdapterModelBindingInterface;
use Weline\Ai\Interface\AdapterSkillBindingInterface;
use Weline\Ai\Interface\AdapterStyleBindingInterface;
use Weline\Ai\Interface\ScenarioAdapterInterface;

class PlanGenerationAdapter implements ScenarioAdapterInterface, AdapterSkillBindingInterface, AdapterStyleBindingInterface, AdapterModelBindingInterface
{
    public function getDefaultModelBindings(): array
    {
        return ['text2text' => 'deepseek-v4-flash'];
    }

    public function getDefaultSkillCodes(): array
    {
        return ['claude-design', 'impeccable', 'weline-pixel-events'];
    }

    public function getDefaultStyleCodes(): array
    {
        return [PageBuilderStyleProvider::CARD_GAME_STYLE_CODE];
    }

    public function getCode(): string
    {
        return 'pagebuilder_plan_generation';
    }

    public function getName(): string
    {
        return 'PageBuilder Stage-1 Plan Adapter';
    }

    public function getDescription(): string
    {
        return 'Constrains stage-1 plan generation to a single structured JSON object.';
    }

    public function getVersion(): string
    {
        return '1.0.2';
    }

    public function getSupportedModelTypes(): array
    {
        return ['*'];
    }

    public function adaptPrompt(string $prompt, array $params = []): string
    {
        $normalized = trim($prompt);
        if ($normalized === '') {
            return $prompt;
        }

        $hasJsonConstraint = stripos($normalized, 'json') !== false
            || stripos($normalized, 'response_format') !== false;
        if ($hasJsonConstraint) {
            return $prompt;
        }

        return $normalized . "\n\n"
            . "Output constraints:\n"
            . "1. Return one valid JSON object only.\n"
            . "2. Do not return markdown fences such as ```json.\n"
            . "3. Do not return a separate markdown field.\n"
            . "4. Do not return any prose outside JSON.\n";
    }

    public function processResponse(string $response, array $params = []): string
    {
        $content = trim($response);
        if ($content === '') {
            return $response;
        }

        if (preg_match('/```(?:json)?\s*(\{[\s\S]*\})\s*```/i', $content, $matches)) {
            $content = trim((string)($matches[1] ?? ''));
        }

        if (preg_match('/(\{[\s\S]*\})/m', $content, $matches)) {
            $candidate = trim((string)($matches[1] ?? ''));
            $decoded = json_decode($candidate, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $candidate;
            }
        }

        return $response;
    }

    public function validateParams(array $params = []): array
    {
        return [];
    }

    public function getParamTemplate(): array
    {
        return [
            'description' => 'Stage-1 site plan generation parameters',
            'fields' => [],
        ];
    }

    public function getExamples(): array
    {
        return [
            [
                'title' => 'Generate a stage-1 plan',
                'description' => 'Return a concrete implementation plan as one JSON object.',
                'input' => 'Generate a stage-1 plan for an India market card game site.',
                'expected_output' => '{"site_strategy":{},"pages":{},"execution_steps":[]}',
            ],
        ];
    }

    public function supportsModel(string $modelCode): bool
    {
        return true;
    }
}
