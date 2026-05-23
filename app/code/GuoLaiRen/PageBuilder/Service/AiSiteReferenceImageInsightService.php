<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Service\AI\AiResponseJsonParser;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\AiService;
use Weline\Framework\Manager\ObjectManager;

class AiSiteReferenceImageInsightService
{
    public const DEFAULT_SCENARIO_CODE = 'pagebuilder_ai_site_assets';
    private const MAX_IMAGES = 6;

    public function __construct(
        private readonly ?AiService $aiService = null,
        private readonly ?AiModel $aiModel = null,
        private readonly ?AiResponseJsonParser $responseJsonParser = null,
    ) {
    }

    /**
     * @param array<string,mixed> $scope
     * @return list<array{name:string,url:string,path:string,mime_type:string}>
     */
    public function buildReferenceImagePromptList(array $scope, int $limit = self::MAX_IMAGES): array
    {
        $images = \is_array($scope['reference_images'] ?? null) ? $scope['reference_images'] : [];
        $out = [];
        foreach ($images as $image) {
            if (!\is_array($image)) {
                continue;
            }
            $url = \trim((string)($image['url'] ?? ''));
            $path = \trim((string)($image['path'] ?? ''));
            if ($url === '' && $path === '') {
                continue;
            }
            $out[] = [
                'name' => \trim((string)($image['name'] ?? 'reference image')) ?: 'reference image',
                'url' => $url,
                'path' => $path,
                'mime_type' => \trim((string)($image['mime_type'] ?? '')),
            ];
            if (\count($out) >= \max(1, $limit)) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $scope
     */
    public function buildSignature(array $scope): string
    {
        $images = $this->buildReferenceImagePromptList($scope);
        if ($images === []) {
            return '';
        }

        return \sha1((string)(\json_encode($images, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '[]'));
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    public function analyze(array $scope, string $locale = '', string $scenarioCode = self::DEFAULT_SCENARIO_CODE): array
    {
        $images = $this->buildReferenceImagePromptList($scope);
        if ($images === []) {
            return [];
        }

        $signature = $this->buildSignature($scope);
        $existing = \is_array($scope['reference_image_insights'] ?? null) ? $scope['reference_image_insights'] : [];
        $existingSignature = \trim((string)($scope['reference_image_insights_signature'] ?? ''));
        if ($existing !== [] && $signature !== '' && $signature === $existingSignature) {
            return $existing;
        }

        $visionModel = $this->resolveVisionModel($scenarioCode);
        if (!$visionModel instanceof AiModel) {
            return [];
        }

        $request = $this->buildAnalysisRequest($visionModel, $images, $locale);
        if (!(bool)($request['has_image_payload'] ?? false)) {
            return [];
        }

        try {
            $raw = $this->getAiService()->generate(
                (string)($request['prompt'] ?? ''),
                $visionModel->getModelCode(),
                null,
                null,
                \is_array($request['params'] ?? null) ? $request['params'] : [],
                null,
                true
            );
        } catch (\Throwable) {
            return [];
        }

        $decoded = $this->getResponseJsonParser()->extractAndDecode($raw);
        if (!\is_array($decoded)) {
            return [];
        }

        return $this->normalizeInsights($decoded, $images);
    }

    private function getAiService(): AiService
    {
        return $this->aiService ?? ObjectManager::getInstance(AiService::class);
    }

    private function getAiModel(): AiModel
    {
        return $this->aiModel ?? ObjectManager::getInstance(AiModel::class);
    }

    private function getResponseJsonParser(): AiResponseJsonParser
    {
        return $this->responseJsonParser ?? ObjectManager::getInstance(AiResponseJsonParser::class);
    }

    private function resolveVisionModel(string $scenarioCode): ?AiModel
    {
        $preferredSupplier = '';
        $preferredImageModelCode = '';

        $imageModel = $this->getAiService()->resolveModel(
            null,
            $scenarioCode,
            AiModel::PRIMARY_MODALITY_TEXT_TO_IMAGE
        );
        if (\is_array($imageModel)) {
            $preferredSupplier = \trim((string)($imageModel['supplier'] ?? ''));
            $preferredImageModelCode = \trim((string)($imageModel['model_code'] ?? ''));
        }

        $bindings = $this->getAiService()->getAdapterModelBindings($scenarioCode);
        $explicitTextModelCode = \trim((string)($bindings[AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT] ?? ''));
        if ($explicitTextModelCode !== '') {
            $explicitModel = $this->loadActiveModelByCode($explicitTextModelCode);
            if ($explicitModel instanceof AiModel && $this->supportsVisionUnderstanding($explicitModel)) {
                return $explicitModel;
            }
        }

        $candidate = $this->findBestVisionTextModel($preferredSupplier, $preferredImageModelCode);
        if ($candidate instanceof AiModel) {
            return $candidate;
        }

        return $this->findBestVisionTextModel('', $preferredImageModelCode);
    }

    private function loadActiveModelByCode(string $modelCode): ?AiModel
    {
        $modelCode = \trim($modelCode);
        if ($modelCode === '') {
            return null;
        }

        /** @var AiModel $model */
        $model = $this->getAiModel()->reset()
            ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
            ->where(AiModel::schema_fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();

        return $model->getId() ? $model : null;
    }

    private function findBestVisionTextModel(string $preferredSupplier = '', string $preferredImageModelCode = ''): ?AiModel
    {
        $items = $this->getAiModel()->reset()
            ->where(AiModel::schema_fields_IS_ACTIVE, 1)
            ->where(AiModel::schema_fields_PRIMARY_MODALITY, AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT)
            ->select()
            ->fetch();

        $best = null;
        $bestScore = null;
        foreach ($this->iterableItems($items) as $candidate) {
            if (!$candidate instanceof AiModel || !$candidate->getId()) {
                continue;
            }
            if (!$this->supportsVisionUnderstanding($candidate)) {
                continue;
            }
            $score = $this->scoreVisionCandidate($candidate, $preferredSupplier, $preferredImageModelCode);
            if ($best === null || $bestScore === null || $score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }

    private function scoreVisionCandidate(AiModel $candidate, string $preferredSupplier, string $preferredImageModelCode): int
    {
        $score = 0;
        $candidateSupplier = \strtolower(\trim($candidate->getSupplier()));
        $preferredSupplier = \strtolower(\trim($preferredSupplier));
        if ($preferredSupplier !== '' && $candidateSupplier === $preferredSupplier) {
            $score += 1000;
        }
        if ((bool)$candidate->getIsDefault()) {
            $score += 200;
        }
        if ($candidate->hasCapability(AiModel::CAPABILITY_VISION)) {
            $score += 120;
        }

        $familyHint = $this->extractFamilyHint($preferredImageModelCode);
        $candidateCode = \strtolower(\trim($candidate->getModelCode()));
        if ($familyHint !== '' && \str_starts_with($candidateCode, $familyHint)) {
            $score += 80;
        }

        foreach (['gpt-4o', 'gpt-4.1', 'gemini', 'claude', 'qwen-vl', 'qwen2-vl', 'qwen2.5-vl', 'glm-4v', 'omni'] as $needle) {
            if (\str_contains($candidateCode, $needle)) {
                $score += 10;
                break;
            }
        }

        return $score;
    }

    private function extractFamilyHint(string $modelCode): string
    {
        $modelCode = \strtolower(\trim($modelCode));
        if ($modelCode === '') {
            return '';
        }

        $parts = \preg_split('/[^a-z0-9]+/i', $modelCode) ?: [];
        return \trim((string)($parts[0] ?? ''));
    }

    private function supportsVisionUnderstanding(AiModel $model): bool
    {
        if (!$model->supportsPrimaryModality(AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT)) {
            return false;
        }
        if ($model->hasCapability(AiModel::CAPABILITY_VISION)) {
            return true;
        }

        $haystack = \strtolower(
            \trim($model->getModelCode()) . ' ' . \trim((string)($model->getData(AiModel::schema_fields_NAME) ?? ''))
        );
        foreach (['vision', 'multimodal', 'multi-modal', 'image2text', 'image-to-text', '-vl', '_vl', '/vl', 'qwen-vl', 'qwen2-vl', 'qwen2.5-vl', 'glm-4v', 'gpt-4o', 'gpt-4.1', 'claude-3', 'omni'] as $needle) {
            if (\str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{name:string,url:string,path:string,mime_type:string}> $images
     * @return array{prompt:string,params:array<string,mixed>,has_image_payload:bool}
     */
    private function buildAnalysisRequest(AiModel $model, array $images, string $locale): array
    {
        $preparedImages = [];
        foreach ($images as $image) {
            $preparedImages[] = \array_replace($image, $this->resolveImageSource($image));
        }

        $instruction = $this->buildAnalysisInstruction($preparedImages, $locale);
        $supplier = \strtolower(\trim($model->getSupplier()));

        if ($supplier === 'google') {
            return $this->buildGeminiRequest($preparedImages, $instruction);
        }
        if ($supplier === 'anthropic') {
            return $this->buildAnthropicRequest($preparedImages, $instruction);
        }

        return $this->buildOpenAiLikeRequest($preparedImages, $instruction);
    }

    /**
     * @param list<array{name:string,url:string,path:string,mime_type:string,source_type?:string,data_url?:string,base64?:string}> $images
     */
    private function buildAnalysisInstruction(array $images, string $locale): string
    {
        $locale = \trim($locale) !== '' ? \trim($locale) : 'zh_Hans_CN';
        $imageLines = [];
        foreach ($images as $index => $image) {
            $imageLines[] = ($index + 1) . '. name=' . ($image['name'] ?? 'reference image')
                . '; url=' . ($image['url'] ?? '-');
        }

        return \implode("\n", [
            'You are a PageBuilder reference image analyst.',
            'Analyze the attached reference images before any style planning or text-to-image prompt planning.',
            'Return STRICT JSON only. No markdown, no explanation, no reasoning text.',
            'Locale: ' . $locale,
            'Reference images in order:',
            \implode("\n", $imageLines),
            'Schema:',
            '{"reference_image_insights":{"summary":"string","style_keywords":["string"],"color_palette":["#hex"],"layout_cues":["string"],"component_cues":["string"],"typography_cues":["string"],"do_not_use":["string"],"per_image":[{"name":"string","url":"string","style_tags":["string"],"dominant_colors":["#hex"],"layout_notes":["string"],"ui_notes":["string"]}],"visual_contract":{"hero_composition":{"nav":"string","headline":"string","media":"string","side_cards":"string","background":"string"},"cta_rule":{"primary_color":"string","label_intent":"string","must_be_above_fold":true},"asset_usage_rule":{"reference_image_role":"style_reference_only|hero_reference","max_same_image_usage":1,"forbid_repeated_raw_screenshot":true},"forbidden_visuals":["string"]}}}',
            'Example return shape (copy the structure, not the exact values; only report what is visible):',
            '{"reference_image_insights":{"summary":"dark premium landing style with split hero and warm CTA contrast","style_keywords":["dark felt surface","gold CTA","glass proof cards","wide hero crop"],"color_palette":["#111111","#D4AF37","#F6F0DC"],"layout_cues":["top nav above split hero","right media panel","staggered proof chips"],"component_cues":["pill CTA","rounded glass cards"],"typography_cues":["large bold headline","compact support text"],"do_not_use":["flat white SaaS cards"],"per_image":[{"name":"reference image","url":"-","style_tags":["premium","conversion"],"dominant_colors":["#111111","#D4AF37"],"layout_notes":["left copy and right media"],"ui_notes":["gold CTA and proof badges"]}],"visual_contract":{"hero_composition":{"nav":"compact top navigation","headline":"large left-aligned headline","media":"right product or scene visual","side_cards":"small proof cards near media","background":"dark layered surface"},"cta_rule":{"primary_color":"#D4AF37","label_intent":"primary conversion","must_be_above_fold":true},"asset_usage_rule":{"reference_image_role":"style_reference_only","max_same_image_usage":1,"forbid_repeated_raw_screenshot":true},"forbidden_visuals":["flat generic card grid"]}}}',
            'Rules:',
            '- per_image length must match reference image count and order.',
            '- style_keywords 4-10 concise items.',
            '- color_palette 3-8 hex colors.',
            '- focus on concrete visual implementation cues for website style planning and image generation.',
            '- visual_contract is a structured, executable visual constraint. Each field describes what MUST be preserved from the reference image into the final design.',
            '- hero_composition describes the above-fold layout structure.',
            '- cta_rule describes button color, label intent, and placement constraint.',
            '- asset_usage_rule describes how the reference image assets should be used.',
            '- forbidden_visuals lists visual patterns from the reference that MUST NOT be copied.',
        ]);
    }

    /**
     * @param list<array{name:string,url:string,path:string,mime_type:string,source_type?:string,data_url?:string,base64?:string}> $images
     * @return array{prompt:string,params:array<string,mixed>,has_image_payload:bool}
     */
    private function buildOpenAiLikeRequest(array $images, string $instruction): array
    {
        $content = [
            ['type' => 'text', 'text' => $instruction],
        ];
        $hasImagePayload = false;
        foreach ($images as $index => $image) {
            $content[] = ['type' => 'text', 'text' => 'Image ' . ($index + 1) . ': ' . ($image['name'] ?? 'reference image')];
            if (($image['source_type'] ?? '') === 'inline' && !empty($image['data_url'])) {
                $content[] = ['type' => 'image_url', 'image_url' => ['url' => (string)$image['data_url']]];
                $hasImagePayload = true;
                continue;
            }
            if (($image['source_type'] ?? '') === 'url' && $this->isHttpUrl((string)($image['url'] ?? ''))) {
                $content[] = ['type' => 'image_url', 'image_url' => ['url' => (string)$image['url']]];
                $hasImagePayload = true;
            }
        }

        return [
            'prompt' => '',
            'params' => [
                'messages' => [[
                    'role' => 'user',
                    'content' => $content,
                ]],
                'temperature' => 0,
                'max_tokens' => 1400,
                'disable_conversation_history' => true,
                'disable_conversation_persist' => true,
                'timeout' => 120,
            ],
            'has_image_payload' => $hasImagePayload,
        ];
    }

    /**
     * @param list<array{name:string,url:string,path:string,mime_type:string,source_type?:string,base64?:string}> $images
     * @return array{prompt:string,params:array<string,mixed>,has_image_payload:bool}
     */
    private function buildGeminiRequest(array $images, string $instruction): array
    {
        $parts = [
            ['text' => $instruction],
        ];
        $hasImagePayload = false;
        foreach ($images as $index => $image) {
            $parts[] = ['text' => 'Image ' . ($index + 1) . ': ' . ($image['name'] ?? 'reference image')];
            if (($image['source_type'] ?? '') === 'inline' && !empty($image['base64'])) {
                $parts[] = ['inlineData' => [
                    'mimeType' => (string)($image['mime_type'] ?? 'image/png'),
                    'data' => (string)$image['base64'],
                ]];
                $hasImagePayload = true;
                continue;
            }
            if (!empty($image['url'])) {
                $parts[] = ['text' => 'Image URL fallback: ' . (string)$image['url']];
            }
        }

        return [
            'prompt' => '',
            'params' => [
                'contents' => [[
                    'role' => 'user',
                    'parts' => $parts,
                ]],
                'temperature' => 0,
                'max_tokens' => 1400,
                'disable_conversation_history' => true,
                'disable_conversation_persist' => true,
                'timeout' => 120,
            ],
            'has_image_payload' => $hasImagePayload,
        ];
    }

    /**
     * @param list<array{name:string,url:string,path:string,mime_type:string,source_type?:string,base64?:string}> $images
     * @return array{prompt:string,params:array<string,mixed>,has_image_payload:bool}
     */
    private function buildAnthropicRequest(array $images, string $instruction): array
    {
        $content = [
            ['type' => 'text', 'text' => $instruction],
        ];
        $hasImagePayload = false;
        foreach ($images as $index => $image) {
            $content[] = ['type' => 'text', 'text' => 'Image ' . ($index + 1) . ': ' . ($image['name'] ?? 'reference image')];
            if (($image['source_type'] ?? '') === 'inline' && !empty($image['base64'])) {
                $content[] = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => (string)($image['mime_type'] ?? 'image/png'),
                        'data' => (string)$image['base64'],
                    ],
                ];
                $hasImagePayload = true;
                continue;
            }
            if (!empty($image['url'])) {
                $content[] = ['type' => 'text', 'text' => 'Image URL fallback: ' . (string)$image['url']];
            }
        }

        return [
            'prompt' => '',
            'params' => [
                'messages' => [[
                    'role' => 'user',
                    'content' => $content,
                ]],
                'temperature' => 0,
                'max_tokens' => 1400,
                'disable_conversation_history' => true,
                'disable_conversation_persist' => true,
                'timeout' => 120,
            ],
            'has_image_payload' => $hasImagePayload,
        ];
    }

    /**
     * @param array{name:string,url:string,path:string,mime_type:string} $image
     * @return array<string,string>
     */
    private function resolveImageSource(array $image): array
    {
        $mimeType = $this->normalizeMimeType((string)($image['mime_type'] ?? ''));
        $url = \trim((string)($image['url'] ?? ''));
        $bytes = $this->readImageBytes($image);
        if ($bytes !== '') {
            $mimeType = $this->detectMimeType($bytes, $mimeType);
            $base64 = \base64_encode($bytes);
            return [
                'source_type' => 'inline',
                'mime_type' => $mimeType,
                'base64' => $base64,
                'data_url' => 'data:' . $mimeType . ';base64,' . $base64,
            ];
        }
        if ($this->isHttpUrl($url)) {
            return [
                'source_type' => 'url',
                'mime_type' => $mimeType,
            ];
        }

        return [];
    }

    /**
     * @param array{name:string,url:string,path:string,mime_type:string} $image
     */
    private function readImageBytes(array $image): string
    {
        $path = \trim((string)($image['path'] ?? ''));
        if ($path !== '') {
            $absolutePath = $this->toAbsolutePath($path);
            if ($absolutePath !== '' && \is_file($absolutePath)) {
                $bytes = @\file_get_contents($absolutePath);
                if (\is_string($bytes) && $bytes !== '') {
                    return $bytes;
                }
            }
        }

        $url = \trim((string)($image['url'] ?? ''));
        if ($url === '') {
            return '';
        }
        if ($this->isDataUrl($url)) {
            return $this->decodeDataUrl($url);
        }
        if (\str_starts_with($url, '/')) {
            $absolutePath = $this->toAbsolutePath(\ltrim($url, '/'));
            if ($absolutePath !== '' && \is_file($absolutePath)) {
                $bytes = @\file_get_contents($absolutePath);
                if (\is_string($bytes) && $bytes !== '') {
                    return $bytes;
                }
            }
        }
        if ($this->isHttpUrl($url)) {
            return $this->downloadUrlBytes($url);
        }

        return '';
    }

    private function toAbsolutePath(string $path): string
    {
        $path = \str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, \ltrim($path, '/\\'));
        $basePath = \defined('BP') ? (string)BP : (\getcwd() ?: '');
        if ($basePath === '') {
            return '';
        }

        return \rtrim($basePath, '/\\') . \DIRECTORY_SEPARATOR . $path;
    }

    private function isHttpUrl(string $url): bool
    {
        return \preg_match('#^https?://#i', \trim($url)) === 1;
    }

    private function isDataUrl(string $url): bool
    {
        return \preg_match('#^data:[^;]+;base64,#i', \trim($url)) === 1;
    }

    private function decodeDataUrl(string $url): string
    {
        if (\preg_match('#^data:[^;]+;base64,(.+)$#i', \trim($url), $matches) !== 1) {
            return '';
        }

        $decoded = \base64_decode($matches[1], true);
        return $decoded === false ? '' : $decoded;
    }

    private function downloadUrlBytes(string $url): string
    {
        if (\function_exists('curl_init')) {
            $ch = \curl_init($url);
            if ($ch !== false) {
                \curl_setopt_array($ch, [
                    \CURLOPT_RETURNTRANSFER => true,
                    \CURLOPT_FOLLOWLOCATION => true,
                    \CURLOPT_CONNECTTIMEOUT => 10,
                    \CURLOPT_TIMEOUT => 20,
                ]);
                $bytes = \curl_exec($ch);
                $error = \curl_error($ch);
                $status = (int)\curl_getinfo($ch, \CURLINFO_HTTP_CODE);
                \curl_close($ch);
                if ($bytes !== false && $error === '' && ($status === 0 || ($status >= 200 && $status < 300))) {
                    return (string)$bytes;
                }
            }
        }

        $context = \stream_context_create([
            'http' => [
                'timeout' => 20,
                'follow_location' => 1,
            ],
        ]);
        $bytes = @\file_get_contents($url, false, $context);
        return \is_string($bytes) ? $bytes : '';
    }

    private function normalizeMimeType(string $mimeType): string
    {
        $mimeType = \strtolower(\trim($mimeType));
        return \in_array($mimeType, ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'], true)
            ? ($mimeType === 'image/jpg' ? 'image/jpeg' : $mimeType)
            : 'image/png';
    }

    private function detectMimeType(string $bytes, string $fallback): string
    {
        if (\function_exists('finfo_open')) {
            $finfo = \finfo_open(\FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = \finfo_buffer($finfo, $bytes);
                \finfo_close($finfo);
                if (\is_string($detected) && \str_starts_with($detected, 'image/')) {
                    return $this->normalizeMimeType($detected);
                }
            }
        }

        return $fallback;
    }

    /**
     * @param array<string,mixed> $decoded
     * @param list<array{name:string,url:string,path:string,mime_type:string}> $images
     * @return array<string,mixed>
     */
    private function normalizeInsights(array $decoded, array $images): array
    {
        $insights = \is_array($decoded['reference_image_insights'] ?? null)
            ? $decoded['reference_image_insights']
            : $decoded;

        if (!\is_array($insights)) {
            return [];
        }

        $visualContract = \is_array($insights['visual_contract'] ?? null) ? $insights['visual_contract'] : [];

        return [
            'summary' => \trim((string)($insights['summary'] ?? '')),
            'style_keywords' => $this->normalizeStringList($insights['style_keywords'] ?? [], 10),
            'color_palette' => $this->normalizeColorList($insights['color_palette'] ?? [], 8),
            'layout_cues' => $this->normalizeStringList($insights['layout_cues'] ?? [], 8),
            'component_cues' => $this->normalizeStringList($insights['component_cues'] ?? [], 8),
            'typography_cues' => $this->normalizeStringList($insights['typography_cues'] ?? [], 8),
            'do_not_use' => $this->normalizeStringList($insights['do_not_use'] ?? [], 8),
            'per_image' => $this->normalizePerImageInsights($insights['per_image'] ?? [], $images),
            'visual_contract' => $visualContract === [] ? [] : [
                'hero_composition' => \is_array($visualContract['hero_composition'] ?? null)
                    ? [
                        'nav' => \trim((string)($visualContract['hero_composition']['nav'] ?? '')),
                        'headline' => \trim((string)($visualContract['hero_composition']['headline'] ?? '')),
                        'media' => \trim((string)($visualContract['hero_composition']['media'] ?? '')),
                        'side_cards' => \trim((string)($visualContract['hero_composition']['side_cards'] ?? '')),
                        'background' => \trim((string)($visualContract['hero_composition']['background'] ?? '')),
                    ]
                    : [],
                'cta_rule' => \is_array($visualContract['cta_rule'] ?? null)
                    ? [
                        'primary_color' => \trim((string)($visualContract['cta_rule']['primary_color'] ?? '')),
                        'label_intent' => \trim((string)($visualContract['cta_rule']['label_intent'] ?? '')),
                        'must_be_above_fold' => (bool)($visualContract['cta_rule']['must_be_above_fold'] ?? true),
                    ]
                    : [],
                'asset_usage_rule' => \is_array($visualContract['asset_usage_rule'] ?? null)
                    ? [
                        'reference_image_role' => \trim((string)($visualContract['asset_usage_rule']['reference_image_role'] ?? 'style_reference_only')),
                        'max_same_image_usage' => (int)($visualContract['asset_usage_rule']['max_same_image_usage'] ?? 1),
                        'forbid_repeated_raw_screenshot' => (bool)($visualContract['asset_usage_rule']['forbid_repeated_raw_screenshot'] ?? true),
                    ]
                    : [],
                'forbidden_visuals' => $this->normalizeStringList($visualContract['forbidden_visuals'] ?? [], 6),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $values, int $limit): array
    {
        if (!\is_array($values)) {
            return [];
        }

        $normalized = [];
        foreach ($values as $value) {
            if (!\is_scalar($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
                continue;
            }
            $text = \trim((string)$value);
            if ($text === '' || \in_array($text, $normalized, true)) {
                continue;
            }
            $normalized[] = $text;
            if (\count($normalized) >= \max(1, $limit)) {
                break;
            }
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function normalizeColorList(mixed $values, int $limit): array
    {
        if (!\is_array($values)) {
            return [];
        }

        $normalized = [];
        foreach ($values as $value) {
            if (!\is_scalar($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
                continue;
            }
            $text = \trim((string)$value);
            if ($text === '') {
                continue;
            }
            if (\preg_match('/#?[0-9a-fA-F]{3,8}/', $text, $matches) !== 1) {
                continue;
            }
            $hex = '#' . \strtoupper(\ltrim($matches[0], '#'));
            if (\in_array($hex, $normalized, true)) {
                continue;
            }
            $normalized[] = $hex;
            if (\count($normalized) >= \max(1, $limit)) {
                break;
            }
        }

        return $normalized;
    }

    /**
     * @param list<array{name:string,url:string,path:string,mime_type:string}> $images
     * @return list<array<string,mixed>>
     */
    private function normalizePerImageInsights(mixed $values, array $images): array
    {
        $rows = \is_array($values) ? \array_values($values) : [];
        $normalized = [];
        foreach ($images as $index => $image) {
            $row = \is_array($rows[$index] ?? null) ? $rows[$index] : [];
            $normalized[] = [
                'name' => \trim((string)($row['name'] ?? $image['name'] ?? 'reference image')) ?: 'reference image',
                'url' => \trim((string)($row['url'] ?? $image['url'] ?? '')),
                'style_tags' => $this->normalizeStringList($row['style_tags'] ?? [], 8),
                'dominant_colors' => $this->normalizeColorList($row['dominant_colors'] ?? [], 6),
                'layout_notes' => $this->normalizeStringList($row['layout_notes'] ?? [], 6),
                'ui_notes' => $this->normalizeStringList($row['ui_notes'] ?? [], 6),
            ];
        }

        return $normalized;
    }

    /**
     * @return iterable<int|string,mixed>
     */
    private function iterableItems(mixed $items): iterable
    {
        if (\is_array($items)) {
            return $items;
        }
        if (\is_object($items) && \method_exists($items, 'getItems')) {
            $resolved = $items->getItems();
            return \is_array($resolved) ? $resolved : [];
        }

        return [];
    }
}
