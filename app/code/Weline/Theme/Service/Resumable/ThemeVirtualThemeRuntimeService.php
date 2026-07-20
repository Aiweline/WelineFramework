<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Resumable;

use Weline\Ai\Api\AiRuntimeInterface;
use Weline\Ai\Api\StyleRuntimeInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeVirtualLayout;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeVirtualLayoutService;
use Weline\Theme\Service\ThemeVirtualThemeManifestService;

/**
 * Application-level checkpoint implementation for Theme virtual-layout AI.
 *
 * It deliberately persists reproducible data (frozen targets, prompt,
 * generated source and version identity), rather than a PHP/Fiber call stack.
 * AI generation and version persistence are separately ledgered by the task
 * handler; this service never starts a task or owns an HTTP connection.
 */
final class ThemeVirtualThemeRuntimeService implements ThemeVirtualThemeRuntimeInterface
{
    private const ADAPTER_CODE = 'theme';
    private const DEFAULT_SCOPE = ThemeVirtualLayoutService::DEFAULT_SCOPE;
    private const MAX_SOURCE_BYTES = 1_048_576;
    private const MAX_INSTRUCTIONS_BYTES = 65_536;

    public function __construct(
        private readonly WelineTheme $welineTheme,
        private readonly ThemeVirtualThemeManifestService $manifestService,
        private readonly ThemeVirtualLayoutService $virtualLayoutService,
        private readonly RuntimeProviderResolver $runtimeProviderResolver,
    ) {
    }

    public function catalog(array $input, int $actorId): array
    {
        $temporarySkillCodes = $this->normalizeCodeList($input['temporary_skill_codes'] ?? $input['selected_skill_codes'] ?? []);
        $temporaryStyleCodes = $this->normalizeCodeList($input['temporary_style_codes'] ?? $input['selected_style_codes'] ?? []);

        return [
            'adapter_code' => self::ADAPTER_CODE,
            'skills' => $this->buildSkillCatalog($temporarySkillCodes),
            'styles' => $this->buildStyleCatalog($temporaryStyleCodes, $actorId),
        ];
    }

    public function freezeTaskInput(array $input, int $actorId): array
    {
        if (!$this->truthy($input['use_ai'] ?? true)) {
            throw new \InvalidArgumentException('Non-AI virtual layout creation is a short CRUD operation, not a runtime task.');
        }

        return $this->freezeInput($input, $actorId, true);
    }

    public function planTarget(array $input, array $target): array
    {
        $themeId = (int)($input['theme_id'] ?? 0);
        $area = $this->normalizeArea((string)($input['area'] ?? 'frontend'));
        $layoutType = $this->normalizeLayoutType((string)($target['layout_type'] ?? ''));
        $layoutOption = $this->normalizeLayoutOption((string)($target['layout_option'] ?? ''));
        $mode = $this->normalizeMode((string)($input['mode'] ?? 'create'));
        if ($themeId <= 0 || $layoutType === '' || $layoutOption === '' || $layoutOption === 'default' || $mode === '') {
            throw new \InvalidArgumentException('Frozen Theme virtual layout target is invalid.');
        }

        $manifest = $this->manifestService->build($themeId, $area, $layoutType, false);
        $reference = $this->loadReferenceLayoutSource($manifest, $layoutType, 'default');
        if ($reference['source_code'] === '') {
            throw new \RuntimeException((string)__('未找到默认布局源码，无法创建虚拟主题草稿'));
        }
        $this->assertSourceSize($reference['source_code'], 'reference layout source');

        $identity = $this->buildIdentity($input, $themeId, $area, $layoutType, $layoutOption);
        $currentSource = $this->virtualLayoutService->loadEditableSource($layoutType, $layoutOption, $identity)
            ?: $reference['source_code'];
        $this->assertSourceSize($currentSource, 'editable layout source');

        $styleSnapshot = is_array($input['style_snapshot'] ?? null) ? $input['style_snapshot'] : [];
        $prompt = $this->buildVirtualLayoutPrompt(
            $mode,
            $input,
            $manifest,
            $reference,
            $currentSource,
            $this->normalizeCodeList($input['selected_skill_codes'] ?? []),
            $this->normalizeCodeList($input['selected_style_codes'] ?? []),
            $styleSnapshot,
            is_array($input['block_context'] ?? null) ? $input['block_context'] : [],
        );
        $this->assertSourceSize($prompt, 'virtual layout prompt');

        return [
            'target' => [
                'key' => (string)($target['key'] ?? $this->targetKey($layoutType, $layoutOption)),
                'layout_type' => $layoutType,
                'layout_option' => $layoutOption,
            ],
            'theme_id' => $themeId,
            'area' => $area,
            'mode' => $mode,
            'request_id' => (string)($input['request_id'] ?? ''),
            'identity' => $identity,
            'reference' => $reference,
            'current_source' => $currentSource,
            'prompt' => $prompt,
            'selected_skill_codes' => $this->normalizeCodeList($input['selected_skill_codes'] ?? []),
            'selected_style_codes' => $this->normalizeCodeList($input['selected_style_codes'] ?? []),
            'style_snapshot' => $styleSnapshot,
            'block_context' => is_array($input['block_context'] ?? null) ? $input['block_context'] : [],
            'manifest_fingerprint' => (string)($manifest['fingerprint'] ?? ''),
            'manifest_coverage' => is_array($manifest['coverage'] ?? null) ? $manifest['coverage'] : [],
            'model_code' => $this->optionalString($input['model_code'] ?? null),
            'instructions' => $this->optionalString($input['instructions'] ?? null),
        ];
    }

    public function generateSource(array $plan, string $idempotencyKey, int $actorId): array
    {
        $runtime = $this->aiRuntime();
        if (!$runtime instanceof AiRuntimeInterface) {
            throw new \RuntimeException((string)__('AI 运行时不可用。'));
        }
        $prompt = trim((string)($plan['prompt'] ?? ''));
        if ($prompt === '') {
            throw new \InvalidArgumentException('Frozen Theme virtual layout prompt is missing.');
        }

        $styleSnapshot = is_array($plan['style_snapshot'] ?? null) ? $plan['style_snapshot'] : [];
        $response = $runtime->generate(
            $prompt,
            $this->optionalString($plan['model_code'] ?? null),
            self::ADAPTER_CODE,
            null,
            [
                'operation' => 'virtual_layout_' . (string)($plan['mode'] ?? 'create'),
                'layout_type' => (string)($plan['target']['layout_type'] ?? ''),
                'layout_option' => (string)($plan['target']['layout_option'] ?? ''),
                'selected_skill_codes' => $this->normalizeCodeList($plan['selected_skill_codes'] ?? []),
                'temporary_skill_codes' => $this->normalizeCodeList($plan['selected_skill_codes'] ?? []),
                'design_direction_mode' => $styleSnapshot !== [] ? StyleRuntimeInterface::MODE_MANUAL : StyleRuntimeInterface::MODE_AUTO,
                'design_direction_snapshot' => $styleSnapshot,
                'style_snapshot' => $styleSnapshot,
                'brief_description' => (string)($plan['instructions'] ?? ''),
                'disable_conversation_history' => true,
                'disable_conversation_persist' => true,
                'is_backend' => true,
                // Providers that implement idempotency receive the same key on
                // every recovery attempt. Providers without reconciliation are
                // deliberately failed closed by the handler.
                'idempotency_key' => $idempotencyKey,
                'resumable_idempotency_key' => $idempotencyKey,
            ],
            $actorId > 0 ? $actorId : null,
            true,
        );

        return $this->normalizeGeneratedSource($response, $idempotencyKey);
    }

    public function persistGenerated(
        array $plan,
        array $generated,
        string $taskId,
        string $targetKey,
        int $actorId,
    ): array {
        $source = trim((string)($generated['source_code'] ?? ''));
        if ($source === '') {
            throw new \InvalidArgumentException('Generated Theme virtual layout source is missing.');
        }
        $this->assertSourceSize($source, 'generated layout source');
        $identity = is_array($plan['identity'] ?? null) ? $plan['identity'] : [];
        $target = is_array($plan['target'] ?? null) ? $plan['target'] : [];
        $current = (string)($plan['current_source'] ?? '');
        $reference = is_array($plan['reference'] ?? null) ? $plan['reference'] : [];
        $payload = is_array($generated['payload'] ?? null) ? $generated['payload'] : [];

        $result = $this->virtualLayoutService->saveSourceVersion($identity, $source, [
            'source_type' => ThemeVirtualLayout::SOURCE_TYPE_AI,
            'is_ai_generated' => true,
            'ai_prompt' => (string)($plan['prompt'] ?? ''),
            'generation_meta' => [
                'mode' => (string)($plan['mode'] ?? 'create'),
                'request_id' => (string)($plan['request_id'] ?? ''),
                'runtime_task_id' => $taskId,
                'runtime_target_key' => $targetKey,
                'selected_skill_codes' => $this->normalizeCodeList($plan['selected_skill_codes'] ?? []),
                'selected_style_codes' => $this->normalizeCodeList($plan['selected_style_codes'] ?? []),
                'style_snapshot' => is_array($plan['style_snapshot'] ?? null) ? $plan['style_snapshot'] : [],
                'manifest_fingerprint' => (string)($plan['manifest_fingerprint'] ?? ''),
                'manifest_coverage' => is_array($plan['manifest_coverage'] ?? null) ? $plan['manifest_coverage'] : [],
                'reference' => [
                    'layout_type' => (string)($target['layout_type'] ?? ''),
                    'layout_option' => 'default',
                    'sha1' => sha1((string)($reference['source_code'] ?? '')),
                    'entry' => is_array($reference['entry'] ?? null) ? $reference['entry'] : [],
                ],
                'block_context' => is_array($plan['block_context'] ?? null) ? $plan['block_context'] : [],
                'source_changed' => sha1($source) !== sha1($current),
                'ai_payload_keys' => array_values(array_filter(array_keys($payload), 'is_string')),
                'external_idempotency_key' => (string)($generated['idempotency_key'] ?? ''),
            ],
            'validation' => [
                'valid' => true,
                'checks' => [
                    'default_reference_present' => true,
                    'stored_as_draft' => true,
                    'publish_required' => true,
                ],
            ],
            'reason' => $this->reasonForMode((string)($plan['mode'] ?? 'create')),
            'actor_id' => (string)$actorId,
        ], false);

        if (!($result['success'] ?? false)) {
            throw new \RuntimeException((string)($result['message'] ?? __('虚拟主题草稿保存失败')));
        }

        return $this->persistResult($result, $plan + ['generated_source' => $source], true);
    }

    public function findPersisted(array $plan, string $taskId, string $targetKey): ?array
    {
        $identity = is_array($plan['identity'] ?? null) ? $plan['identity'] : [];
        foreach ($this->virtualLayoutService->listVersionDetails($identity) as $detail) {
            if (!is_array($detail)) {
                continue;
            }
            $meta = is_array($detail['generation_meta'] ?? null) ? $detail['generation_meta'] : [];
            if ((string)($meta['runtime_task_id'] ?? '') !== $taskId
                || (string)($meta['runtime_target_key'] ?? '') !== $targetKey) {
                continue;
            }
            return [
                'success' => true,
                'asset_id' => (int)($detail['asset_id'] ?? 0),
                'version_id' => (int)($detail['version_id'] ?? 0),
                'draft_version_id' => (int)($detail['version_id'] ?? 0),
                'version_no' => (int)($detail['version_no'] ?? 0),
                'layout_type' => (string)($plan['target']['layout_type'] ?? ''),
                'layout_option' => (string)($plan['target']['layout_option'] ?? ''),
                'source_changed' => (bool)($meta['source_changed'] ?? false),
                'use_ai' => true,
                'mode' => (string)($plan['mode'] ?? 'create'),
                'reconciled_from_version' => true,
            ];
        }

        return null;
    }

    public function saveManualDraft(array $input, int $actorId): array
    {
        if ($this->truthy($input['use_ai'] ?? false)) {
            throw new \InvalidArgumentException('AI virtual layout creation must use theme.virtual_theme_generation.');
        }
        $frozen = $this->freezeInput($input, $actorId, false);
        $targets = is_array($frozen['targets'] ?? null) ? $frozen['targets'] : [];
        if (count($targets) !== 1) {
            throw new \InvalidArgumentException('Manual virtual layout save only supports one layout target.');
        }
        $plan = $this->planTarget($frozen, $targets[0]);
        $source = (string)($plan['current_source'] ?? '');
        $result = $this->virtualLayoutService->saveSourceVersion($plan['identity'], $source, [
            'source_type' => ThemeVirtualLayout::SOURCE_TYPE_VIRTUAL,
            'is_ai_generated' => false,
            'generation_meta' => [
                'mode' => 'manual_draft',
                'request_id' => (string)($plan['request_id'] ?? ''),
                'source_changed' => false,
            ],
            'validation' => ['valid' => true, 'checks' => ['stored_as_draft' => true]],
            'reason' => (string)__('保存虚拟布局源码草稿'),
            'actor_id' => (string)$actorId,
        ], false);
        if (!($result['success'] ?? false)) {
            return $result;
        }

        return [
            'success' => true,
            'message' => (string)__('虚拟布局草稿已保存'),
            'data' => $this->persistResult($result, $plan, false),
        ];
    }

    public function loadSource(array $input, int $actorId): array
    {
        $themeId = $this->resolveThemeId($input);
        $area = $this->normalizeArea((string)($input['area'] ?? 'frontend'));
        $layoutType = $this->normalizeLayoutType((string)($input['layout_type'] ?? $input['page_type'] ?? ThemeLayout::PAGE_TYPE_DEFAULT));
        $layoutOption = $this->normalizeLayoutOption((string)($input['layout_option'] ?? 'default')) ?: 'default';
        if ($themeId <= 0 || $layoutType === '') {
            return ['success' => false, 'message' => (string)__('缺少主题或布局身份')];
        }
        $identity = $this->buildIdentity($input, $themeId, $area, $layoutType, $layoutOption);
        $latest = $layoutOption !== 'default' ? $this->virtualLayoutService->loadLatestVersionDetails($identity) : null;
        if ($latest !== null) {
            return [
                'success' => true,
                'data' => $latest + [
                    'source' => 'theme_virtual_layout',
                    'editable' => true,
                    'versions' => $this->virtualLayoutService->listVersionDetails($identity),
                ],
            ];
        }
        $manifest = $this->manifestService->build($themeId, $area, $layoutType, false);
        $reference = $this->loadReferenceLayoutSource($manifest, $layoutType, 'default');
        if ($reference['source_code'] === '') {
            return ['success' => false, 'message' => (string)__('未找到默认布局源码')];
        }
        return [
            'success' => true,
            'data' => [
                'source' => 'default_theme',
                'editable' => false,
                'source_code' => $reference['source_code'],
                'identity' => $identity,
                'reference' => $reference['entry'],
                'versions' => [],
            ],
        ];
    }

    public function publishVersion(array $input, int $actorId): array
    {
        $versionId = (int)($input['version_id'] ?? $input['draft_version_id'] ?? 0);
        if ($versionId <= 0) {
            return ['success' => false, 'message' => (string)__('缺少 version_id')];
        }
        $published = $this->virtualLayoutService->publishVersion($versionId);
        return [
            'success' => $published,
            'message' => $published ? (string)__('虚拟布局版本已发布') : (string)__('虚拟布局版本发布失败'),
            'data' => $published ? $this->virtualLayoutService->loadVersionDetails($versionId) : null,
        ];
    }

    public function rollbackVersion(array $input, int $actorId): array
    {
        return $this->virtualLayoutService->rollbackPublishedVersion(
            (int)($input['asset_id'] ?? 0),
            (int)($input['version_id'] ?? $input['target_version_id'] ?? 0),
            [
                'reason' => (string)($input['reason'] ?? __('回滚虚拟主题布局版本')),
                'actor_id' => (string)$actorId,
            ],
        );
    }

    /** @return array<string,mixed> */
    private function freezeInput(array $input, int $actorId, bool $runtime): array
    {
        $requestId = trim((string)($input['request_id'] ?? ''));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,95}$/', $requestId) !== 1) {
            throw new \InvalidArgumentException('Invalid Theme virtual layout request_id.');
        }
        $mode = $this->normalizeMode((string)($input['action'] ?? $input['mode'] ?? 'create'));
        if ($mode === '') {
            throw new \InvalidArgumentException((string)__('不支持的 AI block 操作'));
        }
        $themeId = $this->resolveThemeId($input);
        $area = $this->normalizeArea((string)($input['area'] ?? 'frontend'));
        if ($themeId <= 0) {
            throw new \InvalidArgumentException((string)__('缺少参照主题'));
        }
        if ($mode === 'regenerate_images') {
            $assetRef = trim((string)($input['asset_ref'] ?? $input['asset_url'] ?? ''));
            if ($assetRef !== '' && !$this->isVirtualThemeOwnedAsset($assetRef)) {
                throw new \InvalidArgumentException((string)__('只能重新生成虚拟主题拥有的图片资源'));
            }
        }
        $all = $this->shouldCreateAllLayoutTypes($input);
        $layoutTypes = $all
            ? $this->collectDefaultLayoutTypes($themeId, $area)
            : [$this->normalizeLayoutType((string)($input['layout_type'] ?? $input['page_type'] ?? ThemeLayout::PAGE_TYPE_DEFAULT))];
        $layoutTypes = array_values(array_filter(array_unique($layoutTypes), static fn(string $type): bool => $type !== ''));
        if ($layoutTypes === []) {
            throw new \InvalidArgumentException((string)__('参照主题没有可生成的默认布局类型'));
        }
        $currentOption = $this->normalizeLayoutOption((string)($input['layout_option'] ?? 'default')) ?: 'default';
        $sharedOption = $all ? $this->resolveBatchLayoutOption($mode, $input) : '';
        $targets = [];
        foreach ($layoutTypes as $layoutType) {
            $layoutOption = $all
                ? $sharedOption
                : ($currentOption !== 'default' ? $currentOption : $this->deriveVirtualLayoutOption($layoutType, $mode, $input));
            $targets[] = [
                'key' => $this->targetKey($layoutType, $layoutOption),
                'layout_type' => $layoutType,
                'layout_option' => $layoutOption,
            ];
        }
        $instructions = trim((string)($input['instructions'] ?? $input['prompt'] ?? $input['description'] ?? ''));
        if (strlen($instructions) > self::MAX_INSTRUCTIONS_BYTES) {
            throw new \InvalidArgumentException('Theme virtual layout instructions are too large.');
        }
        $styleCodes = $this->normalizeCodeList($input['selected_style_codes'] ?? $input['style_codes'] ?? []);
        return [
            'request_id' => $requestId,
            'mode' => $mode,
            'theme_id' => $themeId,
            'area' => $area,
            'targets' => $targets,
            'selected_skill_codes' => $this->normalizeCodeList($input['selected_skill_codes'] ?? $input['skill_codes'] ?? []),
            'selected_style_codes' => $styleCodes,
            'style_snapshot' => $this->resolveStyleSnapshot($styleCodes, $actorId),
            'block_context' => $this->buildBlockContext($input),
            'instructions' => $instructions,
            'model_code' => $this->optionalString($input['model_code'] ?? null),
            'scope' => (string)($input['scope'] ?? self::DEFAULT_SCOPE),
            'target_type' => (string)($input['target_type'] ?? ThemeVirtualLayout::TARGET_GLOBAL),
            'target_id' => (int)($input['target_id'] ?? 0),
            'name' => (string)($input['name'] ?? ''),
            'description' => (string)($input['description'] ?? ''),
            'actor_id' => max(0, $actorId),
            'runtime' => $runtime,
        ];
    }

    /** @param array<string,mixed> $result @param array<string,mixed> $plan @return array<string,mixed> */
    private function persistResult(array $result, array $plan, bool $useAi): array
    {
        return $result + [
            'draft_version_id' => (int)($result['version_id'] ?? 0),
            'layout_type' => (string)($plan['target']['layout_type'] ?? ''),
            'layout_option' => (string)($plan['target']['layout_option'] ?? ''),
            'source_changed' => $useAi && sha1((string)($plan['current_source'] ?? '')) !== sha1((string)($plan['generated_source'] ?? '')),
            'use_ai' => $useAi,
            'mode' => (string)($plan['mode'] ?? ($useAi ? 'create' : 'manual_draft')),
        ];
    }

    /** @return array{entry:array<string,mixed>,source_code:string} */
    private function loadReferenceLayoutSource(array $manifest, string $layoutType, string $layoutOption): array
    {
        $entry = $this->findLayoutEntry($manifest, $layoutType, $layoutOption)
            ?: $this->findLayoutEntry($manifest, $layoutType, 'default')
            ?: [];
        $path = (string)($entry['absolute_path'] ?? '');
        $source = $path !== '' && is_file($path) ? (string)file_get_contents($path) : '';
        return ['entry' => $entry, 'source_code' => $source];
    }

    private function findLayoutEntry(array $manifest, string $layoutType, string $layoutOption): ?array
    {
        $layoutType = $this->normalizeLayoutType($layoutType);
        $layoutOption = $this->normalizeLayoutOption($layoutOption);
        $candidates = [
            'layouts/' . $layoutType . '/' . $layoutOption,
            'layouts/' . $layoutType . '/' . $layoutOption . '.phtml',
            $layoutType . '/' . $layoutOption . '.phtml',
        ];
        foreach (($manifest['files']['layouts'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (in_array(trim((string)($entry['logical_path'] ?? ''), '/'), $candidates, true)
                || in_array(trim((string)($entry['relative_path'] ?? ''), '/'), $candidates, true)) {
                return $entry;
            }
        }
        return null;
    }

    /** @return list<string> */
    private function collectDefaultLayoutTypes(int $themeId, string $area): array
    {
        $manifest = $this->manifestService->build($themeId, $area, ThemeLayout::PAGE_TYPE_DEFAULT, false);
        $types = [];
        foreach (($manifest['files']['layouts'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $type = $this->defaultLayoutTypeFromEntry($entry);
            if ($type !== '') {
                $types[$type] = true;
            }
        }
        $types = array_keys($types);
        sort($types, SORT_STRING);
        return array_values($types);
    }

    private function defaultLayoutTypeFromEntry(array $entry): string
    {
        foreach (['logical_path', 'relative_path'] as $key) {
            $path = trim(str_replace('\\', '/', (string)($entry[$key] ?? '')), '/');
            if ($path === '') {
                continue;
            }
            $path = (string)preg_replace('/\.[^.\/]+$/', '', $path);
            $segments = array_values(array_filter(explode('/', $path), static fn(string $segment): bool => $segment !== ''));
            if (($segments[0] ?? '') === 'layouts') {
                array_shift($segments);
            }
            $last = end($segments);
            if ($last !== 'default' || count($segments) < 2) {
                continue;
            }
            $layoutType = $this->normalizeLayoutType((string)$segments[count($segments) - 2]);
            if ($layoutType !== '') {
                return $layoutType;
            }
        }
        return '';
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function buildIdentity(array $input, int $themeId, string $area, string $layoutType, string $layoutOption): array
    {
        $name = trim((string)($input['name'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        return [
            'theme_id' => $themeId,
            'area' => $area,
            'layout_type' => $layoutType,
            'layout_option' => $layoutOption,
            'scope' => (string)($input['scope'] ?? self::DEFAULT_SCOPE),
            'target_type' => (string)($input['target_type'] ?? ThemeVirtualLayout::TARGET_GLOBAL),
            'target_id' => (int)($input['target_id'] ?? 0),
            'name' => $name !== '' ? $name : 'AI ' . ucfirst($layoutType),
            'description' => $description !== '' ? $description : (string)__('AI 虚拟主题布局草稿'),
            'metadata' => [
                'virtual_theme' => true,
                'source_of_truth' => 'theme_virtual_layout',
                'reference_layout_option' => 'default',
                'request_id' => (string)($input['request_id'] ?? ''),
            ],
        ];
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $manifest @param array{entry:array<string,mixed>,source_code:string} $reference @param list<string> $skillCodes @param list<string> $styleCodes @param array<string,mixed> $styleSnapshot @param array<string,mixed> $blockContext */
    private function buildVirtualLayoutPrompt(string $mode, array $payload, array $manifest, array $reference, string $currentSource, array $skillCodes, array $styleCodes, array $styleSnapshot, array $blockContext): string
    {
        $instructions = trim((string)($payload['instructions'] ?? $payload['description'] ?? ''));
        return "Create or update one Weline Theme virtual layout draft.\n"
            . "Operation: {$mode}\n"
            . "User instructions:\n" . ($instructions !== '' ? $instructions : '-') . "\n\n"
            . "Selected skill codes: " . json_encode($skillCodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
            . "Selected design direction/style codes: " . json_encode($styleCodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
            . "Selected design direction snapshot:\n" . json_encode($styleSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n"
            . "Block hover context:\n" . json_encode($blockContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n"
            . "Default theme manifest summary. Treat this as the source of truth and preserve every capability the default theme exposes unless explicitly asked otherwise:\n"
            . json_encode($this->manifestPromptSummary($manifest), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n"
            . "Reference default layout source. The virtual layout must fully respect its slots, hooks, metadata, variables, and render conventions:\n<<<DEFAULT_LAYOUT_SOURCE\n"
            . $reference['source_code'] . "\nDEFAULT_LAYOUT_SOURCE\n\n"
            . "Current editable layout source:\n<<<CURRENT_LAYOUT_SOURCE\n"
            . $currentSource . "\nCURRENT_LAYOUT_SOURCE\n\n"
            . "Return exactly one JSON object with keys: source_code, name, description, asset_rewrites, notes.\n"
            . "source_code must contain the complete final .phtml virtual layout source. Do not output markdown or code fences.\n"
            . "Keep only layout skeleton, slots, hooks, taglib markup, PHP variables, markup, and styles. Do not include <script> tags, addEventListener, fetch, XMLHttpRequest, axios, EventSource, or new frontend request logic.\n";
    }

    /** @return array<string,mixed> */
    private function normalizeGeneratedSource(string $response, string $idempotencyKey): array
    {
        $decoded = $this->extractJsonPayload($response);
        $source = '';
        if (is_array($decoded)) {
            foreach (['source_code', 'layout_source', 'source'] as $key) {
                if (is_scalar($decoded[$key] ?? null) && trim((string)$decoded[$key]) !== '') {
                    $source = (string)$decoded[$key];
                    break;
                }
            }
        }
        if ($source === '' && is_array($decoded)) {
            throw new \RuntimeException((string)__('AI 返回内容缺少 source_code 字段。'));
        }
        if ($source === '') {
            $source = trim($response);
        }
        $payload = is_array($decoded) ? $decoded : ['raw_response_sha1' => sha1($response)];
        $normalized = $this->normalizeAiVirtualLayoutSource($source);
        if ($normalized !== trim($source)) {
            $payload['_layout_runtime_sanitized'] = true;
        }
        if ($normalized === '') {
            throw new \RuntimeException((string)__('AI 未返回虚拟主题源码。'));
        }
        $this->assertSourceSize($normalized, 'generated layout source');
        return ['source_code' => $normalized, 'payload' => $payload, 'idempotency_key' => $idempotencyKey];
    }

    private function normalizeAiVirtualLayoutSource(string $source): string
    {
        $source = trim($source);
        if (str_starts_with($source, '```')) {
            $source = preg_replace('/^```[a-zA-Z0-9_-]*\s*/', '', $source) ?? $source;
            $source = preg_replace('/\s*```$/', '', $source) ?? $source;
        }
        $cleaned = preg_replace('/<script\b[^>]*>.*?<\/script\s*>/is', '', $source) ?? $source;
        $cleaned = preg_replace('/<script\b[^>]*\/\s*>/is', '', $cleaned) ?? $cleaned;
        return trim($cleaned);
    }

    /** @return array<string,mixed>|null */
    private function extractJsonPayload(string $response): ?array
    {
        $text = trim($response);
        if ($text === '') {
            return null;
        }
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-zA-Z0-9_-]*\s*/', '', $text) ?? $text;
            $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        }
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @return array<string,mixed> */
    private function manifestPromptSummary(array $manifest): array
    {
        $files = [];
        foreach (($manifest['files'] ?? []) as $category => $entries) {
            if (!is_array($entries)) {
                continue;
            }
            $files[$category] = array_map(static fn(array $entry): array => [
                'logical_path' => (string)($entry['logical_path'] ?? ''),
                'relative_path' => (string)($entry['relative_path'] ?? ''),
                'sha1' => (string)($entry['sha1'] ?? ''),
                'bytes' => (int)($entry['bytes'] ?? 0),
                'slot_ids' => is_array($entry['slot_ids'] ?? null) ? $entry['slot_ids'] : [],
                'asset_ref_count' => is_array($entry['asset_refs'] ?? null) ? count($entry['asset_refs']) : 0,
                'coverage_state' => (string)($entry['coverage_state'] ?? ''),
            ], $entries);
        }
        return [
            'theme' => $manifest['theme'] ?? [],
            'fingerprint' => (string)($manifest['fingerprint'] ?? ''),
            'summary' => $manifest['summary'] ?? [],
            'coverage' => $manifest['coverage'] ?? [],
            'extraction_quality' => $manifest['extraction_quality'] ?? [],
            'slots' => $manifest['slots'] ?? [],
            'asset_refs' => $manifest['asset_refs'] ?? [],
            'files' => $files,
        ];
    }

    /** @return array<string,mixed> */
    private function buildBlockContext(array $payload): array
    {
        return [
            'layout_id' => (string)($payload['layout_id'] ?? ''),
            'slot_id' => (string)($payload['slot_id'] ?? ''),
            'widget_code' => (string)($payload['widget_code'] ?? ''),
            'widget_type' => (string)($payload['widget_type'] ?? ''),
            'asset_ref' => (string)($payload['asset_ref'] ?? $payload['asset_url'] ?? ''),
            'instructions' => (string)($payload['instructions'] ?? $payload['prompt'] ?? ''),
        ];
    }

    private function isVirtualThemeOwnedAsset(string $assetRef): bool
    {
        $normalized = strtolower(str_replace('\\', '/', trim($assetRef)));
        foreach (['/media/theme/virtual/', '/media/virtual-theme/', '/theme-virtual/', '/theme_virtual/', 'pub/media/theme/virtual/', 'media/theme/virtual/'] as $needle) {
            if ($normalized !== '' && str_contains($normalized, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function resolveThemeId(array $input): int
    {
        $themeId = (int)($input['theme_id'] ?? 0);
        if ($themeId > 0) {
            return $themeId;
        }
        $theme = clone $this->welineTheme;
        $theme->clearData()->clearQuery()->getActiveTheme($this->normalizeArea((string)($input['area'] ?? 'frontend')));
        return (int)$theme->getId();
    }

    private function resolveBatchLayoutOption(string $mode, array $payload): string
    {
        $requested = $this->normalizeLayoutOption((string)($payload['layout_option'] ?? ''));
        if ($requested !== '' && !in_array($requested, ['default', 'all', 'all_types', 'all_layout_types'], true)) {
            return $requested;
        }
        return $this->normalizeLayoutOption('ai-theme-' . substr(sha1((string)($payload['request_id'] ?? $mode)), 0, 10));
    }

    private function deriveVirtualLayoutOption(string $layoutType, string $mode, array $payload): string
    {
        return $this->normalizeLayoutOption('ai-' . $layoutType . '-' . substr(sha1((string)($payload['request_id'] ?? $mode)), 0, 10));
    }

    private function targetKey(string $layoutType, string $layoutOption): string
    {
        return 'layout_' . substr(sha1($layoutType . '|' . $layoutOption), 0, 24);
    }

    private function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim(str_replace('-', '_', $mode)));
        return match ($mode) {
            'create', 'ai_create' => 'create',
            'ai_edit', 'edit', 'refine' => 'edit',
            'ai_rebuild', 'rebuild' => 'rebuild',
            'ai_image', 'image', 'regenerate_image', 'regenerate_images' => 'regenerate_images',
            default => '',
        };
    }

    private function reasonForMode(string $mode): string
    {
        return match ($mode) {
            'edit' => (string)__('AI 编辑 block 后生成虚拟主题草稿'),
            'rebuild' => (string)__('AI 重建 block 后生成虚拟主题草稿'),
            'regenerate_images' => (string)__('AI 重新生成 block 图片资源后生成虚拟主题草稿'),
            default => (string)__('AI 生成虚拟主题草稿'),
        };
    }

    private function shouldCreateAllLayoutTypes(array $payload): bool
    {
        $value = strtolower(trim((string)($payload['layout_type'] ?? $payload['page_type'] ?? '')));
        return $this->truthy($payload['all_layout_types'] ?? false)
            || in_array($value, ['all', '*', 'all_types', 'all_layout_types'], true);
    }

    /** @return list<string> */
    private function normalizeCodeList(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : preg_split('/[\s,;]+/', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }
        $codes = [];
        foreach ($raw as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $code = trim((string)$item);
            if ($code !== '' && strlen($code) <= 128 && !in_array($code, $codes, true)) {
                $codes[] = $code;
            }
            if (count($codes) >= 50) {
                break;
            }
        }
        return $codes;
    }

    /** @return array<string,mixed> */
    private function buildSkillCatalog(array $temporarySkillCodes): array
    {
        try {
            return $this->styleRuntime()?->buildSkillCatalog(self::ADAPTER_CODE, $temporarySkillCodes, false) ?? [
                'items' => [], 'default_skill_codes' => [], 'warnings' => [(string)__('AI 查询入口不可用。')],
            ];
        } catch (\Throwable $throwable) {
            return ['items' => [], 'default_skill_codes' => [], 'warnings' => [$throwable->getMessage()]];
        }
    }

    /** @return array<string,mixed> */
    private function buildStyleCatalog(array $temporaryStyleCodes, int $actorId): array
    {
        try {
            return $this->styleRuntime()?->buildStyleCatalog(self::ADAPTER_CODE, $temporaryStyleCodes, $actorId, false) ?? [
                'items' => [], 'default_style_codes' => [], 'manual_style_codes' => [], 'warnings' => [(string)__('AI 查询入口不可用。')],
            ];
        } catch (\Throwable $throwable) {
            return ['items' => [], 'default_style_codes' => [], 'manual_style_codes' => [], 'warnings' => [$throwable->getMessage()]];
        }
    }

    /** @return array<string,mixed> */
    private function resolveStyleSnapshot(array $styleCodes, int $actorId): array
    {
        if ($styleCodes === []) {
            return [];
        }
        try {
            return $this->styleRuntime()?->resolveStyleSnapshot($styleCodes, $actorId, 'Theme virtual theme runtime selection') ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function styleRuntime(): ?StyleRuntimeInterface
    {
        $runtime = $this->runtimeProviderResolver->resolve(StyleRuntimeInterface::class);
        return $runtime instanceof StyleRuntimeInterface ? $runtime : null;
    }

    private function aiRuntime(): ?AiRuntimeInterface
    {
        $runtime = $this->runtimeProviderResolver->resolve(AiRuntimeInterface::class);
        return $runtime instanceof AiRuntimeInterface ? $runtime : null;
    }

    private function normalizeArea(string $area): string
    {
        return strtolower(trim($area)) === 'backend' ? 'backend' : 'frontend';
    }

    private function normalizeLayoutType(string $layoutType): string
    {
        $layoutType = strtolower(trim($layoutType));
        return trim((string)(preg_replace('/[^a-z0-9_-]+/', '_', $layoutType) ?? ''), '_');
    }

    private function normalizeLayoutOption(string $layoutOption): string
    {
        return $this->virtualLayoutService->normalizeLayoutOption($layoutOption);
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function optionalString(mixed $value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private function assertSourceSize(string $source, string $label): void
    {
        if (strlen($source) > self::MAX_SOURCE_BYTES) {
            throw new \InvalidArgumentException('Theme virtual ' . $label . ' exceeds checkpoint size limit.');
        }
    }
}
