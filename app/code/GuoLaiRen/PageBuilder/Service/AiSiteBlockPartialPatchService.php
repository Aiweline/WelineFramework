<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Model\VirtualThemeComponent;
use GuoLaiRen\PageBuilder\Service\AI\AiResponseJsonParser;
use GuoLaiRen\PageBuilder\Service\AI\Contract\AiSiteVisualBlockContractRenderer;
use GuoLaiRen\PageBuilder\Service\AI\MockPage;
use GuoLaiRen\PageBuilder\Service\AI\PreviewRenderer;
use Weline\Ai\Service\AiService;
use Weline\Framework\Manager\ObjectManager;

class AiSiteBlockPartialPatchService
{
    private const META_TEMPLATE_PHTML = '_pb_server_template_phtml';
    private const META_COMPONENT_CODE = '_pb_server_component_code';
    private const META_REGION = '_pb_server_region';
    private const HISTORY_LIMIT = 3;
    private const JSON_REPAIR_MAX_ATTEMPTS = 2;
    private ?AiSiteVisualBlockContractRenderer $visualBlockContractRenderer = null;

    public function __construct(
        private readonly ?AiSiteAgentSessionService $sessionService = null,
        private readonly ?AiSiteScopeCompatibilityService $scopeCompatibilityService = null,
        private readonly ?AiSiteBuildTaskService $buildTaskService = null,
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
            $this->sessionService()->loadScopeForBuildOperation($session)
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
            $virtualThemeBlock = $this->buildVirtualThemeComponentBlockFromScope($scope, $pageType, $blockId);
            if ($virtualThemeBlock !== null) {
                $actualBlockId = \trim((string)($virtualThemeBlock['block_id'] ?? $blockId));
                $componentCode = $this->resolveBlockComponentCode($virtualThemeBlock);

                return [
                    'success' => true,
                    'source' => 'virtual_theme_component',
                    'page_type' => $pageType,
                    'block_id' => $actualBlockId,
                    'requested_block_id' => $blockId,
                    'component_code' => $componentCode,
                    'index' => (int)($virtualThemeBlock['_pb_server_layout_index'] ?? -1),
                    'block' => $virtualThemeBlock,
                    'type' => (string)($virtualThemeBlock['type'] ?? ''),
                    'config' => \is_array($virtualThemeBlock['config'] ?? null) ? $virtualThemeBlock['config'] : [],
                    'html' => (string)($virtualThemeBlock['html'] ?? ''),
                    'field_schema' => \is_array($virtualThemeBlock['field_schema'] ?? null) ? $virtualThemeBlock['field_schema'] : [],
                    'template_metadata' => $this->extractTemplateMetadata($virtualThemeBlock),
                    'page_context' => $this->buildPageContext($scope, $pageType),
                    'layout_context' => $this->buildLayoutContext($scope, $pageType, $componentCode, $actualBlockId),
                ];
            }

            $layoutBlock = $this->buildLayoutContentBlockFromScope($scope, $pageType, $blockId);
            if ($layoutBlock !== null) {
                $actualBlockId = \trim((string)($layoutBlock['block_id'] ?? $blockId));
                $componentCode = $this->resolveBlockComponentCode($layoutBlock);

                return [
                    'success' => true,
                    'source' => 'page_type_layouts.content',
                    'page_type' => $pageType,
                    'block_id' => $actualBlockId,
                    'requested_block_id' => $blockId,
                    'component_code' => $componentCode,
                    'index' => (int)($layoutBlock['_pb_server_layout_index'] ?? -1),
                    'block' => $layoutBlock,
                    'type' => (string)($layoutBlock['type'] ?? ''),
                    'config' => \is_array($layoutBlock['config'] ?? null) ? $layoutBlock['config'] : [],
                    'html' => (string)($layoutBlock['html'] ?? ''),
                    'field_schema' => \is_array($layoutBlock['field_schema'] ?? null) ? $layoutBlock['field_schema'] : [],
                    'template_metadata' => $this->extractTemplateMetadata($layoutBlock),
                    'page_context' => $this->buildPageContext($scope, $pageType),
                    'layout_context' => $this->buildLayoutContext($scope, $pageType, $componentCode, $actualBlockId),
                ];
            }

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
        } elseif ($this->hasUnstyledBrowserDefaultLink((string)$candidate['html'])) {
            $errors[] = 'replacement.html contains an unstyled browser-default link';
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

    private function hasUnstyledBrowserDefaultLink(string $html): bool
    {
        if (\stripos($html, '<a') === false) {
            return false;
        }

        if (\preg_match_all('/<a\b([^>]*)>/i', $html, $matches) !== false) {
            foreach ($matches[1] ?? [] as $attributes) {
                $attributes = (string)$attributes;
                if (\preg_match('/\bclass\s*=\s*["\'][^"\']+["\']/i', $attributes) !== 1) {
                    return true;
                }
            }
        }

        return false;
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
        $replacementProvidedTemplate = $this->replacementProvidesServerTemplate($replacementBlock);
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

        $renderValidation = $this->validateReplacementRenderable($candidate, !$replacementProvidedTemplate);
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
            $scope['build_plan_execution_summary'] = $this->safeSummarize($scope);
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

        if ((string)($read['source'] ?? '') === 'virtual_theme_component') {
            return $this->applyVirtualThemeComponentReplacement($scope, $pageType, $read, $currentBlock, $candidate, $metadata);
        }
        if ((string)($read['source'] ?? '') === 'page_type_layouts.content') {
            return $this->applyLayoutContentReplacement($scope, $pageType, $read, $currentBlock, $candidate, $metadata);
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
        $scope['build_plan_execution_summary'] = $this->safeSummarize($scope);
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
            $this->sessionService()->loadScopeForBuildOperation($session)
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
            $this->sessionService()->loadScopeForBuildOperation($session)
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
        if (empty($result['success']) && $this->shouldRepairGeneratedPatch($result)) {
            if ($streamCallback !== null) {
                $streamCallback('patch_repair', [
                    'message' => 'Generated patch failed validation; requesting one corrected replacement block.',
                    'code' => (string)($result['code'] ?? ''),
                ]);
            }
            $repaired = $this->generateReplacementBlock(
                $read,
                $scope,
                $this->buildPatchRepairInstruction($instruction, $result),
                $streamCallback
            );
            $repairMetadata = [
                'change_summary' => (string)($repaired['change_summary'] ?? ''),
                'changed_fields' => $repaired['changed_fields'] ?? [],
                'reason' => (string)($repaired['reason'] ?? ''),
                'execution_token' => $executionToken,
                'repair_of_code' => (string)($result['code'] ?? ''),
            ];
            $result = $this->applyReplacementBlockToScope(
                $scope,
                $pageType,
                (string)($read['block_id'] ?? $blockId),
                \is_array($repaired['block'] ?? null) ? $repaired['block'] : [],
                $repairMetadata
            );
            $generated = $repaired;
        }

        if (!empty($result['success']) && \is_array($result['scope'] ?? null)) {
            $this->sessionService()->replaceScope((int)$session->getId(), $adminId, $result['scope']);
        }

        return \array_replace($result, [
            'ai_top_keys' => \array_values(\array_map('strval', \array_keys($generated))),
        ]);
    }

    /**
     * @param array<string,mixed> $result
     */
    private function shouldRepairGeneratedPatch(array $result): bool
    {
        $code = \strtoupper(\trim((string)($result['code'] ?? '')));

        return \in_array($code, ['BLOCK_RENDER_FAILED', 'BLOCK_VALIDATION_FAILED'], true);
    }

    /**
     * @param array<string,mixed> $result
     */
    private function buildPatchRepairInstruction(string $instruction, array $result): string
    {
        $details = \is_array($result['details'] ?? null) ? $result['details'] : [];
        $errors = \is_array($details['errors'] ?? null) ? $details['errors'] : [];
        $errorText = $errors !== []
            ? \implode('; ', \array_map(static fn($error): string => (string)$error, $errors))
            : (string)($result['message'] ?? 'replacement block validation failed');

        return \trim($instruction) . "\n\n"
            . "Repair requirement: the previous replacement block failed PageBuilder validation before it could be saved. "
            . "Return a corrected complete replacement block JSON for the same target block. "
            . "Do not return a diff. Do not explain. Preserve verified generated image src and data-pb-ai-asset-slot values. "
            . "Keep the requested visual/copy change, but make the JSON and any _pb_server_template_phtml/html fully renderable. "
            . "If the error is PHP/PHTML syntax, rewrite the block.html and _pb_server_template_phtml as static balanced HTML with scoped CSS only; do not use PHP tags, short echo tags, variables, control structures, or template expressions. "
            . "Validation error: " . $this->clipText($errorText, 500);
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
        if ($this->truthyScopeValue($scope['fake_mode'] ?? false)) {
            $result = $this->buildFakeModeReplacementBlock($read, $instruction);
            if ($streamCallback !== null) {
                $streamCallback('fake_patch', [
                    'message' => 'Generated deterministic fake-mode block patch.',
                    'changed_fields' => $result['changed_fields'],
                ]);
            }

            return $result;
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
            $rawTemplateReplacement = $this->buildReplacementFromRawTemplateResponse($read, $buffer);
            if ($rawTemplateReplacement !== []) {
                return $rawTemplateReplacement;
            }
            $decoded = $this->decodePatchPayloadWithRepair(
                $buffer,
                $read,
                $scope,
                $instruction,
                $streamCallback
            );
        }
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
     * @param callable(string,array<string,mixed>):mixed|null $streamCallback
     * @return array<string,mixed>|null
     */
    private function decodePatchPayloadWithRepair(
        string $content,
        array $read,
        array $scope,
        string $instruction,
        ?callable $streamCallback = null
    ): ?array
    {
        $parser = $this->jsonParser();
        $decoded = $parser->extractAndDecode($content);
        if (\is_array($decoded)) {
            return $decoded;
        }

        $locallyRepairedContent = $this->repairPatchJsonTransportIssues($content);
        if ($locallyRepairedContent !== $content) {
            $decoded = $parser->extractAndDecode($locallyRepairedContent);
            if (\is_array($decoded)) {
                if ($streamCallback !== null) {
                    $streamCallback('json_repair', ['message' => 'Patch JSON parsed after local escape repair.']);
                }
                return $decoded;
            }
        }

        $currentContent = $content;
        for ($attempt = 1; $attempt <= self::JSON_REPAIR_MAX_ATTEMPTS; $attempt++) {
            if ($streamCallback !== null) {
                $streamCallback('json_repair', [
                    'message' => 'Patch JSON repair attempt ' . $attempt . '/' . self::JSON_REPAIR_MAX_ATTEMPTS . '.',
                ]);
            }
            $repairContent = $this->requestPatchJsonRepair($currentContent, $read, $scope, $instruction, $streamCallback);
            if ($repairContent === null || \trim($repairContent) === '') {
                continue;
            }
            $currentContent = $repairContent;
            $decoded = $parser->extractAndDecode($currentContent);
            if (\is_array($decoded)) {
                return $decoded;
            }
            $locallyRepairedContent = $this->repairPatchJsonTransportIssues($currentContent);
            if ($locallyRepairedContent !== $currentContent) {
                $decoded = $parser->extractAndDecode($locallyRepairedContent);
                if (\is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return null;
    }

    /**
     * @param callable(string,array<string,mixed>):mixed|null $streamCallback
     */
    private function requestPatchJsonRepair(
        string $previousContent,
        array $read,
        array $scope,
        string $instruction,
        ?callable $streamCallback = null
    ): ?string
    {
        $previousSnippet = $this->clipText($previousContent, 8000);
        $prompt = "You are repairing malformed PageBuilder block_partial_patch JSON by regenerating the complete replacement block.\n"
            . "Return ONLY one corrected JSON object. No markdown fences, comments, or explanation.\n"
            . "Required top-level keys: block, change_summary, changed_fields.\n"
            . "Do not include reason, why, decision_reason, markdown, or prose fields.\n"
            . "The block object must keep the same block_id/component_code as the current block in the payload below.\n"
            . "Use the current block from the payload as the source of truth. Preserve block_id, type, field_schema, server metadata, verified image src, and data-pb-ai-asset-slot unless the user's instruction explicitly requires changing them.\n"
            . "All HTML, CSS, and PHTML must be encoded as JSON strings with escaped quotes and newlines.\n"
            . "Use static balanced HTML fragments only inside block.html/_pb_server_template_phtml; no PHP tags, short echo tags, variables, control structures, markdown, or raw CSS outside JSON strings.\n"
            . "If the previous output mixed raw HTML/PHP with JSON, discard the broken transport and regenerate a clean JSON object from the current block plus instruction.\n"
            . "Keep the user's requested block edits, but fix JSON syntax first. For typography-only requests, preserve the existing image HTML and only adjust scoped CSS/template text styles.\n"
            . "\nOriginal block patch payload and rules:\n"
            . $this->buildPatchPrompt($read, $scope, $instruction)
            . "Previous invalid output:\n"
            . $previousSnippet;

        $buffer = '';
        $this->aiService()->generateStream(
            $prompt,
            function ($chunk) use (&$buffer, $streamCallback): bool {
                if (\is_string($chunk) && $chunk !== '') {
                    $buffer .= $chunk;
                    if ($streamCallback !== null) {
                        $streamCallback('json_repair_ai_response', ['content' => $chunk]);
                    }
                }
                return true;
            },
            null,
            'pagebuilder_component_generation',
            $this->resolveLocale($read, $scope),
            [
                'allow_zero_balance_provider' => true,
                'temperature' => 0.15,
                'max_tokens' => 8192,
                'timeout' => 180,
                'response_format' => ['type' => 'json_object'],
                'partial_patch_mode' => true,
                'disable_conversation_history' => true,
                'disable_conversation_persist' => true,
                'session_id' => 'pagebuilder_block_partial_patch_json_repair',
            ]
        );

        return $buffer !== '' ? $buffer : null;
    }

    private function repairInvalidJsonBackslashEscapes(string $content): string
    {
        return \preg_replace_callback(
            '/\\\\(?!["\\\\\/bfnrtu])/u',
            static fn(): string => '\\\\',
            $content
        ) ?? $content;
    }

    private function repairPatchJsonTransportIssues(string $content): string
    {
        $content = $this->repairInvalidJsonBackslashEscapes($content);

        return $this->repairUnquotedChangedFields($content);
    }

    private function repairUnquotedChangedFields(string $content): string
    {
        return \preg_replace_callback(
            '/("changed_fields"\s*:\s*\[)(.*?)(\])/is',
            static function (array $matches): string {
                $body = \trim((string)($matches[2] ?? ''));
                if ($body === '') {
                    return (string)$matches[1] . (string)$matches[3];
                }
                $rawTokens = \str_contains($body, ',')
                    ? (\preg_split('/\s*,\s*/', $body) ?: [])
                    : (\preg_split('/\s+/', $body) ?: []);
                $tokens = [];
                foreach ($rawTokens as $rawToken) {
                    $token = \trim((string)$rawToken);
                    if ($token === '') {
                        continue;
                    }
                    if (\preg_match('/^[A-Za-z_][A-Za-z0-9_.:-]*$/', $token) === 1) {
                        $tokens[] = \json_encode($token, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '""';
                        continue;
                    }
                    $tokens[] = $token;
                }

                return (string)$matches[1] . \implode(', ', $tokens) . (string)$matches[3];
            },
            $content
        ) ?? $content;
    }

    /**
     * @param array<string, mixed> $read
     * @return array<string, mixed>
     */
    private function buildReplacementFromRawTemplateResponse(array $read, string $response): array
    {
        $template = $this->normalizeRawTemplateResponse($response);
        if ($template === '' || !$this->looksLikeTemplateResponse($template)) {
            return [];
        }

        $currentBlock = \is_array($read['block'] ?? null) ? $read['block'] : [];
        if ($currentBlock === []) {
            return [];
        }

        $block = $currentBlock;
        $block['html'] = $template;
        if (\array_key_exists(self::META_TEMPLATE_PHTML, $block) || (string)($read['source'] ?? '') === 'virtual_theme_component') {
            $block[self::META_TEMPLATE_PHTML] = $template;
        }
        if (!\is_array($block['config'] ?? null)) {
            $block['config'] = \is_array($currentBlock['config'] ?? null) ? $currentBlock['config'] : [];
        }
        if (!\is_array($block['field_schema'] ?? null)) {
            $block['field_schema'] = \is_array($currentBlock['field_schema'] ?? null) ? $currentBlock['field_schema'] : [];
        }
        if (!\array_key_exists('type', $block) && \array_key_exists('type', $currentBlock)) {
            $block['type'] = $currentBlock['type'];
        }

        return [
            'block' => $block,
            'change_summary' => 'AI returned a raw template; normalized into the current block.',
            'changed_fields' => ['html'],
            'reason' => '',
        ];
    }

    private function normalizeRawTemplateResponse(string $response): string
    {
        $response = \trim($response);
        if ($response === '') {
            return '';
        }
        if (\preg_match('/^```(?:html|php|phtml|blade|twig|css)?\s*\r?\n([\s\S]*?)\r?\n```$/', $response, $match)) {
            return \trim((string)$match[1]);
        }

        return $response;
    }

    private function looksLikeTemplateResponse(string $response): bool
    {
        $trimmed = \trim($response);
        if ($trimmed === '' || \str_starts_with($trimmed, '{') || \str_starts_with($trimmed, '[')) {
            return false;
        }

        foreach (['<?', '<section', '<div', '<header', '<footer', '<style', 'class=', '<?='] as $needle) {
            if (\stripos($trimmed, $needle) !== false) {
                return true;
            }
        }

        return false;
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
     * @param array<string, mixed> $scope
     * @return array<string, mixed>|null
     */
    private function buildVirtualThemeComponentBlockFromScope(array $scope, string $pageType, string $requestedBlockId): ?array
    {
        $themeId = $this->resolveVirtualThemeId($scope);
        if ($themeId <= 0) {
            return null;
        }

        $layoutMatch = $this->findVirtualThemeLayoutContentRow($scope, $pageType, $requestedBlockId);
        $layoutRow = \is_array($layoutMatch['row'] ?? null) ? $layoutMatch['row'] : [];
        $componentCode = \trim((string)($layoutRow['code'] ?? $layoutRow['component'] ?? $layoutRow['block_id'] ?? $requestedBlockId));
        if ($componentCode === '') {
            return null;
        }

        $component = $this->loadVirtualThemeComponent($themeId, $componentCode);
        if (!$component instanceof VirtualThemeComponent) {
            return null;
        }

        $componentCode = $component->getComponentCode() !== '' ? $component->getComponentCode() : $componentCode;
        $defaultConfig = $component->getDefaultConfig();
        $layoutConfig = \is_array($layoutRow['config'] ?? null) ? $layoutRow['config'] : [];
        $config = \array_replace($defaultConfig, $layoutConfig);
        $templateContent = $component->getTemplateContent();

        return [
            'block_id' => $componentCode,
            'type' => 'ai_generated_virtual_theme_component',
            'component_code' => $componentCode,
            'config' => $config,
            'html' => $templateContent,
            'field_schema' => [],
            self::META_COMPONENT_CODE => $componentCode,
            self::META_REGION => 'content',
            self::META_TEMPLATE_PHTML => $templateContent,
            '_pb_server_virtual_theme_id' => $themeId,
            '_pb_server_layout_index' => (int)($layoutMatch['index'] ?? -1),
            '_pb_server_component_name' => $component->getName(),
            '_pb_server_sort_order' => (int)($layoutRow['sort_order'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $read
     * @return array<string, mixed>
     */
    private function buildFakeModeReplacementBlock(array $read, string $instruction): array
    {
        $currentBlock = \is_array($read['block'] ?? null) ? $read['block'] : [];
        $block = $currentBlock;
        unset($block[self::META_TEMPLATE_PHTML]);

        $config = \is_array($block['config'] ?? null) ? $block['config'] : [];
        $currentHeadline = $this->resolveFakeModeHeadline($block);
        $headline = $currentHeadline !== '' ? $currentHeadline . ' - refined' : 'Refined section headline';
        if (\array_key_exists('headline', $config) || !\array_key_exists('title', $config)) {
            $config['headline'] = $headline;
        }
        if (\array_key_exists('title', $config)) {
            $config['title'] = $headline;
        }

        $html = (string)($block['html'] ?? $read['html'] ?? '');
        if ($html !== '' && $currentHeadline !== '') {
            $html = \str_replace($currentHeadline, $headline, $html);
        }
        if (\trim($html) === '' || ($currentHeadline !== '' && !\str_contains($html, $headline))) {
            $html = '<section><h2>' . \htmlspecialchars($headline, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</h2></section>';
        }

        $block['config'] = $config;
        $block['html'] = $html;
        if (!\array_key_exists('block_id', $block)) {
            $block['block_id'] = (string)($read['block_id'] ?? '');
        }
        if (!\array_key_exists('type', $block)) {
            $block['type'] = (string)($read['type'] ?? 'ai_generated_section');
        }
        if (!\array_key_exists('field_schema', $block)) {
            $block['field_schema'] = \is_array($currentBlock['field_schema'] ?? null) ? $currentBlock['field_schema'] : [];
        }

        return [
            'block' => $block,
            'change_summary' => 'Applied deterministic fake-mode block refinement.',
            'changed_fields' => ['config.headline', 'html'],
            'reason' => \trim($instruction) !== '' ? 'fake_mode: ' . $this->clipText($instruction, 120) : 'fake_mode',
        ];
    }

    /**
     * @param array<string, mixed> $block
     */
    private function resolveFakeModeHeadline(array $block): string
    {
        $config = \is_array($block['config'] ?? null) ? $block['config'] : [];
        foreach ([$config['headline'] ?? null, $config['title'] ?? null, $block['headline'] ?? null, $block['title'] ?? null] as $candidate) {
            if (\is_scalar($candidate) && \trim((string)$candidate) !== '') {
                return \trim((string)$candidate);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>|null
     */
    private function buildLayoutContentBlockFromScope(array $scope, string $pageType, string $requestedBlockId): ?array
    {
        $layoutMatch = $this->findVirtualThemeLayoutContentRow($scope, $pageType, $requestedBlockId);
        $layoutRow = \is_array($layoutMatch['row'] ?? null) ? $layoutMatch['row'] : [];
        if ($layoutRow === []) {
            return null;
        }

        $componentCode = \trim((string)($layoutRow['code'] ?? $layoutRow['component'] ?? $layoutRow['component_code'] ?? ''));
        $blockId = \trim((string)($layoutRow['block_id'] ?? $layoutRow['id'] ?? $componentCode));
        if ($blockId === '') {
            $blockId = $requestedBlockId;
        }
        if ($componentCode === '') {
            $componentCode = $blockId;
        }

        return [
            'block_id' => $blockId,
            'type' => \trim((string)($layoutRow['type'] ?? 'section')),
            'component_code' => $componentCode,
            'code' => $componentCode,
            'config' => \is_array($layoutRow['config'] ?? null) ? $layoutRow['config'] : [],
            'html' => (string)($layoutRow['html'] ?? ''),
            'field_schema' => \is_array($layoutRow['field_schema'] ?? null) ? $layoutRow['field_schema'] : [],
            self::META_COMPONENT_CODE => $componentCode,
            self::META_REGION => 'content',
            self::META_TEMPLATE_PHTML => (string)($layoutRow[self::META_TEMPLATE_PHTML] ?? $layoutRow['template_phtml'] ?? $layoutRow['html'] ?? ''),
            '_pb_server_layout_index' => (int)($layoutMatch['index'] ?? -1),
            '_pb_server_sort_order' => (int)($layoutRow['sort_order'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $read
     * @param array<string, mixed> $currentBlock
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function applyLayoutContentReplacement(
        array $scope,
        string $pageType,
        array $read,
        array $currentBlock,
        array $candidate,
        array $metadata
    ): array {
        $componentCode = $this->resolveBlockComponentCode($candidate);
        if ($componentCode === '') {
            $componentCode = \trim((string)($read['component_code'] ?? $read['block_id'] ?? ''));
        }
        $requestedBlockId = \trim((string)($read['requested_block_id'] ?? $read['block_id'] ?? $componentCode));
        $layoutMatch = $this->findVirtualThemeLayoutContentRow($scope, $pageType, $requestedBlockId);
        if ($layoutMatch === null && $componentCode !== '') {
            $layoutMatch = $this->findVirtualThemeLayoutContentRow($scope, $pageType, $componentCode);
        }
        $layoutIndex = (int)($layoutMatch['index'] ?? -1);
        if ($layoutIndex < 0) {
            return $this->error('BLOCK_NOT_FOUND', 'Current layout block not found during replacement.', [
                'page_type' => $pageType,
                'block_id' => $requestedBlockId,
            ]);
        }

        $layouts = \is_array($scope['page_type_layouts'] ?? null) ? $scope['page_type_layouts'] : [];
        $layout = \is_array($layouts[$pageType] ?? null) ? $layouts[$pageType] : [];
        $content = \is_array($layout['content'] ?? null) ? $layout['content'] : [];
        if (!\array_key_exists($layoutIndex, $content) || !\is_array($content[$layoutIndex])) {
            return $this->error('BLOCK_NOT_FOUND', 'Current layout block index is invalid during replacement.', [
                'page_type' => $pageType,
                'block_id' => $requestedBlockId,
            ]);
        }

        $blockId = \trim((string)($candidate['block_id'] ?? $currentBlock['block_id'] ?? $requestedBlockId));
        if ($blockId === '') {
            $blockId = $requestedBlockId;
        }
        $config = \is_array($candidate['config'] ?? null) ? $candidate['config'] : [];
        $fieldSchema = \is_array($candidate['field_schema'] ?? null) ? $candidate['field_schema'] : [];
        $html = (string)($candidate['html'] ?? '');
        $createdAt = \date('Y-m-d H:i:s');

        $content[$layoutIndex] = \array_replace($content[$layoutIndex], [
            'block_id' => $blockId,
            'code' => $componentCode !== '' ? $componentCode : (string)($content[$layoutIndex]['code'] ?? ''),
            'component_code' => $componentCode,
            'type' => (string)($candidate['type'] ?? $currentBlock['type'] ?? 'section'),
            'config' => $config,
            'html' => $html,
            'field_schema' => $fieldSchema,
            'enabled' => true,
        ]);
        $layout['content'] = \array_values($content);
        $layouts[$pageType] = $layout;
        $scope['page_type_layouts'] = $layouts;

        $scope = $this->syncVirtualPageBlockIfPresent($scope, $pageType, $requestedBlockId, $candidate, $createdAt);
        $scope['preview_page_type'] = $pageType;
        $scope['build_plan_execution_summary'] = $this->safeSummarize($scope);
        $scope['block_patch_history'] = $this->appendPatchHistory(
            \is_array($scope['block_patch_history'] ?? null) ? $scope['block_patch_history'] : [],
            $pageType,
            $blockId,
            $currentBlock,
            $candidate,
            $metadata,
            $createdAt
        );

        return [
            'success' => true,
            'page_type' => $pageType,
            'block_id' => $blockId,
            'component_code' => $componentCode,
            'source' => (string)($read['source'] ?? 'page_type_layouts.content'),
            'scope' => $scope,
            'before_block' => $currentBlock,
            'after_block' => $candidate,
            'change_summary' => \trim((string)($metadata['change_summary'] ?? '')),
            'changed_fields' => $this->normalizeChangedFields($metadata['changed_fields'] ?? []),
            'reason' => \trim((string)($metadata['reason'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private function syncVirtualPageBlockIfPresent(
        array $scope,
        string $pageType,
        string $requestedBlockId,
        array $candidate,
        string $createdAt
    ): array {
        if (!\is_array($scope['virtual_pages_by_type'][$pageType] ?? null)) {
            return $scope;
        }
        $blocks = \is_array($scope['virtual_pages_by_type'][$pageType]['blocks'] ?? null)
            ? $scope['virtual_pages_by_type'][$pageType]['blocks']
            : [];
        $match = $this->findBlockInBlocks($blocks, $requestedBlockId);
        if ($match === null) {
            $componentCode = $this->resolveBlockComponentCode($candidate);
            if ($componentCode !== '') {
                $match = $this->findBlockInBlocks($blocks, $componentCode);
            }
        }
        $index = (int)($match['index'] ?? -1);
        if ($index >= 0 && \array_key_exists($index, $blocks)) {
            $blocks[$index] = $candidate;
            $scope['virtual_pages_by_type'][$pageType]['blocks'] = \array_values($blocks);
        }
        $scope['virtual_pages_by_type'][$pageType]['last_generated_at'] = $createdAt;

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function applyVirtualThemeComponentReplacement(
        array $scope,
        string $pageType,
        array $read,
        array $currentBlock,
        array $candidate,
        array $metadata
    ): array {
        $themeId = (int)($currentBlock['_pb_server_virtual_theme_id'] ?? $this->resolveVirtualThemeId($scope));
        if ($themeId <= 0) {
            return $this->error('VIRTUAL_THEME_NOT_FOUND', 'Virtual theme not found during replacement.', [
                'page_type' => $pageType,
                'block_id' => (string)($read['block_id'] ?? ''),
            ]);
        }

        $componentCode = $this->resolveBlockComponentCode($candidate);
        if ($componentCode === '') {
            $componentCode = \trim((string)($read['component_code'] ?? $read['block_id'] ?? ''));
        }
        if ($componentCode === '') {
            return $this->error('VIRTUAL_THEME_COMPONENT_NOT_FOUND', 'Virtual theme component not found during replacement.', [
                'page_type' => $pageType,
                'block_id' => (string)($read['block_id'] ?? ''),
            ]);
        }

        $config = \is_array($candidate['config'] ?? null) ? $candidate['config'] : [];
        $templateContent = $this->resolveVirtualThemeReplacementTemplate($currentBlock, $candidate);
        if (\trim($templateContent) === '') {
            return $this->error('BLOCK_VALIDATION_FAILED', 'Replacement block template is empty.', [
                'page_type' => $pageType,
                'block_id' => (string)($read['block_id'] ?? ''),
            ]);
        }

        $candidate[self::META_COMPONENT_CODE] = $componentCode;
        $candidate[self::META_REGION] = 'content';
        $candidate[self::META_TEMPLATE_PHTML] = $templateContent;
        $renderValidation = $this->validateReplacementRenderable($candidate);
        if (empty($renderValidation['valid'])) {
            return $this->error('BLOCK_RENDER_FAILED', 'Replacement block cannot be rendered.', [
                'page_type' => $pageType,
                'block_id' => (string)($read['block_id'] ?? ''),
                'component_code' => $componentCode,
                'errors' => $renderValidation['errors'],
            ]);
        }

        $createdAt = \date('Y-m-d H:i:s');
        $beforeBlock = $currentBlock;

        $layouts = \is_array($scope['page_type_layouts'] ?? null) ? $scope['page_type_layouts'] : [];
        $layout = \is_array($layouts[$pageType] ?? null) ? $layouts[$pageType] : [];
        $content = \is_array($layout['content'] ?? null) ? $layout['content'] : [];
        $layoutMatch = $this->findVirtualThemeLayoutContentRow($scope, $pageType, $componentCode);
        $layoutIndex = (int)($layoutMatch['index'] ?? -1);
        if ($layoutIndex >= 0 && \array_key_exists($layoutIndex, $content) && \is_array($content[$layoutIndex])) {
            $content[$layoutIndex] = \array_replace($content[$layoutIndex], [
                'code' => $componentCode,
                'config' => $config,
                'enabled' => true,
            ]);
            $layout['content'] = \array_values($content);
            $layouts[$pageType] = $layout;
            $scope['page_type_layouts'] = $layouts;
        }

        if (\is_array($scope['virtual_pages_by_type'][$pageType] ?? null)) {
            $scope['virtual_pages_by_type'][$pageType]['last_generated_at'] = $createdAt;
        }
        $scope['preview_page_type'] = $pageType;
        $scope['build_plan_execution_summary'] = $this->safeSummarize($scope);
        $scope['block_patch_history'] = $this->appendPatchHistory(
            \is_array($scope['block_patch_history'] ?? null) ? $scope['block_patch_history'] : [],
            $pageType,
            $componentCode,
            $beforeBlock,
            $candidate,
            $metadata,
            $createdAt
        );

        $this->virtualThemeService()->saveGeneratedContentComponent($themeId, $pageType, [
            'code' => $componentCode,
            'name' => (string)($currentBlock['_pb_server_component_name'] ?? $componentCode),
            'phtml' => $templateContent,
            'default_config' => $config,
            'sort_order' => (int)($currentBlock['_pb_server_sort_order'] ?? 0),
            'key' => $componentCode,
        ]);

        return [
            'success' => true,
            'page_type' => $pageType,
            'block_id' => $componentCode,
            'component_code' => $componentCode,
            'source' => (string)($read['source'] ?? 'virtual_theme_component'),
            'scope' => $scope,
            'before_block' => $beforeBlock,
            'after_block' => $candidate,
            'change_summary' => \trim((string)($metadata['change_summary'] ?? '')),
            'changed_fields' => $this->normalizeChangedFields($metadata['changed_fields'] ?? []),
            'reason' => \trim((string)($metadata['reason'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{index:int,row:array<string,mixed>}|null
     */
    private function findVirtualThemeLayoutContentRow(array $scope, string $pageType, string $blockId): ?array
    {
        $layouts = \is_array($scope['page_type_layouts'] ?? null) ? $scope['page_type_layouts'] : [];
        $layout = \is_array($layouts[$pageType] ?? null) ? $layouts[$pageType] : [];
        $content = \is_array($layout['content'] ?? null) ? $layout['content'] : [];
        $match = $this->findBlockInBlocks($content, $blockId);
        if ($match === null) {
            return null;
        }

        return [
            'index' => (int)($match['index'] ?? -1),
            'row' => \is_array($match['block'] ?? null) ? $match['block'] : [],
        ];
    }

    private function resolveVirtualThemeId(array $scope): int
    {
        $themeId = (int)($scope['virtual_theme_id'] ?? 0);
        if ($themeId > 0) {
            return $themeId;
        }
        $virtualTheme = \is_array($scope['virtual_theme'] ?? null) ? $scope['virtual_theme'] : [];

        return (int)($virtualTheme['virtual_theme_id'] ?? $virtualTheme['theme_id'] ?? $virtualTheme['id'] ?? 0);
    }

    private function loadVirtualThemeComponent(int $themeId, string $componentCode): ?VirtualThemeComponent
    {
        $candidates = \array_values(\array_unique(\array_filter([
            \trim($componentCode),
            \str_starts_with(\trim($componentCode), 'content/') ? '' : 'content/' . \trim($componentCode),
        ], static fn(string $candidate): bool => $candidate !== '')));

        foreach ($candidates as $candidate) {
            /** @var VirtualThemeComponent $component */
            $component = clone ObjectManager::getInstance(VirtualThemeComponent::class);
            $component->clearData()->clearQuery()
                ->where(VirtualThemeComponent::schema_fields_VIRTUAL_THEME_ID, $themeId)
                ->where(VirtualThemeComponent::schema_fields_COMPONENT_CODE, $candidate)
                ->where(VirtualThemeComponent::schema_fields_AREA, VirtualThemeComponent::AREA_FRONTEND)
                ->where(VirtualThemeComponent::schema_fields_IS_ACTIVE, 1)
                ->order(VirtualThemeComponent::schema_fields_ID, 'DESC')
                ->find()
                ->fetch();

            if ((int)$component->getId() > 0) {
                return $component;
            }
        }

        return null;
    }

    private function resolveVirtualThemeReplacementTemplate(array $currentBlock, array $candidate): string
    {
        $currentTemplate = (string)($currentBlock[self::META_TEMPLATE_PHTML] ?? '');
        $candidateTemplate = (string)($candidate[self::META_TEMPLATE_PHTML] ?? '');
        $candidateHtml = (string)($candidate['html'] ?? '');
        if (\trim($candidateHtml) !== '' && (
            \trim($candidateTemplate) === ''
            || $candidateTemplate === $currentTemplate
            || $candidateHtml !== (string)($currentBlock['html'] ?? '')
        )) {
            return $candidateHtml;
        }
        if (\trim($candidateTemplate) !== '') {
            return $candidateTemplate;
        }

        return $candidateHtml;
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
        if (!\array_key_exists('field_schema', $candidate)) {
            $candidate['field_schema'] = \is_array($currentBlock['field_schema'] ?? null)
                ? $currentBlock['field_schema']
                : [];
        }

        return $candidate;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function replacementProvidesServerTemplate(array $block): bool
    {
        if (\array_key_exists(self::META_TEMPLATE_PHTML, $block)) {
            return true;
        }
        return \is_array($block['block'] ?? null) && \array_key_exists(self::META_TEMPLATE_PHTML, $block['block']);
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
    private function validateReplacementRenderable(array $block, bool $allowPhpTemplate = true): array
    {
        $phtml = \trim((string)($block[self::META_TEMPLATE_PHTML] ?? ''));
        if ($phtml === '') {
            return ['valid' => true, 'errors' => []];
        }
        if (!$allowPhpTemplate && \preg_match('/<\?(?:php|=)?/i', $phtml)) {
            return ['valid' => false, 'errors' => ['replacement._pb_server_template_phtml must be static HTML when supplied by the patch']];
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
        $websiteProfile = \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [];
        $contentLocale = '';
        foreach ([
            $scope['content_locale'] ?? null,
            $websiteProfile['content_locale'] ?? null,
            $websiteProfile['default_locale'] ?? null,
            $scope['default_locale'] ?? null,
            $page['locale'] ?? null,
            $scope['plan_locale'] ?? null,
        ] as $candidate) {
            if (\is_scalar($candidate) && \trim((string)$candidate) !== '') {
                $contentLocale = \trim((string)$candidate);
                break;
            }
        }

        return [
            'page_type' => $pageType,
            'title' => (string)($page['title'] ?? ''),
            'handle' => (string)($page['handle'] ?? ''),
            'locale' => (string)($page['locale'] ?? $scope['plan_locale'] ?? ''),
            'content_locale' => $contentLocale,
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
            if (\is_string($key) && \in_array($key, ['virtual_pages_by_type', 'pagebuilder_pages_by_type', 'events', 'top_logs'], true)) {
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
        $visualContract = $this->buildPatchVisualContractPrompt($read, $scope);
        $currentBlockContext = $this->buildPatchCurrentBlockContext($read, $scope);
        $contentLocale = (string)($currentBlockContext['content_locale'] ?? $this->resolveLocale($read, $scope) ?? '');
        $languageContract = \is_array($currentBlockContext['language_contract'] ?? null)
            ? $currentBlockContext['language_contract']
            : $this->buildPatchLanguageContract([], $contentLocale);
        $payload = [
            'instruction' => $instruction,
            'content_locale' => $contentLocale,
            'language_contract' => $languageContract,
            'target' => [
                'page_type' => (string)($read['page_type'] ?? ''),
                'block_id' => (string)($read['block_id'] ?? ''),
                'component_code' => (string)($read['component_code'] ?? ''),
            ],
            'current_block' => $this->buildSafePatchPromptBlock(\is_array($read['block'] ?? null) ? $read['block'] : []),
            'current_block_context' => $currentBlockContext,
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
            . "CTX_WEBSITE_LANGUAGE (hard patch-local language contract):\n"
            . "- source_of_truth_locale: " . ($contentLocale !== '' ? $contentLocale : 'not provided') . "\n"
            . "- language_contract: " . $this->jsonEncodeForPrompt($languageContract, 900) . "\n"
            . "- HARD LANGUAGE CONTRACT: every visitor-visible string changed or newly generated for this block must be in source_of_truth_locale. Translate/rewrite plan text, existing copied labels, CTA labels, alt/title/aria text, placeholders, and form labels before returning JSON.\n\n"
            . "CTX_CURRENT_BLOCK_CONTEXT (hard patch-local block contract): "
            . $this->jsonEncodeForPrompt($currentBlockContext, 2200) . "\n"
            . "- Patch block-context execution rule: patch only this current block. Preserve the confirmed task/block goal, morphology_id, media strategy, required generated image bindings, output_contract, and acceptance rules unless the user's instruction directly edits a visitor-facing field allowed by this block.\n"
            . "- Patch anti-repetition rule: do not turn this block into the same title/paragraph/card/CTA shell as adjacent or sibling blocks; if the contract has morphology_id or diversity_constraints, keep the visible structure distinct.\n\n"
            . "Return one JSON object only. Required top-level keys: block, change_summary, changed_fields.\n"
            . "The first non-whitespace character must be { and the last non-whitespace character must be }.\n"
            . "Rules:\n"
            . "- Return a complete replacement block object in block.\n"
            . "- Keep block.block_id exactly the same.\n"
            . "- Do not add, delete, move, or mention other blocks.\n"
            . "- Preserve server metadata keys that start with _pb_server_; the backend will automatically carry omitted _pb_server_* metadata from the current block.\n"
            . "- Ensure block has type, config, html, and field_schema; copy current_block.field_schema unchanged when fields do not change.\n"
            . "- Put all HTML and CSS inside JSON string fields such as block.html. Do not return _pb_server_template_phtml unless the user's instruction explicitly asks for template logic changes.\n"
            . "- JSON transport self-check: escape every double quote inside HTML/CSS strings or use single-quoted HTML attributes. Do not put raw newlines, markdown fences, or a second object outside the JSON object.\n"
            . "- Template safety: for this partial patch, edit block.html/config only by default. Never copy, rewrite, invent, or emit PHP/PHTML code. If existing template contains PHP, omit _pb_server_template_phtml so the backend preserves it unchanged.\n"
            . "- Do not output raw HTML, CSS, PHTML, Markdown fences, comments, or prose outside JSON.\n"
            . "- Do not include reason, why, or decision_reason fields; use change_summary for the visible change summary.\n"
            . "- If _pb_server_template_phtml is present, keep it renderable.\n\n"
            . 'Example replacement JSON shape (copy structure, not content): {"block":{"block_id":"same-block-id","type":"content","config":{"content.title":"Finished localized title","cta.text":"Finished CTA"},"html":"<section class=\'pb-c-root\'><h2>Finished localized title</h2></section>","field_schema":{"preserve":"current schema unless changed"}},"change_summary":"Updated visible copy and styling for the requested block.","changed_fields":["config.content.title","html"]}' . "\n\n"
            . ($visualContract !== '' ? $visualContract . "\n" : '')
            . "Visible copy governance:\n"
            . "- Visitor-facing copy and attributes must use source_of_truth_locale/content_locale exactly.\n"
            . "- Task labels, component labels, section labels, image-slot labels, queue/build-plan labels, and schema role labels are internal metadata. Never render them verbatim as headings, card titles, badges, CTA text, alt/title/aria text, or body copy.\n"
            . "- Before returning, compare all headings, card titles, badges, CTA labels, alt/title/aria attributes, and paragraphs against current_block labels/config labels/layout labels. Exact copies, title-cased copies, or instruction-shaped sentences starting with Introduce, Showcase, Answer, Reassure, Remove, Educate, Encourage, or Close are invalid; rewrite them into final customer-facing copy.\n"
            . "- Preserve the required generated image src and data-pb-ai-asset-slot attributes, but rewrite alt/title/aria text into concise visitor-facing descriptions instead of copying slot labels or prompt briefs.\n\n"
            . "- Never leave a browser-default `<a>` in block.html or template fields. If a link is necessary, it must have a component-prefixed class and explicit CSS in the same block/template; otherwise remove the link and use plain text.\n\n"
            . $payloadJson;
    }

    /**
     * @param array<string, mixed> $read
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function buildPatchCurrentBlockContext(array $read, array $scope): array
    {
        $pageType = \trim((string)($read['page_type'] ?? ''));
        $blockId = \trim((string)($read['block_id'] ?? ''));
        $componentCode = \trim((string)($read['component_code'] ?? ''));
        $layoutContext = \is_array($read['layout_context'] ?? null) ? $read['layout_context'] : [];
        $task = $this->resolvePatchBuildTask($scope, $pageType, $blockId, $componentCode, $layoutContext);
        $contentLocale = $this->resolvePatchContentLocale($read, $scope, $task);
        $languageContract = $this->buildPatchLanguageContract($task, $contentLocale);
        $blockContract = $this->resolvePatchBlockContract($task, $scope, $pageType, $blockId, $componentCode, $layoutContext);
        $blockTask = \is_array($task['block_task'] ?? null) ? $task['block_task'] : [];
        $taskScript = \is_array($task['task_script'] ?? null) ? $task['task_script'] : [];
        $planContext = \is_array($task['plan_context'] ?? null) ? $task['plan_context'] : [];

        return [
            'block_context_source' => $task !== [] ? 'confirmed_build_task' : 'current_rendered_block',
            'content_locale' => $contentLocale,
            'language_contract' => $languageContract,
            'task_identity' => [
                'task_key' => (string)($task['task_key'] ?? ''),
                'task_type' => (string)($task['task_type'] ?? ''),
                'page_type' => $pageType,
                'section_code' => (string)($task['section_code'] ?? $componentCode),
                'section_key' => (string)($task['section_key'] ?? ''),
                'block_key' => (string)($task['block_key'] ?? $blockId),
                'block_id' => $blockId,
                'component_code' => $componentCode,
            ],
            'block_contract' => $blockContract,
            'image_intent' => \is_array($blockTask['image_intent'] ?? null)
                ? $blockTask['image_intent']
                : (\is_array($planContext['block_image_intent'] ?? null) ? $planContext['block_image_intent'] : []),
            'content_plan' => \is_array($blockTask['content_plan'] ?? null) ? $blockTask['content_plan'] : [],
            'output_contract' => \is_array($taskScript['output_contract'] ?? null) ? $taskScript['output_contract'] : [],
            'acceptance' => \is_array($taskScript['acceptance'] ?? null) ? $taskScript['acceptance'] : [],
            'diversity_constraints' => \is_array($blockContract['diversity_constraints'] ?? null) ? $blockContract['diversity_constraints'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function buildSafePatchPromptBlock(array $block): array
    {
        if (\array_key_exists(self::META_TEMPLATE_PHTML, $block)) {
            $template = (string)$block[self::META_TEMPLATE_PHTML];
            unset($block[self::META_TEMPLATE_PHTML]);
            $block['_pb_server_template_phtml_preserved_by_backend'] = [
                'present' => \trim($template) !== '',
                'length' => \strlen($template),
            ];
        }

        return $block;
    }

    /**
     * @param array<string, mixed> $read
     * @param array<string, mixed> $scope
     */
    private function buildPatchVisualContractPrompt(array $read, array $scope): string
    {
        $renderer = $this->getVisualBlockContractRenderer();
        $brief = $this->buildPatchVisualContractBrief($read, $scope);
        $locale = (string)($brief['content_locale'] ?? '');
        $hasVerifiedHeroImage = $this->patchHasVerifiedHeroImage($read);

        return "Patch quality contract (same standard as full generation; patch mode must not weaken layout, role fidelity, or visual quality):\n"
            . $renderer->renderSectionVisualContract(
                $this->resolvePatchThemePalette($scope),
                $brief,
                $locale,
                $hasVerifiedHeroImage
            );
    }

    /**
     * @param array<string, mixed> $read
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function buildPatchVisualContractBrief(array $read, array $scope): array
    {
        $pageType = \trim((string)($read['page_type'] ?? ''));
        $blockId = \trim((string)($read['block_id'] ?? ''));
        $componentCode = \trim((string)($read['component_code'] ?? ''));
        $pageContext = \is_array($read['page_context'] ?? null) ? $read['page_context'] : [];
        $layoutContext = \is_array($read['layout_context'] ?? null) ? $read['layout_context'] : [];
        $task = $this->resolvePatchBuildTask($scope, $pageType, $blockId, $componentCode, $layoutContext);
        $planContext = \is_array($task['plan_context'] ?? null) ? $task['plan_context'] : [];
        $blockTask = \is_array($task['block_task'] ?? null) ? $task['block_task'] : [];
        $taskScript = \is_array($task['task_script'] ?? null) ? $task['task_script'] : [];
        $pagePlanBlock = $this->resolvePatchPagePlanBlock($scope, $pageType, $blockId, $componentCode, $layoutContext);
        $blockGoal = $this->firstNonEmptyScalar([
            $planContext['block_goal'] ?? null,
            $planContext['stage1_block_goal'] ?? null,
            $blockTask['task_goal'] ?? null,
            $pagePlanBlock['block_goal'] ?? null,
            $pagePlanBlock['execution_script']['core_copy'] ?? null,
            $pagePlanBlock['execution_script'] ?? null,
        ]);
        $pageGoal = $this->firstNonEmptyScalar([
            $planContext['page_goal'] ?? null,
            $pageContext['goal'] ?? null,
            $pageContext['summary'] ?? null,
        ]);
        $pageFlowRole = $this->firstNonEmptyScalar([
            $planContext['page_flow_role'] ?? null,
            $pagePlanBlock['page_flow_role'] ?? null,
        ]);
        $blockKey = $this->firstNonEmptyScalar([
            $layoutContext['block_key'] ?? null,
            $task['block_key'] ?? null,
            $task['section_key'] ?? null,
            $pagePlanBlock['block_key'] ?? null,
            $blockId,
        ]);

        return [
            'content_locale' => $this->resolvePatchContentLocale($read, $scope, $task),
            'task_key' => (string)($task['task_key'] ?? ''),
            'section_code' => (string)($task['section_code'] ?? $componentCode),
            'block_key' => $blockKey,
            'page_type' => $pageType,
            'page_goal' => $pageGoal,
            'page_flow_role' => $pageFlowRole,
            'block_goal' => $blockGoal,
            'stage1_block_content' => $this->firstNonEmptyScalar([
                $planContext['stage1_block_content'] ?? null,
                $pagePlanBlock['content_brief'] ?? null,
                $taskScript['story_goal'] ?? null,
            ]),
            'must_include_facts' => $this->collectPatchFacts($taskScript, $pagePlanBlock),
            'role_fidelity_hint' => $this->buildPatchRoleFidelityHint($blockKey, $pageFlowRole, $blockGoal),
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, string>
     */
    private function resolvePatchThemePalette(array $scope): array
    {
        $palette = [];
        foreach ([
            $scope['theme_context_snapshot']['palette'] ?? null,
            $scope['theme_context_snapshot']['theme_design']['color_scheme'] ?? null,
            $scope['theme_design']['color_scheme'] ?? null,
            $scope['theme_style']['palette'] ?? null,
            $scope['palette'] ?? null,
            $scope['plan_json']['theme_design']['color_scheme'] ?? null,
            $scope['plan_json']['palette'] ?? null,
        ] as $candidate) {
            if (!\is_array($candidate)) {
                continue;
            }
            foreach ($candidate as $key => $value) {
                if (!\is_string($key) || !\is_scalar($value)) {
                    continue;
                }
                $color = \trim((string)$value);
                if (\preg_match('/^#[0-9a-f]{6,8}$/i', $color) !== 1) {
                    continue;
                }
                $palette[$key] = $color;
            }
            if ($palette !== []) {
                break;
            }
        }

        return $palette;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $layoutContext
     * @return array<string, mixed>
     */
    private function resolvePatchBuildTask(
        array $scope,
        string $pageType,
        string $blockId,
        string $componentCode,
        array $layoutContext
    ): array {
        $buildTasks = $this->collectPatchBuildTaskDefinitions($scope, $pageType);
        $blockKey = \trim((string)($layoutContext['block_key'] ?? ''));
        $sharedRegion = $this->resolveSharedComponentRegionForBlockId($pageType, $componentCode !== '' ? $componentCode : $blockId);
        foreach ($buildTasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $taskPageType = \trim((string)($task['page_type'] ?? ''));
            $taskRegion = \trim((string)($task['region'] ?? $task['shared_region'] ?? ''));
            if ($sharedRegion !== '') {
                if ($taskRegion !== $sharedRegion && \trim((string)($task['task_key'] ?? '')) !== 'shared:' . $sharedRegion) {
                    continue;
                }
            } elseif ($taskPageType !== $pageType) {
                continue;
            }
            if ($blockKey !== '' && \trim((string)($task['block_key'] ?? '')) === $blockKey) {
                return $task;
            }
            if ($componentCode !== '' && \trim((string)($task['section_code'] ?? '')) === $componentCode) {
                return $task;
            }
            if ($blockId !== '' && \trim((string)($task['section_key'] ?? '')) === $blockId) {
                return $task;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    private function collectPatchBuildTaskDefinitions(array $scope, string $pageType): array
    {
        $tasks = [];
        $seen = [];
        $append = static function (array $task) use (&$tasks, &$seen): void {
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            $fingerprint = $taskKey !== ''
                ? $taskKey
                : \sha1(\json_encode($task, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: \serialize($task));
            if (isset($seen[$fingerprint])) {
                return;
            }
            $seen[$fingerprint] = true;
            $tasks[] = $task;
        };

        foreach ($this->buildTaskService()->listTaskKeysByPageType($scope, $pageType) as $taskKey) {
            $definition = $this->buildTaskService()->getTaskDefinition($scope, $taskKey);
            if (\is_array($definition)) {
                $append($definition);
            }
        }
        foreach (['shared:header', 'shared:footer'] as $taskKey) {
            $definition = $this->buildTaskService()->getTaskDefinition($scope, $taskKey);
            if (\is_array($definition)) {
                $append($definition);
            }
        }

        return $tasks;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $layoutContext
     * @return array<string, mixed>
     */
    private function resolvePatchPagePlanBlock(
        array $scope,
        string $pageType,
        string $blockId,
        string $componentCode,
        array $layoutContext
    ): array {
        $planBlocks = \is_array($scope['plan_json']['pages'][$pageType]['blocks'] ?? null)
            ? $scope['plan_json']['pages'][$pageType]['blocks']
            : [];
        $blockKey = \trim((string)($layoutContext['block_key'] ?? ''));
        foreach ($planBlocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            if ($blockKey !== '' && \trim((string)($block['block_key'] ?? '')) === $blockKey) {
                return $block;
            }
            if ($componentCode !== '' && \trim((string)($block['component_code'] ?? '')) === $componentCode) {
                return $block;
            }
            if ($blockId !== '' && \trim((string)($block['block_id'] ?? '')) === $blockId) {
                return $block;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $taskScript
     * @param array<string, mixed> $pagePlanBlock
     * @return list<string>
     */
    private function collectPatchFacts(array $taskScript, array $pagePlanBlock): array
    {
        $facts = [];
        foreach (\is_array($taskScript['field_content_requirements'] ?? null) ? $taskScript['field_content_requirements'] : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            foreach ([$row['sample'] ?? null, $row['implementation_note'] ?? null] as $candidate) {
                if (!\is_scalar($candidate)) {
                    continue;
                }
                $value = \trim((string)$candidate);
                if ($value !== '') {
                    $facts[] = $value;
                }
            }
        }
        foreach (\is_array($pagePlanBlock['required_facts'] ?? null) ? $pagePlanBlock['required_facts'] : [] as $fact) {
            if (\is_scalar($fact) && \trim((string)$fact) !== '') {
                $facts[] = \trim((string)$fact);
            }
        }

        return \array_values(\array_slice(\array_unique($facts), 0, 8));
    }

    private function buildPatchRoleFidelityHint(string $blockKey, string $pageFlowRole, string $blockGoal): string
    {
        $normalized = \strtolower($blockKey . ' ' . $pageFlowRole . ' ' . $blockGoal);
        if (\str_contains($normalized, 'contact_cta') || (\str_contains($normalized, 'cta') && \str_contains($normalized, 'contact'))) {
            return 'This is a final contact/download CTA band. Do not render channel cards, office/email grids, FAQ rows, a support form, placeholder emails, fake phone numbers, or invented contact handles.';
        }
        if (\str_contains($normalized, 'contact_methods') || \str_contains($normalized, 'support hours')) {
            return 'This is a contact-method hub. Render visible contact channels with separated labels and values; use exact email/phone/address/hours/WhatsApp values only when source facts provide them, otherwise use localized support promises. Do not collapse into a generic hero or final CTA strip.';
        }
        if (\str_contains($normalized, 'faq')) {
            return 'This block must render distinct question-answer rows, not cards, not hero copy, and not contact-method tiles.';
        }
        if (\str_contains($normalized, 'form')) {
            return 'This block must render a real guidance form with labels and fields, not contact cards, FAQ rows, or a generic CTA.';
        }

        return '';
    }

    /**
     * @param array<int, mixed> $values
     */
    private function firstNonEmptyScalar(array $values): string
    {
        foreach ($values as $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $value = \trim((string)$value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $read
     */
    private function patchHasVerifiedHeroImage(array $read): bool
    {
        $html = (string)($read['html'] ?? '');
        if ($html === '') {
            return false;
        }

        return \str_contains($html, 'pb-c-hero-img')
            || \str_contains($html, "data-pb-ai-image-role='generated-asset'")
            || \str_contains($html, 'data-pb-ai-image-role="generated-asset"');
    }

    private function getVisualBlockContractRenderer(): AiSiteVisualBlockContractRenderer
    {
        if ($this->visualBlockContractRenderer === null) {
            $this->visualBlockContractRenderer = new AiSiteVisualBlockContractRenderer();
        }

        return $this->visualBlockContractRenderer;
    }

    /**
     * @param array<string, mixed> $read
     * @param array<string, mixed> $scope
     */
    private function resolveLocale(array $read, array $scope): ?string
    {
        $pageType = \trim((string)($read['page_type'] ?? ''));
        $blockId = \trim((string)($read['block_id'] ?? ''));
        $componentCode = \trim((string)($read['component_code'] ?? ''));
        $layoutContext = \is_array($read['layout_context'] ?? null) ? $read['layout_context'] : [];
        $task = $this->resolvePatchBuildTask($scope, $pageType, $blockId, $componentCode, $layoutContext);
        $locale = $this->resolvePatchContentLocale($read, $scope, $task);

        return $locale !== '' ? $locale : null;
    }

    /**
     * @param array<string, mixed> $read
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $task
     */
    private function resolvePatchContentLocale(array $read, array $scope, array $task = []): string
    {
        $pageContext = \is_array($read['page_context'] ?? null) ? $read['page_context'] : [];
        $runtimeContext = \is_array($task['runtime_context'] ?? null) ? $task['runtime_context'] : [];
        $languageContract = \is_array($runtimeContext['language_contract'] ?? null) ? $runtimeContext['language_contract'] : [];
        $websiteProfile = \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [];

        foreach ([
            $runtimeContext['content_locale'] ?? null,
            $languageContract['source_of_truth_locale'] ?? null,
            $task['content_locale'] ?? null,
            $pageContext['content_locale'] ?? null,
            $scope['content_locale'] ?? null,
            $websiteProfile['content_locale'] ?? null,
            $websiteProfile['default_locale'] ?? null,
            $scope['default_locale'] ?? null,
            $pageContext['locale'] ?? null,
            $scope['plan_locale'] ?? null,
        ] as $candidate) {
            if (!\is_scalar($candidate)) {
                continue;
            }
            $locale = \trim((string)$candidate);
            if ($locale !== '') {
                return $locale;
            }
        }

        return 'zh_Hans_CN';
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function buildPatchLanguageContract(array $task, string $contentLocale): array
    {
        $runtimeContext = \is_array($task['runtime_context'] ?? null) ? $task['runtime_context'] : [];
        $languageContract = \is_array($runtimeContext['language_contract'] ?? null)
            ? $runtimeContext['language_contract']
            : [];
        $contentLocale = \trim($contentLocale);
        if ($contentLocale === '') {
            $contentLocale = \trim((string)($languageContract['source_of_truth_locale'] ?? 'zh_Hans_CN'));
        }

        return \array_replace([
            'source_of_truth_locale' => $contentLocale,
            'visible_copy_rule' => 'All visitor-facing copy and attributes changed by this block patch must use source_of_truth_locale.',
            'plan_text_rule' => 'Confirmed plan text is intent only; rewrite it into source_of_truth_locale before rendering.',
            'patch_scope_rule' => 'Patch only the current block; do not use sibling or full-page context as replacement content.',
        ], $languageContract, [
            'source_of_truth_locale' => $contentLocale,
        ]);
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $layoutContext
     * @return array<string, mixed>
     */
    private function resolvePatchBlockContract(
        array $task,
        array $scope,
        string $pageType,
        string $blockId,
        string $componentCode,
        array $layoutContext
    ): array {
        foreach ([
            $task['block_contract'] ?? null,
            $task['block_task']['block_contract'] ?? null,
            $task['block_task']['style_plan']['block_contract'] ?? null,
            $task['plan_context']['block']['block_contract'] ?? null,
            $task['plan_context']['block_contract'] ?? null,
            $task['implementation_contract']['block_contract'] ?? null,
        ] as $candidate) {
            if (\is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        $pagePlanBlock = $this->resolvePatchPagePlanBlock($scope, $pageType, $blockId, $componentCode, $layoutContext);
        if (\is_array($pagePlanBlock['block_contract'] ?? null) && $pagePlanBlock['block_contract'] !== []) {
            return $pagePlanBlock['block_contract'];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $value
     */
    private function jsonEncodeForPrompt(array $value, int $limit): string
    {
        $json = \json_encode(
            $value,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE
        );
        if (!\is_string($json) || $json === '') {
            return '{}';
        }
        if ($limit > 0 && \strlen($json) > $limit) {
            return \substr($json, 0, \max(1, $limit - 20)) . '...truncated';
        }

        return $json;
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

    private function truthyScopeValue(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (int)$value !== 0;
        }
        if (\is_string($value)) {
            $normalized = \strtolower(\trim($value));

            return \in_array($normalized, ['1', 'true', 'yes', 'on'], true);
        }

        return !empty($value);
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

    private function clipText(string $value, int $limit): string
    {
        $value = \trim($value);
        if ($limit <= 0 || $value === '') {
            return '';
        }
        if (\mb_strlen($value, 'UTF-8') <= $limit) {
            return $value;
        }

        return \rtrim(\mb_substr($value, 0, \max(1, $limit - 1), 'UTF-8')) . '…';
    }

    private function jsonParser(): AiResponseJsonParser
    {
        return $this->jsonParser ?? ObjectManager::getInstance(AiResponseJsonParser::class);
    }

    private function aiService(): AiService
    {
        return $this->aiService ?? ObjectManager::getInstance(AiService::class);
    }

    private function virtualThemeService(): AiSiteVirtualThemeService
    {
        return $this->virtualThemeService ?? ObjectManager::getInstance(AiSiteVirtualThemeService::class);
    }

}
