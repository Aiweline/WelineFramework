<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Extends\Module\Weline_Ai\Adapter;

use Weline\Ai\Interface\ScenarioAdapterInterface;

class AiSiteAssetsAdapter implements ScenarioAdapterInterface
{
    public function getCode(): string
    {
        return 'pagebuilder_ai_site_assets';
    }

    public function getName(): string
    {
        return 'PageBuilder AI Site Assets Adapter';
    }

    public function getDescription(): string
    {
        return 'Binds PageBuilder inline image asset generation to an explicit text-to-image model.';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getSupportedModelTypes(): array
    {
        return ['text2image'];
    }

    public function adaptPrompt(string $prompt, array $params = []): string
    {
        $normalized = \trim($prompt);
        if ($normalized === '') {
            return $prompt;
        }

        return $normalized . "\n\n"
            . "Asset constraints:\n"
            . "1. Generate one production-ready website image for the requested PageBuilder slot.\n"
            . "2. Do not include visible prompt text, UI labels, watermarks, screenshots, or placeholder graphics.\n"
            . "3. Match the target site language, market, block role, and visual direction from the prompt.\n";
    }

    public function processResponse(string $response, array $params = []): string
    {
        return $response;
    }

    public function validateParams(array $params = []): array
    {
        return [];
    }

    public function getParamTemplate(): array
    {
        return [
            'description' => 'PageBuilder AI site image asset generation parameters',
            'fields' => [],
        ];
    }

    public function getExamples(): array
    {
        return [
            [
                'title' => 'Generate a hero background',
                'description' => 'Create an inline image asset for a PageBuilder hero or content block slot.',
                'input' => 'Generate a full-width hero background for an India market card game site.',
                'expected_output' => 'A generated image asset URL returned by the bound text-to-image model.',
            ],
        ];
    }

    public function supportsModel(string $modelCode): bool
    {
        return $modelCode !== '';
    }
}
