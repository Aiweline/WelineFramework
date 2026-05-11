<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AI\AiResponseJsonParser;
use GuoLaiRen\PageBuilder\Service\AI\MockPage;
use GuoLaiRen\PageBuilder\Service\AI\PreviewRenderer;
use Weline\Ai\Service\AiService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;

class AiSiteBlockPartialPatchService
{
    private const META_TEMPLATE_PHTML = '_pb_server_template_phtml';
    private const META_COMPONENT_CODE = '_pb_server_component_code';
    private const META_REGION = '_pb_server_region';
    private const HISTORY_LIMIT = 3;

    public function __construct(
        private readonly ?AiSiteAgentSessionService $sessionService = null,
        private readonly ?AiSiteScopeCompatibilityService $scopeCompatibilityService = null,
        private readonly ?AiSiteBuildTaskService $buildTaskService = null,
        private readonly ?AiSiteHtmlBlocksBuildService $htmlBlocksBuildService = null,
        private readonly ?AiSiteVirtualThemeService $virtualThemeService = null,
        private readonly ?AiResponseJsonParser $jsonParser = null,
        private readonly ?AiService $aiService = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function readCurrentBlock(int $adminId, string $publicId, string $pageType, string $blockId): array
    {
        $publicId = \trim($publicId);
        $pageType = \trim($pageType);
        $blockId = \trim($blockId);
        if ($adminId <= 0 || $publicId === '' || $pageType === '' || $blockId === '') {
            return $this->error('INVALID_PARAMS', 'Missing public_id, page_type, or block_id.');
        }

        $session = $this->sessionService()->loadByPublicId($publicId, $adminId);
        if (!$session instanceof AiSiteAgentSession) {
            return $this->error('SESSION_NOT_FOUND', 'Session not found or not accessible.');
        }

        $scope = $this->normalizeScope(
            $this->sessionService()->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );

        return $this->readCurrentBlockFromScope($scope, $pageType, $blockId);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function readCurrentBlockFromScope(array $scope, string $pageType, string $blockId): array
    {
        $pageType = \trim($pageType);
        $blockId = \trim($blockId);
        if ($pageType === '' || $blockId === '') {
            return $this->error('INVALID_PARAMS', 'Missing page_type or block_id.');
        }

        $sharedRegion = $this->resolveSharedComponentRegionForBlockId($pageType, $blockId);
        if ($sharedRegion !== '') {
            $sharedBlock = $this->buildSharedComponentBlockFromScope($scope, $sharedRegion, $blockId);
            if ($sharedBlock !== null) {
                $actualBlockId = \trim((string)($sharedBlock['block_id'] ?? $blockId));
                $componentCode = $this->resolveBlockComponentCode($sharedBlock);

                return [
                    'success' => true,
                    'source' => 'shared_components.' . $sharedRegion,
                    'page_type' => $pageType,
                    'block_id' => $actualBlockId,
                    'requested_block_id' => $blockId,
                    'component_code' => $componentCode,
                    'index' => 0,
                    'block' => $sharedBlock,
                    'type' => (string)($sharedBlock['type'] ?? ''),
                    'config' => \is_array($sharedBlock['config'] ?? null) ? $sharedBlock['config'] : [],
                    'html' => (string)($sharedBlock['html'] ?? ''),
                    'field_schema' => \is_array($sharedBlock['field_schema'] ?? null) ? $sharedBlock['field_schema'] : [],
                    'template_metadata' => $this->extractTemplateMetadata($sharedBlock),
                    'page_context' => $this->buildPageContext($scope, $pageType),
                    'layout_context' => $this->buildLayoutContext($scope, $pageType, $componentCode, $actualBlockId),
                ];
            }
        }

        $virtualPages = $this->buildTargetVirtualPagesByType($scope, $pageType);
        $virtualPage = \is_array($virtualPages[$pageType] ?? null) ? $virtualPages[$pageType] : [];
        $blocks = \is_array($virtualPage['blocks'] ?? null) ? $virtualPage['blocks'] : [];
        $match = $this->findBlockInBlocks($blocks, $blockId);
        $source = 'virtual_pages_by_type';

        if ($match === null) {
            return $this->error('BLOCK_NOT_FOUND', 'Current block not found.', [
                'page_type' => $pageType,
                'block_id' => $blockId,
                'available_block_ids' => $this->collectBlockIds($blocks),
            ]);
        }

        $block = \is_array($match['block'] ?? null) ? $match['block'] : [];
        $actualBlockId = \trim((string)($block['block_id'] ?? $blockId));
        $componentCode = $this->resolveBlockComponentCode($block);

        return [
            'success' => true,
            'source' => $source,
            'page_type' => $pageType,
            'block_id' => $actualBlockId,
            'requested_block_id' => $blockId,
            'component_code' => $componentCode,
            'index' => (int)($match['index'] ?? -1),
            'block' => $block,
            'type' => (string)($block['type'] ?? ''),
            'config' => \is_array($block['config'] ?? null) ? $block['config'] : [],
            'html' => (string)($block['html'] ?? ''),
            'field_schema' => \is_array($block['field_schema'] ?? null) ? $block['field_schema'] : [],
            'template_metadata' => $this->extractTemplateMetadata($block),
            'page_context' => $this->buildPageContext($scope, $pageType),
            'layout_context' => $this->buildLayoutContext($scope, $pageType, $componentCode, $actualBlockId),
        ];
    }

    /**
     * @param array<string, mixed> $currentBlock
     * @param array<string, mixed> $replacementBlock
     * @return array{valid:bool,errors:list<string>,top_keys:list<string>}
     */
    public function validateReplacementBlock(array $currentBlock, array $replacementBlock): array
    {
        $candidate = \is_array($replacementBlock['block'] ?? null) ? $replacementBlock['block'] : $replacementBlock;
        $topKeys = \array_values(\array_map('strval', \array_keys($replacementBlock)));
        $errors = [];

        $currentBlockId = \trim((string)($currentBlock['block_id'] ?? ''));
        $candidateBlockId = \trim((string)($candidate['block_id'] ?? ''));
        if ($currentBlockId === '') {
            $errors[] = 'current_block.block_id is missing';
        }
        if ($candidateBlockId === '') {
            $errors[] = 'replacement.block_id is missing';
        } elseif ($currentBlockId !== '' && $candidateBlockId !== $currentBlockId) {
            $errors[] = 'replacement.block_id must match current block_id';
        }

        if (\trim((string)($candidate['type'] ?? '')) === '') {
            $errors[] = 'replacement.type is missing';
        }
        if (!\is_array($candidate['config'] ?? null)) {
            $errors[] = 'replacement.config must be an object';
        }
        if (!\array_key_exists('html', $candidate) || !\is_string($candidate['html']) || \trim($candidate['html']) === '') {
            $errors[] = 'replacement.html must be a non-empty string';
        }
        if (!\is_array($candidate['field_schema'] ?? null)) {
            $errors[] = 'replacement.field_schema must be an object';
        } elseif (!$this->isLegalFieldSchema($candidate['field_schema'])) {
            $errors[] = 'replacement.field_schema has an invalid shape';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'top_keys' => $topKeys,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $replacementBlock
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function applyReplacementBlockToScope(
        array $scope,
        string $pageType,
        string $blockId,
        array $replacementBlock,
        array $metadata = []
    ): array {
        $pageType = \trim($pageType);
        $blockId = \trim($blockId);
        $read = $this->readCurrentBlockFromScope($scope, $pageType, $blockId);
        if (empty($read['success'])) {
            return $read;
        }

        $currentBlock = \is_array($read['block'] ?? null) ? $read['block'] : [];
        $candidate = $this->normalizeReplacementBlock($currentBlock, $replacementBlock);
        $validation = $this->validateReplacementBlock($currentBlock, $candidate);
        if (empty($validation['valid'])) {
            return $this->error('BLOCK_VALIDATION_FAILED', 'Replacement block failed validation.', [
                'page_type' => $pageType,
                'block_id' => (string)($read['block_id'] ?? $blockId),
                'top_keys' => $validation['top_keys'],
                'errors' => $validation['errors'],
            ]);
        }

        $renderValidation = $this->validateReplacementRenderable($candidate);
        if (empty($renderValidation['valid'])) {
            return $this->error('BLOCK_RENDER_FAILED', 'Replacement block cannot be rendered.', [
                'page_type' => $pageType,
                'block_id' => (string)($read['block_id'] ?? $blockId),
                'top_keys' => $validation['top_keys'],
                'errors' => $renderValidation['errors'],
            ]);
        }

        $sharedRegion = $this->resolveSharedComponentRegionForBlockId(
            $pageType,
            (string)($read['component_code'] ?? $read['block_id'] ?? $blockId)
        );
        if ($sharedRegion !== '') {
            $createdAt = \date('Y-m-d H:i:s');
            $beforeBlock = $currentBlock;
            $scope = $this->syncSharedComponentReplacement($scope, $sharedRegion, $candidate);
            $scope['preview_page_type'] = $pageType;
            $scope['build_summary'] = \array_replace(
                \is_array($scope['build_summary'] ?? null) ? $scope['build_summary'] : [],
                ['task_summary' => $this->safeSummarize($scope)]
            );
            $scope['block_patch_history'] = $this->appendPatchHistory(
                \is_array($scope['block_patch_history'] ?? null) ? $scope['block_patch_history'] : [],
                $pageType,
                (string)($candidate['block_id'] ?? $blockId),
                $beforeBlock,
                $candidate,
                $metadata,
                $createdAt
            );
            $this->persistSharedComponentReplacement($scope, $sharedRegion, $candidate);

            return [
                'success' => true,
                'page_type' => $pageType,
                'block_id' => (string)($candidate['block_id'] ?? $blockId),
                'component_code' => $this->resolveBlockComponentCode($candidate),
                'source' => (string)($read['source'] ?? 'shared_components.' . $sharedRegion),
                'scope' => $scope,
                'before_block' => $beforeBlock,
                'after_block' => $candidate,
                'change_summary' => \trim((string)($metadata['change_summary'] ?? '')),
                'changed_fields' => $this->normalizeChangedFields($metadata['changed_fields'] ?? []),
                'reason' => \trim((string)($metadata['reason'] ?? '')),
            ];
        }

        $virtualPages = $this->buildTargetVirtualPagesPreservingScope($scope, $pageType);
        if (!\is_array($virtualPages[$pageType] ?? null)) {
            return $this->error('VIRTUAL_PAGE_NOT_FOUND', 'Virtual page not found during replacement.', [
                'page_type' => $pageType,
                'block_id' => $blockId,
            ]);
        }
        $virtualPage = \is_array($virtualPages[$pageType] ?? null) ? $virtualPages[$pageType] : [];
        $blocks = \is_array($virtualPage['blocks'] ?? null) ? $virtualPage['blocks'] : [];

        $match = $this->findBlockInBlocks($blocks, $blockId);
        if ($match === null) {
            return $this->error('BLOCK_NOT_FOUND', 'Current block not found during replacement.', [
                'page_type' => $pageType,
                'block_id' => $blockId,
                'available_block_ids' => $this->collectBlockIds($blocks),
            ]);
        }

        $index = (int)($match['index'] ?? -1);
        if ($index < 0 || !\array_key_exists($index, $blocks)) {
            return $this->error('BLOCK_NOT_FOUND', 'Current block index is invalid during replacement.');
        }

        $beforeIds = $this->collectBlockIds($blocks);
        $beforeBlock = \is_array($blocks[$index] ?? null) ? $blocks[$index] : [];
        $blocks[$index] = $candidate;
        $afterIds = $this->collectBlockIds($blocks);
        if (\count($beforeIds) !== \count($afterIds)) {
            return $this->error('BLOCK_BOUNDARY_CHANGED', 'Replacement changed block count.');
        }
        foreach ($beforeIds as $position => $id) {
            if ($position === $index) {
                continue;
            }
            if (($afterIds[$position] ?? null) !== $id) {
                return $this->error('BLOCK_BOUNDARY_CHANGED', 'Replacement changed other block order.');
            }
        }

        $createdAt = \date('Y-m-d H:i:s');
        $virtualPage['blocks'] = \array_values($blocks);
        $virtualPage['last_generated_at'] = $createdAt;
        $virtualPages[$pageType] = $virtualPage;
        $scope['virtual_pages_by_type'] = $virtualPages;
        // 微调编辑期间只存到虚拟主题（scope/session），不落盘到具体页面。
        // 发布时 publish flow 会将虚拟主题中的 block 拷贝到具体页面。
        // 编辑数据已安全保存在 session scope 中。
        $scope['preview_page_type'] = $pageType;
        $scope['build_summary'] = \array_replace(
            \is_array($scope['build_summary'] ?? null) ? $scope['build_summary'] : [],
            ['task_summary' => $this->safeSummarize($scope)]
        );
        $scope['block_patch_history'] = $this->appendPatchHistory(
            \is_array($scope['block_patch_history'] ?? null) ? $scope['block_patch_history'] : [],
            $pageType,
            (string)($candidate['block_id'] ?? $blockId),
            $beforeBlock,
            $candidate,
            $metadata,
            $createdAt
        );

        return [
            'success' => true,
            'page_type' => $pageType,
            'block_id' => (string)($candidate['block_id'] ?? $blockId),
            'component_code' => $this->resolveBlockComponentCode($candidate),
            'source' => (string)($read['source'] ?? 'virtual_pages_by_type'),
            'scope' => $scope,
            'before_block' => $beforeBlock,
            'after_block' => $candidate,
            'change_summary' => \trim((string)($metadata['change_summary'] ?? '')),
            'changed_fields' => $this->normalizeChangedFields($metadata['changed_fields'] ?? []),
            'reason' => \trim((string)($metadata['reason'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $replacementBlock
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function applyReplacementBlockByPublicId(
        int $adminId,
        string $publicId,
        string $pageType,
        string $blockId,
        array $replacementBlock,
        array $metadata = []
    ): array {
        $session = $this->sessionService()->loadByPublicId(\trim($publicId), $adminId);
        if (!$session instanceof AiSiteAgentSession) {
            return $this->error('SESSION_NOT_FOUND', 'Session not found or not accessible.');
        }

        $scope = $this->normalizeScope(
            $this->sessionService()->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
        $result = $this->applyReplacementBlockToScope($scope, $pageType, $blockId, $replacementBlock, $metadata);
        if (!empty($result['success']) && \is_array($result['scope'] ?? null)) {
            $this->sessionService()->replaceScope((int)$session->getId(), $adminId, $result['scope']);
        }

        return $result;
    }

    /**
     * @param callable(string,array<string,mixed>):mixed|null $streamCallback
     * @return array<string, mixed>
     */
    public function generateAndApplyPatch(
        AiSiteAgentSession $session,
        int $adminId,
        string $pageType,
        string $blockId,
        string $instruction,
        string $executionToken = '',
        ?callable $streamCallback = null
    ): array {
        $scope = $this->normalizeScope(
            $this->sessionService()->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
        $read = $this->readCurrentBlockFromScope($scope, $pageType, $blockId);
        if (empty($read['success'])) {
            return $read;
        }

        $generated = $this->generateReplacementBlock($read, $scope, $instruction, $streamCallback);
        $metadata = [
            'change_summary' => (string)($generated['change_summary'] ?? ''),
            'changed_fields' => $generated['changed_fields'] ?? [],
            'reason' => (string)($generated['reason'] ?? ''),
            'execution_token' => $executionToken,
        ];
        $result = $this->applyReplacementBlockToScope(
            $scope,
            $pageType,
            (string)($read['block_id'] ?? $blockId),
            \is_array($generated['block'] ?? null) ? $generated['block'] : [],
            $metadata
        );

        if (!empty($result['success']) && \is_array($result['scope'] ?? null)) {
            $this->sessionService()->replaceScope((int)$session->getId(), $adminId, $result['scope']);
        }

        return \array_replace($result, [
            'ai_top_keys' => \array_values(\array_map('strval', \array_keys($generated))),
        ]);
    }

    /**
     * @param array<string, mixed> $read
     * @param array<string, mixed> $scope
     * @param callable(string,array<string,mixed>):mixed|null $streamCallback
     * @return array<string, mixed>
     */
    public function generateReplacementBlock(
        array $read,
        array $scope,
        string $instruction,
        ?callable $streamCallback = null
    ): array {
        if ((bool)RequestContext::get(AiSitePageComponentGenerationService::REQUEST_KEY_ALLOW_STUB_AI_IN_TEST, false)) {
            return $this->buildStubReplacement($read, $instruction);
        }

        $prompt = $this->buildPatchPrompt($read, $scope, $instruction);
        $buffer = '';
        $this->aiService()->generateStream(
            $prompt,
            function ($chunk) use (&$buffer, $streamCallback): bool {
                if (\is_string($chunk) && $chunk !== '') {
                    $buffer .= $chunk;
                    if ($streamCallback !== null) {
                        $streamCallback('ai_response', ['content' => $chunk]);
                    }
                }
                return true;
            },
            null,
            'pagebuilder_component_generation',
            $this->resolveLocale($read, $scope),
            [
                'allow_zero_balance_provider' => true,
                'temperature' => 0.25,
                'max_tokens' => 8192,
                'timeout' => 180,
                'response_format' => ['type' => 'json_object'],
                'partial_patch_mode' => true,
                'disable_conversation_history' => true,
                'disable_conversation_persist' => true,
                'session_id' => 'pagebuilder_block_partial_patch',
            ]
        );

        $decoded = $this->jsonParser()->extractAndDecode($buffer);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('AI patch response is not valid JSON.');
        }

        $block = \is_array($decoded['block'] ?? null)
            ? $decoded['block']
            : (\is_array($decoded['replacement_block'] ?? null) ? $decoded['replacement_block'] : []);
        if ($block === []) {
            throw new \RuntimeException('AI patch response missing block. top_keys=' . \implode(',', \array_keys($decoded)));
        }

        return [
            'block' => $block,
            'change_summary' => \trim((string)($decoded['change_summary'] ?? '')),
            'changed_fields' => $this->normalizeChangedFields($decoded['changed_fields'] ?? []),
            'reason' => \trim((string)($decoded['reason'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<string>
     */
    private function resolvePageTypes(array $scope, string $fallbackPageType): array
    {
        if ($this->scopeCompatibilityService !== null) {
            $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        } else {
            $pageTypes = \array_values(\array_filter(\array_map(
                'strval',
                \is_array($scope['page_types'] ?? null) ? $scope['page_types'] : \array_keys(\is_array($scope['virtual_pages_by_type'] ?? null) ? $scope['virtual_pages_by_type'] : [])
            )));
        }
        if ($fallbackPageType !== '' && !\in_array($fallbackPageType, $pageTypes, true)) {
            $pageTypes[] = $fallbackPageType;
        }

        return \array_values(\array_unique($pageTypes));
    }

    /**
     * @param array<string, mixed> $scope
     * @param list<string> $pageTypes
     * @return array<string, array<string, mixed>>
     */
    private function buildVirtualPagesByType(array $scope, array $pageTypes): array
    {
        if ($this->scopeCompatibilityService !== null) {
            return $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypes, $scope, false);
        }

        return \is_array($scope['virtual_pages_by_type'] ?? null) ? $scope['virtual_pages_by_type'] : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildTargetVirtualPagesByType(array $scope, string $pageType): array
    {
        return $this->buildVirtualPagesByType($scope, [$pageType]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildTargetVirtualPagesPreservingScope(array $scope, string $pageType): array
    {
        $existing = \is_array($scope['virtual_pages_by_type'] ?? null) ? $scope['virtual_pages_by_type'] : [];
        $target = $this->buildTargetVirtualPagesByType($scope, $pageType);
        if (\is_array($target[$pageType] ?? null)) {
            $existing[$pageType] = $target[$pageType];
        }

        return $existing;
    }

    private function resolveSharedComponentRegionForBlockId(string $pageType, string $blockId): string
    {
        $blockId = \trim($blockId);
        if ($blockId === '') {
            return '';
        }

        $normalizedBlockId = $blockId;
        if (\str_starts_with($normalizedBlockId, 'content/')) {
            $normalizedBlockId = \substr($normalizedBlockId, \strlen('content/'));
        }

        $pageSlug = \str_replace('_', '-', \trim($pageType));
        if (
            $blockId === 'header/ai-site-header'
            || $normalizedBlockId === 'header-ai-site-header'
            || ($pageSlug !== '' && $normalizedBlockId === $pageSlug . '-site-header')
            || \str_ends_with($normalizedBlockId, '-site-header')
        ) {
            return 'header';
        }
        if (
            $blockId === 'footer/ai-site-footer'
            || $normalizedBlockId === 'footer-ai-site-footer'
            || ($pageSlug !== '' && $normalizedBlockId === $pageSlug . '-site-footer')
            || \str_ends_with($normalizedBlockId, '-site-footer')
        ) {
            return 'footer';
        }

        return '';
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>|null
     */
    private function buildSharedComponentBlockFromScope(array $scope, string $region, string $requestedBlockId): ?array
    {
        $sharedComponents = \is_array($scope['shared_components'] ?? null) ? $scope['shared_components'] : [];
        $component = \is_array($sharedComponents[$region] ?? null) ? $sharedComponents[$region] : [];
        if ($component === []) {
            return null;
        }

        $componentCode = \trim((string)($component['code'] ?? ($region === 'header' ? 'header/ai-site-header' : 'footer/ai-site-footer')));
        $defaultConfig = \is_array($component['default_config'] ?? null) ? $component['default_config'] : [];
        $phtml = \trim((string)($component['phtml'] ?? ''));
        $html = \trim((string)($component['html'] ?? ''));
        if ($html === '') {
            foreach (['html_content', 'html_extra', 'html_extra_column', 'footer_extra_text'] as $field) {
                $candidate = \trim((string)($defaultConfig[$field] ?? ''));
                if ($candidate !== '') {
                    $html = $candidate;
                    break;
                }
            }
        }

        return [
            'block_id' => $componentCode !== '' ? $componentCode : $requestedBlockId,
            'type' => 'ai_generated_shared_' . $region,
            'component_code' => $componentCode,
            'config' => $defaultConfig,
            'html' => $html,
            'field_schema' => [],
            self::META_COMPONENT_CODE => $componentCode,
            self::META_REGION => $region,
            self::META_TEMPLATE_PHTML => $phtml,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private function syncSharedComponentReplacement(array $scope, string $region, array $candidate): array
    {
        $sharedComponents = \is_array($scope['shared_components'] ?? null) ? $scope['shared_components'] : [];
        $existing = \is_array($sharedComponents[$region] ?? null) ? $sharedComponents[$region] : [];
        $componentCode = \trim((string)($candidate[self::META_COMPONENT_CODE] ?? $candidate['component_code'] ?? $candidate['block_id'] ?? $existing['code'] ?? ''));
        $sharedComponents[$region] = \array_replace($existing, [
            'code' => $componentCode !== '' ? $componentCode : ($region === 'header' ? 'header/ai-site-header' : 'footer/ai-site-footer'),
            'name' => (string)($existing['name'] ?? ($region === 'header' ? 'AI Site Header' : 'AI Site Footer')),
            'region' => $region,
            'phtml' => (string)($candidate[self::META_TEMPLATE_PHTML] ?? $existing['phtml'] ?? ''),
            'html' => (string)($candidate['html'] ?? $existing['html'] ?? ''),
            'default_config' => \is_array($candidate['config'] ?? null)
                ? $candidate['config']
                : (\is_array($existing['default_config'] ?? null) ? $existing['default_config'] : []),
        ]);
        $scope['shared_components'] = $sharedComponents;

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $candidate
     */
    private function persistSharedComponentReplacement(array $scope, string $region, array $candidate): void
    {
        $themeId = (int)($scope['virtual_theme_id'] ?? 0);
        if ($themeId <= 0) {
            return;
        }
        if ($this->virtualThemeService === null) {
            return;
        }
        $sharedComponents = \is_array($scope['shared_components'] ?? null) ? $scope['shared_components'] : [];
        $component = \is_array($sharedComponents[$region] ?? null) ? $sharedComponents[$region] : [];
        if ($component === []) {
            return;
        }

        $this->virtualThemeService->saveGeneratedSharedComponent($themeId, $component);
    }

    /**
     * @param list<mixed> $blocks
     * @return array{index:int,block:array<string,mixed>}|null
     */
    private function findBlockInBlocks(array $blocks, string $blockId): ?array
    {
        $needles = $this->normalizeLookupCandidates($blockId);
        foreach ($blocks as $index => $block) {
            if (!\is_array($block)) {
                continue;
            }
            foreach ($this->buildBlockLookupValues($block) as $value) {
                if (\in_array($this->normalizeLookupKey($value), $needles, true)) {
                    return ['index' => (int)$index, 'block' => $block];
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $block
     * @return list<string>
     */
    private function buildBlockLookupValues(array $block): array
    {
        $config = \is_array($block['config'] ?? null) ? $block['config'] : [];
        $metadata = \is_array($block['metadata'] ?? null) ? $block['metadata'] : [];
        $meta = \is_array($block['meta'] ?? null) ? $block['meta'] : [];
        $values = [];
        foreach ([$block, $config, $metadata, $meta] as $payload) {
            $this->appendLookupValuesFromArray($values, $payload);
        }

        return \array_values(\array_unique(\array_filter(
            \array_map(static fn($value): string => \trim((string)$value), $values),
            static fn(string $value): bool => $value !== ''
        )));
    }

    /**
     * @param list<mixed> $values
     * @param array<string, mixed> $payload
     */
    private function appendLookupValuesFromArray(array &$values, array $payload): void
    {
        foreach ([
            'block_id',
            'component_code',
            'section_code',
            'component',
            'code',
            'block_code',
            'block_key',
            'task_key',
            self::META_COMPONENT_CODE,
        ] as $key) {
            if (!isset($payload[$key]) || (!\is_scalar($payload[$key]) && !(\is_object($payload[$key]) && \method_exists($payload[$key], '__toString')))) {
                continue;
            }
            $values[] = $payload[$key];
        }
    }

    /**
     * @return list<string>
     */
    private function normalizeLookupCandidates(string $value): array
    {
        $key = $this->normalizeLookupKey($value);
        if ($key === '') {
            return [];
        }

        $candidates = [$key];
        $candidates[] = $this->normalizeLookupKey(\str_replace(['content/', '/'], ['', '-'], $value));
        $candidates[] = $this->normalizeLookupKey('content/' . $value);
        $candidates[] = $this->normalizeLookupKey(\str_replace(['_', '/'], ['-', '-'], $value));
        $parts = \array_values(\array_filter(
            \array_map(static fn($part): string => \trim((string)$part), \preg_split('/[:|]+/', $value) ?: []),
            static fn(string $part): bool => $part !== ''
        ));
        $lastPartIndex = \count($parts) - 1;
        foreach ($parts as $partIndex => $part) {
            $part = \trim((string)$part);
            if ($part === '') {
                continue;
            }
            if ($partIndex !== $lastPartIndex && !\str_contains($part, '/')) {
                continue;
            }
            $candidates[] = $this->normalizeLookupKey($part);
            $candidates[] = $this->normalizeLookupKey(\str_replace(['content/', '/'], ['', '-'], $part));
        }

        return \array_values(\array_unique(\array_filter($candidates, static fn(string $candidate): bool => $candidate !== '')));
    }

    private function normalizeLookupKey(string $value): string
    {
        $value = \strtolower(\trim($value));
        $value = \str_replace('\\', '/', $value);
        $value = \preg_replace('/\s+/', '-', $value) ?? $value;

        return \trim($value);
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function normalizeReplacementBlock(array $currentBlock, array $block): array
    {
        $candidate = \is_array($block['block'] ?? null) ? $block['block'] : $block;
        foreach ($currentBlock as $key => $value) {
            if (\is_string($key) && \str_starts_with($key, '_pb_server_') && !\array_key_exists($key, $candidate)) {
                $candidate[$key] = $value;
            }
        }
        if (!\array_key_exists('block_id', $candidate) && \array_key_exists('block_id', $currentBlock)) {
            $candidate['block_id'] = $currentBlock['block_id'];
        }
        if (!\array_key_exists('type', $candidate) && \array_key_exists('type', $currentBlock)) {
            $candidate['type'] = $currentBlock['type'];
        }

        return $candidate;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function isLegalFieldSchema(array $schema): bool
    {
        foreach ($schema as $groupKey => $group) {
            if (!\is_string($groupKey) && !\is_int($groupKey)) {
                return false;
            }
            if (!\is_array($group)) {
                return false;
            }
            if (\array_key_exists('label', $group) && !\is_scalar($group['label']) && $group['label'] !== null) {
                return false;
            }
            if (!\array_key_exists('fields', $group)) {
                continue;
            }
            if (!\is_array($group['fields'])) {
                return false;
            }
            foreach ($group['fields'] as $fieldKey => $field) {
                if (!\is_string($fieldKey) && !\is_int($fieldKey)) {
                    return false;
                }
                if (\is_string($fieldKey) && \trim($fieldKey) === '') {
                    return false;
                }
                if (!\is_array($field)) {
                    return false;
                }
                if (\is_int($fieldKey)) {
                    $fieldName = $field['key'] ?? $field['name'] ?? null;
                    if (!\is_scalar($fieldName) || \trim((string)$fieldName) === '') {
                        return false;
                    }
                }
                if (\array_key_exists('key', $field) && !\is_scalar($field['key']) && $field['key'] !== null) {
                    return false;
                }
                if (\array_key_exists('name', $field) && !\is_scalar($field['name']) && $field['name'] !== null) {
                    return false;
                }
                if (\array_key_exists('type', $field) && !\is_scalar($field['type'])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $block
     * @return array{valid:bool,errors:list<string>}
     */
    private function validateReplacementRenderable(array $block): array
    {
        $phtml = \trim((string)($block[self::META_TEMPLATE_PHTML] ?? ''));
        if ($phtml === '') {
            return ['valid' => true, 'errors' => []];
        }

        $renderer = new PreviewRenderer();
        $renderer->setData('component_config', \is_array($block['config'] ?? null) ? $block['config'] : []);
        $renderer->setData('page', new MockPage());
        $bufferLevel = \ob_get_level();
        try {
            $result = $renderer->render($phtml);
        } finally {
            while (\ob_get_level() > $bufferLevel) {
                \ob_end_clean();
            }
        }
        if (!($result['success'] ?? false)) {
            return ['valid' => false, 'errors' => [(string)($result['error'] ?? 'unknown render error')]];
        }

        return ['valid' => true, 'errors' => []];
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function extractTemplateMetadata(array $block): array
    {
        $metadata = [];
        foreach ([self::META_TEMPLATE_PHTML, self::META_COMPONENT_CODE, self::META_REGION] as $key) {
            if (\array_key_exists($key, $block)) {
                $metadata[$key] = $key === self::META_TEMPLATE_PHTML
                    ? ['present' => \trim((string)$block[$key]) !== '', 'length' => \strlen((string)$block[$key])]
                    : $block[$key];
            }
        }

        return $metadata;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function buildPageContext(array $scope, string $pageType): array
    {
        $page = \is_array($scope['virtual_pages_by_type'][$pageType] ?? null)
            ? $scope['virtual_pages_by_type'][$pageType]
            : [];

        return [
            'page_type' => $pageType,
            'title' => (string)($page['title'] ?? ''),
            'handle' => (string)($page['handle'] ?? ''),
            'locale' => (string)($page['locale'] ?? $scope['plan_locale'] ?? ''),
            'workspace_track' => (string)($scope['workspace_track'] ?? ''),
            'website_profile' => $this->compactPromptValue(\is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : []),
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function buildLayoutContext(array $scope, string $pageType, string $componentCode, string $blockId): array
    {
        $layouts = \is_array($scope['page_type_layouts'] ?? null) ? $scope['page_type_layouts'] : [];
        $layout = \is_array($layouts[$pageType] ?? null) ? $layouts[$pageType] : [];

        return [
            'page_type_layout' => $this->compactPromptValue($layout),
            'component_code' => $componentCode,
            'block_id' => $blockId,
        ];
    }

    private function compactPromptValue(mixed $value, int $depth = 0): mixed
    {
        if ($value === null || \is_bool($value) || \is_int($value) || \is_float($value)) {
            return $value;
        }
        if (\is_string($value)) {
            $value = \trim($value);
            if (\function_exists('mb_strlen') && \function_exists('mb_substr')) {
                return \mb_strlen($value, 'UTF-8') > 600
                    ? \mb_substr($value, 0, 600, 'UTF-8') . '...'
                    : $value;
            }
            if (\preg_match_all('/./us', $value, $matches) !== false) {
                $chars = $matches[0] ?? [];
                return \count($chars) > 600 ? \implode('', \array_slice($chars, 0, 600)) . '...' : $value;
            }

            return \strlen($value) > 1800 ? \substr($value, 0, 1800) . '...' : $value;
        }
        if (!\is_array($value)) {
            return (string)$value;
        }
        if ($depth >= 4) {
            return ['_truncated' => true, '_reason' => 'max_depth'];
        }

        $result = [];
        $count = 0;
        foreach ($value as $key => $item) {
            if ($count >= 24) {
                $result['_truncated'] = true;
                $result['_remaining_count'] = \max(0, \count($value) - $count);
                break;
            }
            if (\is_string($key) && \in_array($key, ['virtual_pages_by_type', 'pagebuilder_pages_by_type', 'build_tasks', 'events', 'top_logs'], true)) {
                continue;
            }
            $result[$key] = $this->compactPromptValue($item, $depth + 1);
            ++$count;
        }

        return $result;
    }

    /**
     * @param list<mixed> $blocks
     * @return list<string>
     */
    private function collectBlockIds(array $blocks): array
    {
        $ids = [];
        foreach ($blocks as $block) {
            if (\is_array($block)) {
                $ids[] = \trim((string)($block['block_id'] ?? ''));
            }
        }

        return \array_values($ids);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function resolveBlockComponentCode(array $block): string
    {
        $config = \is_array($block['config'] ?? null) ? $block['config'] : [];
        foreach ([
            $block[self::META_COMPONENT_CODE] ?? '',
            $config[self::META_COMPONENT_CODE] ?? '',
            $block['component_code'] ?? '',
            $config['component_code'] ?? '',
            $block['section_code'] ?? '',
            $config['section_code'] ?? '',
        ] as $value) {
            $candidate = \trim((string)$value);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @param mixed $fields
     * @return list<string>
     */
    private function normalizeChangedFields(mixed $fields): array
    {
        if (\is_string($fields)) {
            $fields = \preg_split('/[,;\r\n]+/', $fields) ?: [];
        }
        if (!\is_array($fields)) {
            return [];
        }

        return \array_values(\array_unique(\array_filter(\array_map(
            static fn($field): string => \trim((string)$field),
            $fields
        ), static fn(string $field): bool => $field !== '')));
    }

    /**
     * @param array<string, mixed> $history
     * @param array<string, mixed> $beforeBlock
     * @param array<string, mixed> $afterBlock
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function appendPatchHistory(
        array $history,
        string $pageType,
        string $blockId,
        array $beforeBlock,
        array $afterBlock,
        array $metadata,
        string $createdAt
    ): array {
        $pageHistory = \is_array($history[$pageType] ?? null) ? $history[$pageType] : [];
        $blockHistory = \is_array($pageHistory[$blockId] ?? null) ? $pageHistory[$blockId] : [];
        $blockHistory[] = [
            'before_block' => $beforeBlock,
            'after_block_hash' => \sha1(\json_encode($afterBlock, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: ''),
            'change_summary' => \trim((string)($metadata['change_summary'] ?? '')),
            'changed_fields' => $this->normalizeChangedFields($metadata['changed_fields'] ?? []),
            'reason' => \trim((string)($metadata['reason'] ?? '')),
            'created_at' => $createdAt,
            'execution_token' => \trim((string)($metadata['execution_token'] ?? '')),
        ];
        $pageHistory[$blockId] = \array_slice($blockHistory, -self::HISTORY_LIMIT);
        $history[$pageType] = $pageHistory;

        return $history;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function safeSummarize(array $scope): array
    {
        try {
            return $this->buildTaskService()->summarize($scope);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $read
     * @param array<string, mixed> $scope
     */
    private function buildPatchPrompt(array $read, array $scope, string $instruction): string
    {
        $payload = [
            'instruction' => $instruction,
            'target' => [
                'page_type' => (string)($read['page_type'] ?? ''),
                'block_id' => (string)($read['block_id'] ?? ''),
                'component_code' => (string)($read['component_code'] ?? ''),
            ],
            'current_block' => \is_array($read['block'] ?? null) ? $read['block'] : [],
            'page_context' => \is_array($read['page_context'] ?? null) ? $read['page_context'] : [],
            'layout_context' => \is_array($read['layout_context'] ?? null) ? $read['layout_context'] : [],
        ];

        $payloadJson = \json_encode(
            $payload,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE
        );
        if (!\is_string($payloadJson) || $payloadJson === '') {
            $payloadJson = '{}';
        }

        return "You are modifying one PageBuilder block in-place.\n"
            . "Return JSON only with keys: block, change_summary, changed_fields, reason.\n"
            . "Rules:\n"
            . "- Return a complete replacement block object in block.\n"
            . "- Keep block.block_id exactly the same.\n"
            . "- Do not add, delete, move, or mention other blocks.\n"
            . "- Preserve server metadata keys that start with _pb_server_ unless the template itself must change.\n"
            . "- Ensure block has type, config, html, and field_schema.\n"
            . "- If _pb_server_template_phtml is present, keep it renderable.\n\n"
            . $payloadJson;
    }

    /**
     * @param array<string, mixed> $read
     * @param array<string, mixed> $scope
     */
    private function resolveLocale(array $read, array $scope): ?string
    {
        $pageContext = \is_array($read['page_context'] ?? null) ? $read['page_context'] : [];
        $locale = \trim((string)($pageContext['locale'] ?? $scope['plan_locale'] ?? ''));

        return $locale !== '' ? $locale : null;
    }

    /**
     * @param array<string, mixed> $read
     * @return array<string, mixed>
     */
    private function buildStubReplacement(array $read, string $instruction): array
    {
        $block = \is_array($read['block'] ?? null) ? $read['block'] : [];
        $config = \is_array($block['config'] ?? null) ? $block['config'] : [];
        $summary = \trim($instruction) !== '' ? \trim($instruction) : 'stub patch';
        $oldValue = null;
        $newValue = null;
        if (isset($config['headline']) && \is_scalar($config['headline'])) {
            $oldValue = (string)$config['headline'];
            $newValue = $oldValue . ' - refined';
            $config['headline'] = $newValue;
        } elseif (isset($config['title']) && \is_scalar($config['title'])) {
            $oldValue = (string)$config['title'];
            $newValue = $oldValue . ' - refined';
            $config['title'] = $newValue;
        } else {
            $config['partial_patch_note'] = $summary;
            $newValue = $summary;
        }
        $block['config'] = $config;
        $html = (string)($block['html'] ?? '');
        if ($html !== '' && $oldValue !== null && $oldValue !== '' && $newValue !== null && \strpos($html, $oldValue) !== false) {
            $block['html'] = \str_replace($oldValue, $newValue, $html);
        } elseif (\trim($html) === '') {
            $block['html'] = '<section data-pb-partial-patch="stub">' . \htmlspecialchars((string)($newValue ?? $summary), \ENT_QUOTES, 'UTF-8') . '</section>';
        } else {
            $block['html'] = $html . "\n" . '<div data-pb-partial-patch="stub">' . \htmlspecialchars((string)($newValue ?? $summary), \ENT_QUOTES, 'UTF-8') . '</div>';
        }

        return [
            'block' => $block,
            'change_summary' => 'Stub block partial patch applied.',
            'changed_fields' => ['config', 'html'],
            'reason' => 'test stub',
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function normalizeScope(array $scope): array
    {
        return $this->scopeCompatibilityService !== null
            ? $this->scopeCompatibilityService->normalizeScope($scope)
            : $scope;
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function error(string $code, string $message, array $details = []): array
    {
        return [
            'success' => false,
            'code' => $code,
            'message' => $message,
            'details' => $details,
        ];
    }

    private function sessionService(): AiSiteAgentSessionService
    {
        return $this->sessionService ?? ObjectManager::getInstance(AiSiteAgentSessionService::class);
    }

    private function buildTaskService(): AiSiteBuildTaskService
    {
        return $this->buildTaskService ?? ObjectManager::getInstance(AiSiteBuildTaskService::class);
    }

    private function htmlBlocksBuildService(): AiSiteHtmlBlocksBuildService
    {
        return $this->htmlBlocksBuildService ?? ObjectManager::getInstance(AiSiteHtmlBlocksBuildService::class);
    }

    private function jsonParser(): AiResponseJsonParser
    {
        return $this->jsonParser ?? ObjectManager::getInstance(AiResponseJsonParser::class);
    }

    private function aiService(): AiService
    {
        return $this->aiService ?? ObjectManager::getInstance(AiService::class);
    }

}
