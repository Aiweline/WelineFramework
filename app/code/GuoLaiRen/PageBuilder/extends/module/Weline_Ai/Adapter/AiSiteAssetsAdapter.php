<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Extends\Module\Weline_Ai\Adapter;

use GuoLaiRen\PageBuilder\Extends\Module\Weline_Ai\Style\PageBuilderStyleProvider;
use Weline\Ai\Interface\AdapterModelBindingInterface;
use Weline\Ai\Interface\AdapterSkillBindingInterface;
use Weline\Ai\Interface\AdapterStyleBindingInterface;
use Weline\Ai\Interface\ScenarioAdapterInterface;

class AiSiteAssetsAdapter implements ScenarioAdapterInterface, AdapterSkillBindingInterface, AdapterStyleBindingInterface, AdapterModelBindingInterface
{
    public function getDefaultModelBindings(): array
    {
        return [
            'text2text' => 'deepseek-v4-flash',
            'text2image' => 'gemini-3.1-flash-image-preview',
        ];
    }

    public function getDefaultSkillCodes(): array
    {
        return ['claude-design'];
    }

    public function getDefaultStyleCodes(): array
    {
        return [PageBuilderStyleProvider::CARD_GAME_STYLE_CODE];
    }

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

        $contract = $normalized . "\n\n"
            . "Asset constraints:\n"
            . "1. Generate one production-ready website image for the requested PageBuilder slot.\n"
            . "2. Do not include visible prompt text, UI labels, watermarks, screenshots, or placeholder graphics.\n"
            . "3. Match the target site language, market, block role, and visual direction from the prompt.\n"
            . "4. Neon card-game visual contract (when prompt mentions card game, APK, Teen Patti, Rummy, mahjong, poker, chips, table, or neon entertainment): use premium dark card-room atmosphere, electric cyan/magenta/violet rim light, restrained gold highlights, green felt or glass table texture, cinematic crop, and block-specific props. Each image must differ by block role; never reuse one generic hero lobby for every section.\n"
            . "5. Never return flat gray placeholders, emoji stand-ins, CSS-only motifs, or text-only panels when a real generated image is requested.\n";

        if ($this->requiresTransparentIdentityPng($params)) {
            $contract .= "4. Identity logo/icon contract (HARD): output a transparent identity asset: transparent PNG alpha, or safe SVG with no canvas background. The canvas must be transparent; only the brand mark, symbol, or wordmark pixels may be visible.\n"
                . "5. Identity logo/icon exclusions (HARD): no white box, solid-color tile, rounded square card, gradient backdrop, photo scene, wall mockup, app icon tile, screenshot frame, watermark, or paragraph text.\n"
                . "6. If the selected image model cannot produce transparent identity output, the generation must fail contract validation instead of returning a JPEG/WebP or opaque background asset.\n";
        }

        return $contract;
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

    /**
     * @param array<string,mixed> $params
     */
    private function requiresTransparentIdentityPng(array $params): bool
    {
        if (!empty($params['identity_transparent_png_required']) || !empty($params['transparent_png_required'])) {
            return true;
        }

        $slotId = \strtolower(\trim((string)($params['slot_id'] ?? '')));
        if ($slotId === '') {
            return false;
        }

        return \str_starts_with($slotId, 'identity:')
            || \str_contains($slotId, 'identity:website-logo')
            || \str_contains($slotId, 'identity:site-title-icon');
    }
}
