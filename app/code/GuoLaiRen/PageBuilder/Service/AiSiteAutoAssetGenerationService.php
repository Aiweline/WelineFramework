<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;

class AiSiteAutoAssetGenerationService
{
    private const DEFAULT_LIMIT = 4;
    private const FAILURE_TRAIL_MAX_ITEMS = 80;
    private const FAILURE_TRAIL_MESSAGE_MAX_LEN = 800;
    private const IMAGE_GENERATION_TIMEOUT_SECONDS = 20;
    private const IMAGE_GENERATION_MAX_ATTEMPTS = 1;
    private const REQUEST_CTX_INLINE_IMAGE_GENERATION_DISABLED = 'pagebuilder.ai.inline_image_generation.disabled';
    private const INLINE_IMAGE_GENERATION_DISABLED_REASON = 'disabled_by_test_switch';

    public function __construct(
        private readonly AiSiteAssetManifestService $manifestService,
        private readonly ?AiSiteReferenceImageInsightService $referenceImageInsightService = null,
        private readonly mixed $imageGenerator = null,
        private readonly mixed $buildReadinessInspector = null,
    ) {
    }

    /**
     * @param array<string,mixed> $scope
     * @return array{
     *   scope:array<string,mixed>,
     *   generated_slots:list<string>,
     *   failed_slots:list<array{slot_id:string,message:string}>
     * }
     */
    public function prepareBuildAssets(AiSiteAgentSession $session, int $adminId, array $scope, int $limit = self::DEFAULT_LIMIT): array
    {
        $scope = $this->ensureReferenceImageInsights($scope);
        $scope = $this->clearInvalidIdentityAssetFieldsFromScope($scope);
        $manifest = $this->manifestService->syncFromPlanJson($scope);
        // 无论何种模式，都清理既有的占位图，留空等待真实 AI 图片生成
        $placeholderUrls = $this->manifestService->extractPlaceholderAssetUrls($manifest);
        $manifest = $this->manifestService->discardPlaceholderGeneratedAssets($manifest);
        if ($placeholderUrls !== []) {
            $scope = $this->clearPlaceholderIdentityAssetsFromScope($scope, $placeholderUrls);
        }
        $foreignAssetUrls = [];
        $manifest = $this->discardForeignDomainGeneratedAssets($manifest, $scope, $session, $foreignAssetUrls);
        if ($foreignAssetUrls !== []) {
            $scope = $this->clearIdentityAssetUrlsFromScope($scope, $foreignAssetUrls);
        }
        $invalidIdentityAssetUrls = [];
        $manifest = $this->discardInvalidIdentityGeneratedAssets($manifest, $invalidIdentityAssetUrls);
        if ($invalidIdentityAssetUrls !== []) {
            $scope = $this->clearIdentityAssetUrlsFromScope($scope, $invalidIdentityAssetUrls);
        }
        $scope = $this->clearInvalidIdentityAssetFieldsFromScope($scope);
        $scope = $this->manifestService->rememberGeneratedSlotsInScope($scope, $manifest);
        $scope['asset_manifest'] = $manifest;
        $scope['verified_assets'] = $this->manifestService->extractVerifiedAssets($manifest);
        if ($limit <= 0) {
            return [
                'scope' => $scope,
                'generated_slots' => [],
                'failed_slots' => [],
            ];
        }
        if ($this->isInlineImageGenerationDisabledForCurrentRequest()) {
            return [
                'scope' => $this->markImageGenerationDeferredInScope($scope, self::INLINE_IMAGE_GENERATION_DISABLED_REASON),
                'generated_slots' => [],
                'failed_slots' => [],
            ];
        }
        $prioritizeIdentityAssets = (int)($scope['auto_generate_identity_assets_first'] ?? 0) === 1;
        $identityOnly = (int)($scope['auto_asset_prebuild_identity_only'] ?? 0) === 1;
        if (!$identityOnly) {
            $this->assertBuildReadinessBeforeImageGeneration($scope);
        }

        $generatedSlots = [];
        $failedSlots = [];
        foreach ($this->pickPendingSlots($manifest, $limit, $prioritizeIdentityAssets, $identityOnly) as $slot) {
            $slotId = (string)($slot['slot_id'] ?? '');
            if ($slotId === '') {
                continue;
            }

            // 不生成占位图，留空等待真实 AI 图片生成
            if ($this->shouldUsePlaceholderFallback($scope)) {
                continue;
            }

            $prompt = '';
            try {
                $prompt = $this->manifestService->buildPrompt($slot, $scope);
                if ($prompt === '') {
                    throw new \RuntimeException('Asset slot prompt brief is empty: ' . $slotId);
                }

                $manifest = $this->manifestService->markGenerating($manifest, $slotId);
                $profileIdentityAsset = $this->materializeProfileIdentityAsset(
                    $scope,
                    $session,
                    $manifest,
                    $slotId,
                    $slot,
                    'auto_build'
                );
                if ($profileIdentityAsset !== []) {
                    $scope = $profileIdentityAsset['scope'];
                    $manifest = $profileIdentityAsset['manifest'];
                    $scope = $this->clearAssetImageGenerationFailureForSlot($scope, $slotId);
                    $generatedSlots[] = $slotId;
                    continue;
                }

                $result = $this->generateImage($prompt, $adminId, $slot);

                $image = $this->firstGeneratedImage($result);
                [$bytes, $mimeType] = $this->resolveImageBytes($image);
                if ($bytes === '') {
                    throw new \RuntimeException('Image generation returned empty image bytes.');
                }
                $this->assertIdentityAssetTransparentPng($slotId, $slot, $bytes, $mimeType);
                $generatedModel = (string)($result['model'] ?? '');
                $revisedPrompt = (string)($image['revised_prompt'] ?? '');

                $relativePath = $this->buildTargetPath($scope, $session, $slotId, $bytes, $mimeType);
                $absolutePath = BP . \str_replace('/', \DIRECTORY_SEPARATOR, $relativePath);
                $directory = \dirname($absolutePath);
                if (!\is_dir($directory) && !\mkdir($directory, 0755, true) && !\is_dir($directory)) {
                    throw new \RuntimeException('Failed to create image asset directory: ' . $directory);
                }
                if (\file_put_contents($absolutePath, $bytes) === false) {
                    throw new \RuntimeException('Failed to write image asset file: ' . $absolutePath);
                }
                unset($bytes, $image, $result);

                $finalUrl = '/' . \str_replace('\\', '/', $relativePath);
                $variant = [
                    'url' => $finalUrl,
                    'mime_type' => $mimeType,
                    'path' => $relativePath,
                    'mode' => 'auto_build',
                    'model' => $generatedModel,
                    'revised_prompt' => $revisedPrompt,
                ];
                $manifest = $this->manifestService->recordGenerated($manifest, $slotId, $finalUrl, $variant);
                $scope = $this->manifestService->rememberGeneratedSlotInScope($scope, $manifest, $slotId);
                $scope['asset_manifest'] = $manifest;
                $scope['verified_assets'] = $this->manifestService->extractVerifiedAssets($manifest);
                $scope = $this->applyIdentityAssetPatchToScope($scope, $slot, $finalUrl);
                $scope = $this->applyThemeLogoOptionPatchToScope($scope, $slot, $finalUrl);
                $scope = $this->clearAssetImageGenerationFailureForSlot($scope, $slotId);
                $generatedSlots[] = $slotId;
            } catch (\Throwable $throwable) {
                unset($bytes, $image, $result);
                $manifest = $this->manifestService->recordError($manifest, $slotId, $throwable->getMessage());
                $scope['asset_manifest'] = $manifest;
                $scope['verified_assets'] = $this->manifestService->extractVerifiedAssets($manifest);
                $failedSlots[] = [
                    'slot_id' => $slotId,
                    'message' => $throwable->getMessage(),
                ];
            }
        }

        $scope = $this->recordAssetImageGenerationFailures($scope, $failedSlots);

        return [
            'scope' => $scope,
            'generated_slots' => $generatedSlots,
            'failed_slots' => $failedSlots,
        ];
    }

    /**
     * Generate one image exactly when a block needs it. This keeps image work
     * inside the block build flow instead of prebuilding a separate asset batch.
     *
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $slotSeed
     * @return array{
     *   scope:array<string,mixed>,
     *   slot_id:string,
     *   final_url:string,
     *   generated:bool,
     *   failed_slot?:array{slot_id:string,message:string}
     * }
     */
    public function generateSlotAsset(AiSiteAgentSession $session, int $adminId, array $scope, string $slotId, array $slotSeed = []): array
    {
        $slotId = \trim($slotId);
        if ($slotId === '') {
            return [
                'scope' => $scope,
                'slot_id' => '',
                'final_url' => '',
                'generated' => false,
            ];
        }

        $scope = $this->ensureReferenceImageInsights($scope);
        $scope = $this->clearInvalidIdentityAssetFieldsFromScope($scope);
        $manifest = $this->manifestService->syncFromPlanJson($scope);
        $placeholderUrls = $this->manifestService->extractPlaceholderAssetUrls($manifest);
        $manifest = $this->manifestService->discardPlaceholderGeneratedAssets($manifest);
        if ($placeholderUrls !== []) {
            $scope = $this->clearPlaceholderIdentityAssetsFromScope($scope, $placeholderUrls);
        }
        $scope = $this->manifestService->rememberGeneratedSlotsInScope($scope, $manifest);

        $slot = $this->manifestService->getSlot($manifest, $slotId);
        if ($slot === []) {
            $manifest = $this->manifestService->upsert($manifest, \array_replace([
                'slot_id' => $slotId,
                'slot_type' => 'section_image',
                'kind' => 'section_visual',
                'source' => 'planned',
                'status' => 'pending',
                'final_url' => '',
                'locked_by_user' => 0,
            ], $slotSeed));
            $slot = $this->manifestService->getSlot($manifest, $slotId);
        }

        $preverifiedSlot = $this->resolvePreverifiedSlot($scope, $manifest, $slot, $slotId, $session);
        if ($preverifiedSlot !== []) {
            $manifest = $this->manifestService->upsert($manifest, $preverifiedSlot);
            $slot = $this->manifestService->getSlot($manifest, $slotId);
        }

        $finalUrl = \trim((string)($slot['final_url'] ?? ''));
        if (
            $finalUrl !== ''
            && !$this->isFinalUrlUnderCurrentDomainAssetPath($finalUrl, $scope, $session)
            && !$this->manifestService->isReusableSessionBlockAsset($scope, $slot, $finalUrl)
        ) {
            $slot['final_url'] = '';
            $slot['url'] = '';
            $slot['variants'] = [];
            $slot['status'] = 'pending';
            $manifest = $this->manifestService->upsert($manifest, $slot);
            $finalUrl = '';
        }
        if ($finalUrl !== '' && $this->identityAssetFinalUrlNeedsRegeneration($slotId, $slot, $finalUrl)) {
            $slot['locked_by_user'] = 0;
            $slot['final_url'] = '';
            $slot['url'] = '';
            $slot['variants'] = [];
            $slot['source'] = 'planned';
            $slot['status'] = 'pending';
            $slot['updated_at'] = \date('Y-m-d H:i:s');
            $manifest['slots'][(string)($slot['slot_id'] ?? $slotId)] = $slot;
            $manifest['updated_at'] = \date('Y-m-d H:i:s');
            $finalUrl = '';
        }
        if ($finalUrl !== '') {
            $scope = $this->manifestService->rememberGeneratedSlotInScope($scope, $manifest, $slotId);
            $scope['asset_manifest'] = $manifest;
            $scope['verified_assets'] = $this->manifestService->extractVerifiedAssets($manifest);
            $scope = $this->applyIdentityAssetPatchToScope($scope, $slot, $finalUrl);
            $scope = $this->applyThemeLogoOptionPatchToScope($scope, $slot, $finalUrl);
            return [
                'scope' => $scope,
                'slot_id' => $slotId,
                'final_url' => $finalUrl,
                'generated' => false,
            ];
        }

        $scope['asset_manifest'] = $manifest;
        $scope['verified_assets'] = $this->manifestService->extractVerifiedAssets($manifest);
        if ($this->isInlineImageGenerationDisabledForCurrentRequest()) {
            return [
                'scope' => $this->markImageGenerationDeferredInScope($scope, self::INLINE_IMAGE_GENERATION_DISABLED_REASON, $slotId),
                'slot_id' => $slotId,
                'final_url' => '',
                'generated' => false,
            ];
        }
        if ($this->shouldUsePlaceholderFallback($scope)) {
            return [
                'scope' => $scope,
                'slot_id' => $slotId,
                'final_url' => '',
                'generated' => false,
            ];
        }
        $reusableFailure = $this->buildReusableFailedSlotResult($slot);
        if ($reusableFailure !== []) {
            $scope = $this->recordAssetImageGenerationFailures($scope, [$reusableFailure]);

            return [
                'scope' => $scope,
                'slot_id' => $slotId,
                'final_url' => '',
                'generated' => false,
                'failed_slot' => $reusableFailure,
            ];
        }

        try {
            $prompt = $this->manifestService->buildPrompt($slot, $scope);
            if ($prompt === '') {
                throw new \RuntimeException('Asset slot prompt brief is empty: ' . $slotId);
            }

            $manifest = $this->manifestService->markGenerating($manifest, $slotId);
            $profileIdentityAsset = $this->materializeProfileIdentityAsset(
                $scope,
                $session,
                $manifest,
                $slotId,
                $slot,
                'inline_block'
            );
            if ($profileIdentityAsset !== []) {
                $scope = $profileIdentityAsset['scope'];
                $manifest = $profileIdentityAsset['manifest'];
                $scope = $this->clearAssetImageGenerationFailureForSlot($scope, $slotId);

                return [
                    'scope' => $scope,
                    'slot_id' => $slotId,
                    'final_url' => (string)$profileIdentityAsset['final_url'],
                    'generated' => true,
                ];
            }

            $lastImageThrowable = null;
            $result = [];
            $image = [];
            $maxImageAttempts = $this->resolveImageGenerationMaxAttempts($slot);
            for ($attempt = 1; $attempt <= $maxImageAttempts; $attempt++) {
                try {
                    $result = $this->generateImage($prompt, $adminId, $slot);
                    $image = $this->firstGeneratedImage($result);
                    $lastImageThrowable = null;
                    break;
                } catch (\Throwable $imageThrowable) {
                    $lastImageThrowable = $imageThrowable;
                    \w_log_warning('[AI Site Asset Image Retry] slot=' . $slotId . ' attempt=' . $attempt . '/' . $maxImageAttempts . ': ' . $imageThrowable->getMessage());
                    if ($attempt >= $maxImageAttempts) {
                        throw $imageThrowable;
                    }
                }
            }
            if ($lastImageThrowable instanceof \Throwable) {
                throw $lastImageThrowable;
            }
            [$bytes, $mimeType] = $this->resolveImageBytes($image);
            if ($bytes === '') {
                throw new \RuntimeException('Image generation returned empty image bytes.');
            }
            $this->assertIdentityAssetTransparentPng($slotId, $slot, $bytes, $mimeType);
            $generatedModel = (string)($result['model'] ?? '');
            $revisedPrompt = (string)($image['revised_prompt'] ?? '');

            $relativePath = $this->buildTargetPath($scope, $session, $slotId, $bytes, $mimeType);
            $absolutePath = BP . \str_replace('/', \DIRECTORY_SEPARATOR, $relativePath);
            $directory = \dirname($absolutePath);
            if (!\is_dir($directory) && !\mkdir($directory, 0755, true) && !\is_dir($directory)) {
                throw new \RuntimeException('Failed to create image asset directory: ' . $directory);
            }
            if (\file_put_contents($absolutePath, $bytes) === false) {
                throw new \RuntimeException('Failed to write image asset file: ' . $absolutePath);
            }
            unset($bytes, $image, $result);

            $finalUrl = '/' . \str_replace('\\', '/', $relativePath);
            $variant = [
                'url' => $finalUrl,
                'mime_type' => $mimeType,
                'path' => $relativePath,
                'mode' => 'inline_block',
                'model' => $generatedModel,
                'revised_prompt' => $revisedPrompt,
            ];
            $manifest = $this->manifestService->recordGenerated($manifest, $slotId, $finalUrl, $variant);
            $scope = $this->manifestService->rememberGeneratedSlotInScope($scope, $manifest, $slotId);
            $scope['asset_manifest'] = $manifest;
            $scope['verified_assets'] = $this->manifestService->extractVerifiedAssets($manifest);
            $scope = $this->applyIdentityAssetPatchToScope($scope, $slot, $finalUrl);
            $scope = $this->applyThemeLogoOptionPatchToScope($scope, $slot, $finalUrl);
            $scope = $this->clearAssetImageGenerationFailureForSlot($scope, $slotId);

            return [
                'scope' => $scope,
                'slot_id' => $slotId,
                'final_url' => $finalUrl,
                'generated' => true,
            ];
        } catch (\Throwable $throwable) {
            unset($bytes, $image, $result);
            $manifest = $this->manifestService->recordError($manifest, $slotId, $throwable->getMessage());
            $scope['asset_manifest'] = $manifest;
            $scope['verified_assets'] = $this->manifestService->extractVerifiedAssets($manifest);
            $scope = $this->recordAssetImageGenerationFailures($scope, [[
                'slot_id' => $slotId,
                'message' => $throwable->getMessage(),
            ]]);

            return [
                'scope' => $scope,
                'slot_id' => $slotId,
                'final_url' => '',
                'generated' => false,
                'failed_slot' => [
                    'slot_id' => $slotId,
                    'message' => $throwable->getMessage(),
                ],
            ];
        }
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $slot
     * @return array<string,mixed>
     */
    private function resolvePreverifiedSlot(
        array $scope,
        array $manifest,
        array $slot,
        string $slotId,
        AiSiteAgentSession $session
    ): array {
        $slotId = \trim($slotId);
        if ($slotId === '') {
            return [];
        }

        $candidates = [];
        $scopeManifest = \is_array($scope['asset_manifest'] ?? null) ? $scope['asset_manifest'] : [];
        $scopeVerifiedAssets = $scopeManifest !== [] ? $this->manifestService->extractVerifiedAssets($scopeManifest) : [];
        $scopeSlots = \is_array($scope['asset_manifest']['slots'] ?? null) ? $scope['asset_manifest']['slots'] : [];
        if (\is_array($scopeSlots[$slotId] ?? null) && \array_key_exists($slotId, $scopeVerifiedAssets)) {
            $candidates[] = $scopeSlots[$slotId];
        }
        $manifestSlots = \is_array($manifest['slots'] ?? null) ? $manifest['slots'] : [];
        if (\is_array($manifestSlots[$slotId] ?? null)) {
            $candidates[] = $manifestSlots[$slotId];
        }
        $verifiedAssets = \is_array($scope['verified_assets'] ?? null) ? $scope['verified_assets'] : [];
        if (\array_key_exists($slotId, $verifiedAssets)) {
            $candidates[] = [
                'slot_id' => $slotId,
                'final_url' => $verifiedAssets[$slotId],
                'source' => 'verified_asset',
                'status' => 'done',
            ];
        }

        foreach ($candidates as $candidate) {
            if (!\is_array($candidate)) {
                continue;
            }
            $finalUrl = $this->firstNonEmptyString([
                $candidate['final_url'] ?? null,
                $candidate['url'] ?? null,
            ]);
            if ($finalUrl === '') {
                continue;
            }
            $candidateSlot = \array_replace($slot, $candidate, [
                'slot_id' => $slotId,
                'final_url' => $finalUrl,
                'url' => $finalUrl,
                'status' => \trim((string)($candidate['status'] ?? '')) ?: 'done',
                'source' => \trim((string)($candidate['source'] ?? '')) ?: 'verified_asset',
                'error_message' => '',
                'execution_token' => '',
            ]);
            if (!$this->preverifiedSlotUrlIsAllowed($candidateSlot, $finalUrl, $scope, $session)) {
                continue;
            }
            if ($this->identityAssetFinalUrlNeedsRegeneration($slotId, $candidateSlot, $finalUrl)) {
                continue;
            }

            return $candidateSlot;
        }

        return [];
    }

    /**
     * @param array<string,mixed> $slot
     * @param array<string,mixed> $scope
     */
    private function preverifiedSlotUrlIsAllowed(
        array $slot,
        string $finalUrl,
        array $scope,
        AiSiteAgentSession $session
    ): bool {
        $finalUrl = \trim($finalUrl);
        if ($finalUrl === '') {
            return false;
        }
        if ($this->preverifiedSlotLooksLikePlaceholder($slot, $finalUrl)) {
            return false;
        }

        return $this->isFinalUrlUnderCurrentDomainAssetPath($finalUrl, $scope, $session)
            || $this->manifestService->isReusableSessionBlockAsset($scope, $slot, $finalUrl);
    }

    /**
     * @param array<string,mixed> $slot
     */
    private function preverifiedSlotLooksLikePlaceholder(array $slot, string $finalUrl): bool
    {
        foreach (\is_array($slot['variants'] ?? null) ? $slot['variants'] : [] as $variant) {
            if (!\is_array($variant)) {
                continue;
            }
            if ((int)($variant['placeholder'] ?? 0) === 1) {
                return true;
            }
            foreach (['mode', 'model', 'source'] as $key) {
                $value = \strtolower(\trim((string)($variant[$key] ?? '')));
                if ($value === 'placeholder' || $value === 'local_composed' || \str_contains($value, 'local_composition')) {
                    return true;
                }
            }
            if (\trim((string)($variant['generation_fallback_reason'] ?? '')) !== '') {
                return true;
            }
        }

        $path = \parse_url($finalUrl, \PHP_URL_PATH);
        $path = \strtolower(\is_string($path) && $path !== '' ? $path : $finalUrl);
        $source = \strtolower(\trim((string)($slot['source'] ?? '')));

        return $source === 'generated'
            && \str_contains($path, '/pub/media/page-build/ai-generated/')
            && \str_ends_with($path, '.svg');
    }

    /**
     * @param list<mixed> $values
     */
    private function firstNonEmptyString(array $values): string
    {
        foreach ($values as $value) {
            if (!\is_scalar($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
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
     * Placeholder image files are an explicit operator fallback only. Normal
     * build flow must call the text-to-image model or expose a visible failure.
     *
     * Fake_mode 是 e2e/test 会话的契约：不能调真实的 image API（避免昂贵或不稳定的依赖）。
     * 强行契约要求此场景必须沿用第一阶段已落地的 verified_assets，并对剩余孤儿 slot 用占位写盘
     * 回退，而非冒险触发真实图像生成超时把整个构建拖死。
     *
     * @param array<string,mixed> $scope
     */
    private function assertBuildReadinessBeforeImageGeneration(array $scope): void
    {
        if (!$this->shouldRequireBuildReadinessBeforeImages($scope)) {
            return;
        }

        $report = $this->inspectBuildReadinessGate($scope);
        if (!empty($report['passed'])) {
            return;
        }

        $failures = [];
        foreach (\is_array($report['items'] ?? null) ? $report['items'] : [] as $item) {
            if (!\is_array($item) || empty($item['blocking']) || !empty($item['ok'])) {
                continue;
            }
            $key = \trim((string)($item['key'] ?? ''));
            $label = \trim((string)($item['label'] ?? ''));
            $failures[] = $key !== '' ? $key . ($label !== '' ? ': ' . $label : '') : ($label !== '' ? $label : 'unknown');
        }

        throw new \RuntimeException(
            'Build structure gate must pass before image generation: '
            . ($failures !== [] ? \implode('; ', \array_slice($failures, 0, 6)) : 'unknown build-structure failure')
        );
    }

    /**
     * @param array<string,mixed> $scope
     */
    private function shouldRequireBuildReadinessBeforeImages(array $scope): bool
    {
        if ((int)($scope['image_generation_requires_build_ready'] ?? 0) === 1) {
            return true;
        }
        if ((int)($scope['image_generation_build_ready_check_skip'] ?? 0) === 1) {
            return false;
        }

        $summary = \is_array($scope['plan_json_execution_summary'] ?? null)
            ? $scope['plan_json_execution_summary']
            : [];

        if ($summary === [] || (int)($summary['total'] ?? 0) <= 0) {
            return false;
        }

        return (int)($summary['pending'] ?? 0) <= 0
            && (int)($summary['running'] ?? 0) <= 0
            && (int)($summary['failed'] ?? 0) <= 0;
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function inspectBuildReadinessGate(array $scope): array
    {
        if (\is_callable($this->buildReadinessInspector)) {
            $report = ($this->buildReadinessInspector)($scope);
            return \is_array($report) ? $report : ['passed' => false, 'items' => []];
        }

        /** @var AiSiteQualityGateService $qualityGateService */
        $qualityGateService = ObjectManager::getInstance(AiSiteQualityGateService::class);
        return $qualityGateService->inspectBuildReadinessGate($scope);
    }

    /**
     * @param array<string,mixed> $scope
     */
    private function shouldUsePlaceholderFallback(array $scope): bool
    {
        return false;
    }

    /**
     * @param array<string,mixed> $slot
     * @return array{slot_id:string,message:string}|array{}
     */
    private function buildReusableFailedSlotResult(array $slot): array
    {
        $slotId = \trim((string)($slot['slot_id'] ?? ''));
        $status = \strtolower(\trim((string)($slot['status'] ?? '')));
        $message = \trim((string)($slot['error_message'] ?? ''));
        $signature = \trim((string)($slot['planning_signature'] ?? ''));
        if (
            $slotId === ''
            || $status !== 'error'
            || $message === ''
            || $signature === ''
            || \trim((string)($slot['final_url'] ?? '')) !== ''
            || (int)($slot['locked_by_user'] ?? 0) === 1
            || !$this->isHardImageProviderFailure($message)
        ) {
            return [];
        }

        return [
            'slot_id' => $slotId,
            'message' => $message . ' (same planning signature; provider call skipped)',
        ];
    }

    private function isHardImageProviderFailure(string $message): bool
    {
        $lower = \strtolower($message);
        foreach ([
            'quota',
            '403',
            'forbidden',
            'unauthorized',
            'permission',
            'billing',
            'balance',
            'credit',
            'insufficient',
            'pre-consumed',
            'rate limit',
            'too many requests',
            'api key',
            'provider account',
        ] as $needle) {
            if (\str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    /**
     * @param array<string,mixed> $slot
     */
    private function generateImage(string $prompt, int $adminId, array $slot): array
    {
        if ($this->isInlineImageGenerationDisabledForCurrentRequest()) {
            throw new \RuntimeException('Image generation disabled by test switch.');
        }
        $slotId = (string)($slot['slot_id'] ?? '');
        $slotType = \strtolower(\trim((string)($slot['slot_type'] ?? '')));
        $usage = \strtolower(\trim((string)($slot['usage'] ?? $slot['kind'] ?? '')));
        $isHeroImage = \in_array($slotType, ['hero_image'], true)
            || \in_array($usage, ['hero_banner_background', 'hero_image', 'section_background_cover'], true);
        $imageSize = $isHeroImage ? '1792x1024' : '1024x1024';
        $targetSize = \trim((string)($slot['target_size'] ?? ''));
        if ($targetSize === '') {
            $targetSize = $isHeroImage ? '1920x750' : $imageSize;
        }
        $aspectRatio = \trim((string)($slot['aspect_ratio'] ?? ''));
        if ($aspectRatio === '') {
            $aspectRatio = $isHeroImage ? '1920:750' : '1:1';
        }

        if ($this->imageGenerator !== null) {
            if (!\is_callable($this->imageGenerator)) {
                throw new \RuntimeException('Image generator callback is not callable.');
            }
            $result = ($this->imageGenerator)($prompt, $adminId, $slotId);
        } else {
            $identityParams = $this->buildIdentityImageGenerationParams($slotId, $slot);
            $imageTimeout = $this->resolveImageGenerationTimeout($slot);
            $result = \w_query('ai', 'generateImage', [
                'prompt' => $prompt,
                'scenario_code' => 'pagebuilder_ai_site_assets',
                'params' => [
                    'disable_conversation_history' => true,
                    'disable_conversation_persist' => true,
                    'allow_zero_balance_provider' => true,
                    'is_backend' => true,
                    'user_id' => $adminId,
                    'slot_id' => $slotId,
                    'size' => $imageSize,
                    'target_size' => $targetSize,
                    'aspect_ratio' => $aspectRatio,
                    'timeout' => $imageTimeout,
                    'image_timeout' => $imageTimeout,
                    'connect_timeout' => 10,
                ] + $identityParams,
            ]);
        }

        if (!\is_array($result)) {
            throw new \RuntimeException('Image generation returned invalid result.');
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $slot
     */
    private function resolveImageGenerationMaxAttempts(array $slot): int
    {
        foreach (['image_generation_max_attempts', 'max_image_generation_attempts'] as $key) {
            if (\is_numeric($slot[$key] ?? null)) {
                $attempts = (int)$slot[$key];
                if ($attempts > 0) {
                    return \max(1, \min(3, $attempts));
                }
            }
        }

        return self::IMAGE_GENERATION_MAX_ATTEMPTS;
    }

    /**
     * @param array<string,mixed> $slot
     */
    private function resolveImageGenerationTimeout(array $slot): int
    {
        foreach (['image_timeout', 'image_generation_timeout', 'timeout'] as $key) {
            if (\is_numeric($slot[$key] ?? null)) {
                $timeout = (int)$slot[$key];
                if ($timeout > 0) {
                    return \max(1, $timeout);
                }
            }
        }

        return self::IMAGE_GENERATION_TIMEOUT_SECONDS;
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function ensureReferenceImageInsights(array $scope): array
    {
        $insights = $this->getReferenceImageInsightService()->analyze($scope, $this->resolveInsightLocale($scope));
        if ($insights === []) {
            return $scope;
        }

        $scope['reference_image_insights'] = $insights;
        $signature = $this->getReferenceImageInsightService()->buildSignature($scope);
        if ($signature !== '') {
            $scope['reference_image_insights_signature'] = $signature;
        }

        return $scope;
    }

    /**
     * @param array<string,mixed> $scope
     */
    private function resolveInsightLocale(array $scope): string
    {
        foreach ([
            $scope['plan_locale'] ?? null,
            $scope['default_locale'] ?? null,
            $scope['default_language'] ?? null,
        ] as $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $locale = \trim((string)$value);
            if ($locale !== '') {
                return $locale;
            }
        }

        return '';
    }

    private function getReferenceImageInsightService(): AiSiteReferenceImageInsightService
    {
        return $this->referenceImageInsightService ?? new AiSiteReferenceImageInsightService();
    }

    private function isInlineImageGenerationDisabledForCurrentRequest(): bool
    {
        if ($this->isTruthyRuntimeSwitchValue(RequestContext::get(self::REQUEST_CTX_INLINE_IMAGE_GENERATION_DISABLED, false))) {
            return true;
        }

        return $this->isTruthyRuntimeSwitchValue(\getenv('PAGEBUILDER_AI_SITE_SKIP_INLINE_IMAGES') ?: null);
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function markImageGenerationDeferredInScope(array $scope, string $reason, string $slotId = ''): array
    {
        $scope['asset_image_generation_deferred'] = [
            'reason' => $reason,
            'slot_id' => \trim($slotId),
            'updated_at' => \date('Y-m-d H:i:s'),
        ];

        return $scope;
    }

    private function isTruthyRuntimeSwitchValue(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return $value !== 0 && $value !== 0.0;
        }
        if (!\is_scalar($value)) {
            return false;
        }

        $normalized = \strtolower(\trim((string)$value));
        if ($normalized === '') {
            return false;
        }

        return !\in_array($normalized, ['0', 'false', 'no', 'off', 'disable', 'disabled'], true);
    }

    /**
     * @param array<string,mixed> $scope
     * @param list<array{slot_id:string,message:string}> $failedSlots
     * @return array<string,mixed>
     */
    private function recordAssetImageGenerationFailures(array $scope, array $failedSlots): array
    {
        if ($failedSlots === []) {
            return $scope;
        }

        $trail = \is_array($scope['asset_image_generation_failures'] ?? null)
            ? $scope['asset_image_generation_failures']
            : [];
        $trail = $this->compactAssetImageGenerationFailureTrail($trail);
        $stamp = \date('Y-m-d H:i:s');
        foreach ($failedSlots as $failure) {
            $slotId = \trim((string)($failure['slot_id'] ?? ''));
            if ($slotId === '') {
                continue;
            }
            $trail = $this->filterAssetImageGenerationFailures($trail, $slotId);
            $trail[] = [
                'slot_id' => $slotId,
                'message' => (string)($failure['message'] ?? ''),
                'updated_at' => $stamp,
            ];
        }
        $scope['asset_image_generation_failures'] = $this->compactAssetImageGenerationFailureTrail($trail);

        return $scope;
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function clearAssetImageGenerationFailureForSlot(array $scope, string $slotId): array
    {
        $slotId = \trim($slotId);
        if ($slotId === '') {
            return $scope;
        }
        $trail = \is_array($scope['asset_image_generation_failures'] ?? null)
            ? $scope['asset_image_generation_failures']
            : [];
        $scope['asset_image_generation_failures'] = $this->compactAssetImageGenerationFailureTrail(
            $this->filterAssetImageGenerationFailures($trail, $slotId)
        );

        return $scope;
    }

    /**
     * @param list<mixed> $trail
     * @return list<mixed>
     */
    private function filterAssetImageGenerationFailures(array $trail, string $slotId): array
    {
        $slotId = \trim($slotId);
        if ($slotId === '') {
            return \array_values($trail);
        }

        return \array_values(\array_filter($trail, static function (mixed $row) use ($slotId): bool {
            if (!\is_array($row)) {
                return true;
            }
            return \trim((string)($row['slot_id'] ?? $row['slotId'] ?? '')) !== $slotId;
        }));
    }

    /**
     * @param list<mixed> $trail
     * @return list<array<string,mixed>>
     */
    private function compactAssetImageGenerationFailureTrail(array $trail): array
    {
        if ($trail === []) {
            return [];
        }

        $trail = \array_values(\array_filter($trail, static fn(mixed $row): bool => \is_array($row)));
        if (\count($trail) > self::FAILURE_TRAIL_MAX_ITEMS) {
            $trail = \array_slice($trail, -self::FAILURE_TRAIL_MAX_ITEMS);
        }

        foreach ($trail as &$row) {
            $slotId = \trim((string)($row['slot_id'] ?? $row['slotId'] ?? ''));
            if ($slotId !== '') {
                $row['slot_id'] = $slotId;
            }
            unset($row['slotId']);

            $message = \trim((string)($row['message'] ?? ''));
            if ($message !== '' && \mb_strlen($message) > self::FAILURE_TRAIL_MESSAGE_MAX_LEN) {
                $row['message'] = \mb_substr($message, 0, self::FAILURE_TRAIL_MESSAGE_MAX_LEN) . '...';
            }
        }
        unset($row);

        return $trail;
    }

    /**
     * @param array<string,mixed> $scope
     * @param list<string> $placeholderUrls
     * @return array<string,mixed>
     */
    private function clearPlaceholderIdentityAssetsFromScope(array $scope, array $placeholderUrls): array
    {
        return $this->clearIdentityAssetUrlsFromScope($scope, $placeholderUrls);
    }

    /**
     * @param array<string,mixed> $scope
     * @param list<string> $urls
     * @return array<string,mixed>
     */
    private function clearIdentityAssetUrlsFromScope(array $scope, array $urls): array
    {
        $urlMap = \array_fill_keys(\array_values(\array_filter(\array_map(
            static fn(string $url): string => \trim($url),
            $urls
        ))), true);
        if ($urlMap === []) {
            return $scope;
        }

        foreach (['logo', 'icon', 'favicon'] as $key) {
            $value = \trim((string)($scope[$key] ?? ''));
            if ($value !== '' && isset($urlMap[$value])) {
                unset($scope[$key]);
            }
        }

        $hasWebsiteProfile = \is_array($scope['website_profile'] ?? null);
        $websiteProfile = $hasWebsiteProfile ? $scope['website_profile'] : [];
        foreach (['logo', 'icon', 'favicon'] as $key) {
            $value = \trim((string)($websiteProfile[$key] ?? ''));
            if ($value !== '' && isset($urlMap[$value])) {
                unset($websiteProfile[$key]);
            }
        }
        if ($hasWebsiteProfile) {
            $scope['website_profile'] = $websiteProfile;
        }

        return $scope;
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function clearInvalidIdentityAssetFieldsFromScope(array $scope): array
    {
        foreach (['logo' => 'logo', 'icon' => 'icon', 'favicon' => 'icon'] as $key => $role) {
            if ($this->identityAssetUrlIsInvalidForRole((string)($scope[$key] ?? ''), $role)) {
                unset($scope[$key]);
            }
        }

        $websiteProfile = \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [];
        $hadWebsiteProfile = \is_array($scope['website_profile'] ?? null);
        foreach (['logo' => 'logo', 'icon' => 'icon', 'favicon' => 'icon'] as $key => $role) {
            if ($this->identityAssetUrlIsInvalidForRole((string)($websiteProfile[$key] ?? ''), $role)) {
                unset($websiteProfile[$key]);
            }
        }
        if ($hadWebsiteProfile) {
            $scope['website_profile'] = $websiteProfile;
        }

        $scope = $this->clearInvalidIdentityAssetReferences($scope);

        $defaultConfig = \is_array($scope['plan_json']['shared_components']['header']['default_config'] ?? null)
            ? $scope['plan_json']['shared_components']['header']['default_config']
            : [];
        foreach (['logo.image', 'logo.url', 'brand.logo'] as $key) {
            if ($this->identityAssetUrlIsInvalidForRole((string)($defaultConfig[$key] ?? ''), 'logo')) {
                unset($defaultConfig[$key]);
            }
        }
        if (\is_array($defaultConfig['logo'] ?? null)) {
            foreach (['image', 'url'] as $key) {
                if ($this->identityAssetUrlIsInvalidForRole((string)($defaultConfig['logo'][$key] ?? ''), 'logo')) {
                    unset($defaultConfig['logo'][$key]);
                }
            }
        }
        if ($defaultConfig !== []) {
            $scope['plan_json']['shared_components']['header']['default_config'] = $defaultConfig;
        }
        unset($scope['shared_components']);

        return $scope;
    }

    /**
     * @param array<string,mixed> $value
     * @return array<string,mixed>
     */
    private function clearInvalidIdentityAssetReferences(array $value): array
    {
        foreach ($value as $key => $item) {
            $normalizedKey = \strtolower(\trim((string)$key));
            if (\is_array($item)) {
                $value[$key] = $this->clearInvalidIdentityAssetReferences($item);
                continue;
            }
            if (!\is_string($item) || \trim($item) === '') {
                continue;
            }

            if (
                \in_array($normalizedKey, ['logo', 'logo.image', 'logo.url', 'brand.logo', 'identity.shared_logo_asset', 'shared_logo_asset'], true)
                && $this->identityAssetUrlIsInvalidForRole($item, 'logo')
            ) {
                unset($value[$key]);
                continue;
            }

            if (
                \in_array($normalizedKey, ['icon', 'favicon', 'site.icon', 'identity.shared_icon_asset', 'shared_icon_asset'], true)
                && $this->identityAssetUrlIsInvalidForRole($item, 'icon')
            ) {
                unset($value[$key]);
            }
        }

        return $value;
    }

    private function identityAssetUrlIsInvalidForRole(string $url, string $role): bool
    {
        $url = \trim($url);
        if ($url === '') {
            return false;
        }
        $path = \parse_url($url, \PHP_URL_PATH);
        $path = \is_string($path) && $path !== '' ? $path : $url;
        $path = '/' . \ltrim(\preg_replace('#/+#', '/', \str_replace('\\', '/', $path)) ?? $path, '/');
        $lowerPath = \strtolower($path);
        $isPageBuilderGeneratedAsset = \str_contains($lowerPath, '/pub/media/page-build/')
            && \str_contains($lowerPath, '/ai-generated/');
        if (!$isPageBuilderGeneratedAsset) {
            return false;
        }
        $expectedToken = $role === 'logo' ? 'identity-website-logo' : 'identity-site-title-icon';
        $hasSupportedExtension = \str_ends_with($lowerPath, '.png') || \str_ends_with($lowerPath, '.svg');
        if (!\str_contains($lowerPath, $expectedToken) || !$hasSupportedExtension) {
            return true;
        }
        $absolutePath = BP . \str_replace('/', \DIRECTORY_SEPARATOR, \ltrim($path, '/'));
        if (!\is_file($absolutePath)) {
            return true;
        }
        $bytes = @\file_get_contents($absolutePath);
        if (!\is_string($bytes) || $bytes === '') {
            return true;
        }

        return !AiSiteIdentityAssetTransparencyValidator::isAcceptableIdentityAsset(
            $bytes,
            $this->mimeTypeForIdentityAssetPath($path),
            $role
        );
    }

    /**
     * Local preview sessions must not keep AI-generated files under a stale
     * production-domain handle. Reset those slots so normal image generation
     * writes them under the current preview host.
     *
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $scope
     * @param list<string> $discardedUrls
     * @return array<string,mixed>
     */
    private function discardForeignDomainGeneratedAssets(
        array $manifest,
        array $scope,
        AiSiteAgentSession $session,
        array &$discardedUrls
    ): array {
        $discardedUrls = [];
        $slots = \is_array($manifest['slots'] ?? null) ? $manifest['slots'] : [];
        foreach ($slots as $slot) {
            if (!\is_array($slot)) {
                continue;
            }
            $slotId = \trim((string)($slot['slot_id'] ?? ''));
            $finalUrl = \trim((string)($slot['final_url'] ?? ''));
            if ($slotId === '' || $finalUrl === '' || !$this->isGeneratedPageBuildAssetUrl($finalUrl)) {
                continue;
            }
            if ($this->isFinalUrlUnderCurrentDomainAssetPath($finalUrl, $scope, $session)) {
                continue;
            }
            if ($this->manifestService->isReusableSessionBlockAsset($scope, $slot, $finalUrl)) {
                continue;
            }

            $discardedUrls[] = $finalUrl;
            $slot['locked_by_user'] = 0;
            $slot['final_url'] = '';
            $slot['url'] = '';
            $slot['variants'] = [];
            $slot['source'] = 'planned';
            $slot['status'] = 'pending';
            $slot['error_message'] = '';
            $slot['last_error'] = '';
            $slot['error'] = '';
            $slot['updated_at'] = \date('Y-m-d H:i:s');
            $manifest['slots'][$slotId] = $slot;
            $manifest['updated_at'] = \date('Y-m-d H:i:s');
        }

        $discardedUrls = \array_values(\array_unique($discardedUrls));
        return $manifest;
    }

    /**
     * @param array<string,mixed> $manifest
     * @param list<string> $discardedUrls
     * @return array<string,mixed>
     */
    private function discardInvalidIdentityGeneratedAssets(array $manifest, array &$discardedUrls): array
    {
        $discardedUrls = [];
        $slots = \is_array($manifest['slots'] ?? null) ? $manifest['slots'] : [];
        foreach ($slots as $slot) {
            if (!\is_array($slot)) {
                continue;
            }
            $slotId = \trim((string)($slot['slot_id'] ?? ''));
            $finalUrl = \trim((string)($slot['final_url'] ?? ''));
            if ($slotId === '' || $finalUrl === '' || !$this->identityAssetFinalUrlNeedsRegeneration($slotId, $slot, $finalUrl)) {
                continue;
            }

            $discardedUrls[] = $finalUrl;
            $slot['final_url'] = '';
            $slot['url'] = '';
            $slot['variants'] = [];
            $slot['source'] = 'planned';
            $slot['status'] = 'pending';
            $slot['error_message'] = '';
            $slot['last_error'] = '';
            $slot['error'] = '';
            $slot['updated_at'] = \date('Y-m-d H:i:s');
            $manifest['slots'][$slotId] = $slot;
            $manifest['updated_at'] = \date('Y-m-d H:i:s');
        }

        $discardedUrls = \array_values(\array_unique($discardedUrls));
        return $manifest;
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $slot
     * @return array<string,mixed>
     */
    private function applyThemeLogoOptionPatchToScope(array $scope, array $slot, string $finalUrl): array
    {
        $finalUrl = \trim($finalUrl);
        $optionId = $this->resolveThemeLogoOptionId($slot);
        if ($finalUrl === '' || $optionId === '') {
            return $scope;
        }
        $slotId = \trim((string)($slot['slot_id'] ?? ''));
        $scope = $this->applyThemeLogoOptionPatchToPayload($scope, $optionId, $slotId, $finalUrl);

        if (\is_array($scope['render_data_contract']['payload'] ?? null)) {
            $scope['render_data_contract']['payload'] = $this->applyThemeLogoOptionPatchToPayload(
                $scope['render_data_contract']['payload'],
                $optionId,
                $slotId,
                $finalUrl
            );
        }
        if (\is_array($scope['build_contracts']['render_data']['payload'] ?? null)) {
            $scope['build_contracts']['render_data']['payload'] = $this->applyThemeLogoOptionPatchToPayload(
                $scope['build_contracts']['render_data']['payload'],
                $optionId,
                $slotId,
                $finalUrl
            );
        }

        return $scope;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function applyThemeLogoOptionPatchToPayload(array $payload, string $optionId, string $slotId, string $finalUrl): array
    {
        if (!\is_array($payload['plan_json'] ?? null)) {
            $payload['plan_json'] = [];
        }
        if (!\is_array($payload['plan_json']['theme'] ?? null)) {
            $payload['plan_json']['theme'] = [];
        }
        if (!\is_array($payload['plan_json']['theme']['logo_generation'] ?? null)) {
            $payload['plan_json']['theme']['logo_generation'] = [
                'stage' => 'logo_generation',
                'status' => 'planned',
                'asset_slot_id' => 'plan:theme:logo_generation',
                'output_path' => 'plan_json.theme.logo_generation',
            ];
        }

        $logoGeneration = $payload['plan_json']['theme']['logo_generation'];
        $rawOptions = \is_array($logoGeneration['options'] ?? null) ? \array_values($logoGeneration['options']) : [];
        $options = [];
        $generatedCount = 0;
        for ($index = 0; $index < 4; $index++) {
            $number = $index + 1;
            $currentOptionId = 'logo_option_' . $number;
            $currentSlotId = 'plan:theme:logo_generation:option_' . $number;
            $option = \is_array($rawOptions[$index] ?? null) ? $rawOptions[$index] : [];
            $option['option_id'] = $currentOptionId;
            $option['asset_slot_id'] = $currentSlotId;
            $option['slot_type'] = 'logo_icon';
            $option['kind'] = 'logo_option';
            if (!isset($option['label']) || \trim((string)$option['label']) === '') {
                $option['label'] = 'Logo ' . $number;
            }
            if ($currentOptionId === $optionId) {
                $option['url'] = $finalUrl;
                $option['final_url'] = $finalUrl;
                $option['status'] = 'generated';
                $option['updated_at'] = \date('Y-m-d H:i:s');
            }
            if (\trim((string)($option['final_url'] ?? $option['url'] ?? '')) !== '') {
                $generatedCount++;
            }
            $options[] = $option;
        }
        $logoGeneration['options'] = $options;
        $logoGeneration['option_count'] = 4;
        $logoGeneration['updated_at'] = \date('Y-m-d H:i:s');
        if (\trim((string)($logoGeneration['selected_option_id'] ?? '')) === $optionId) {
            $logoGeneration['selected_asset_slot_id'] = $slotId !== '' ? $slotId : 'plan:theme:logo_generation:' . \str_replace('logo_', '', $optionId);
            $logoGeneration['selected_url'] = $finalUrl;
            $logoGeneration['status'] = 'selected';
        } elseif ($generatedCount >= 4) {
            $logoGeneration['status'] = 'generated';
        } elseif ($generatedCount > 0 && \strtolower(\trim((string)($logoGeneration['status'] ?? ''))) !== 'selected') {
            $logoGeneration['status'] = 'partial';
        }
        $payload['plan_json']['theme']['logo_generation'] = $logoGeneration;

        return $payload;
    }

    /**
     * @param array<string,mixed> $slot
     */
    private function resolveThemeLogoOptionId(array $slot): string
    {
        foreach ([
            $slot['option_id'] ?? null,
            $slot['logo_option_id'] ?? null,
            $slot['field'] ?? null,
            $slot['slot_id'] ?? null,
            $slot['id'] ?? null,
            $slot['key'] ?? null,
        ] as $value) {
            $text = \strtolower(\trim((string)$value));
            if ($text === '') {
                continue;
            }
            if (\preg_match('/logo_option_([1-4])/', $text, $matches)) {
                return 'logo_option_' . $matches[1];
            }
            if (\preg_match('/option[_:-]([1-4])/', $text, $matches)) {
                return 'logo_option_' . $matches[1];
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $slot
     * @return array<string,mixed>
     */
    private function applyIdentityAssetPatchToScope(array $scope, array $slot, string $finalUrl): array
    {
        $role = $this->resolveIdentityAssetRole($slot);
        if ($role === '' || \trim($finalUrl) === '') {
            return $scope;
        }
        if ($role === 'logo') {
            return $this->applyThemeLogoOptionPatchToScope($scope, $slot, $finalUrl);
        }

        $websiteProfile = \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [];
        if ($role === 'icon') {
            $scope['icon'] = $finalUrl;
            $scope['favicon'] = $finalUrl;
            $websiteProfile['icon'] = $finalUrl;
            $websiteProfile['favicon'] = $finalUrl;
        }
        $scope['website_profile'] = $websiteProfile;
        $scope = $this->applyIdentityAssetToRenderPayloads($scope, $role, $finalUrl);

        return $scope;
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function applyIdentityAssetToRenderPayloads(array $scope, string $role, string $finalUrl): array
    {
        $scope = $this->applyIdentityAssetToRenderPayload($scope, $role, $finalUrl);

        if (\is_array($scope['render_data_contract']['payload'] ?? null)) {
            $payload = $this->applyIdentityAssetToRenderPayload($scope['render_data_contract']['payload'], $role, $finalUrl);
            $scope['render_data_contract']['payload'] = $payload;
        }
        if (\is_array($scope['build_contracts']['render_data']['payload'] ?? null)) {
            $payload = $this->applyIdentityAssetToRenderPayload($scope['build_contracts']['render_data']['payload'], $role, $finalUrl);
            $scope['build_contracts']['render_data']['payload'] = $payload;
        }
        return $scope;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function applyIdentityAssetToRenderPayload(array $payload, string $role, string $finalUrl): array
    {
        if (!\is_array($payload['plan_json'] ?? null)) {
            $payload['plan_json'] = [];
        }
        if (!\is_array($payload['plan_json']['shared_components'] ?? null)) {
            $payload['plan_json']['shared_components'] = \is_array($payload['shared_components'] ?? null)
                ? $payload['shared_components']
                : [];
        }

        if ($role === 'logo') {
            if (\is_array($payload['plan_json']['shared_components']['header']['default_config'] ?? null)) {
                $payload['plan_json']['shared_components']['header']['default_config']['logo']['url'] = $finalUrl;
                $payload['plan_json']['shared_components']['header']['default_config']['logo']['image'] = $finalUrl;
                $payload['plan_json']['shared_components']['header']['default_config']['logo.url'] = $finalUrl;
                $payload['plan_json']['shared_components']['header']['default_config']['logo.image'] = $finalUrl;
                $payload['plan_json']['shared_components']['header']['default_config']['identity']['shared_logo_asset'] = $finalUrl;
                $payload['plan_json']['shared_components']['header']['default_config']['identity.shared_logo_asset'] = $finalUrl;
            }
            if (\is_array($payload['plan_json']['shared_components']['footer']['default_config'] ?? null)) {
                $payload['plan_json']['shared_components']['footer']['default_config']['identity']['shared_logo_asset'] = $finalUrl;
                $payload['plan_json']['shared_components']['footer']['default_config']['identity.shared_logo_asset'] = $finalUrl;
                $payload['plan_json']['shared_components']['footer']['default_config']['brand']['logo'] = $finalUrl;
                $payload['plan_json']['shared_components']['footer']['default_config']['brand.logo'] = $finalUrl;
            }
            foreach (\is_array($payload['plan_json']['pages'] ?? null) ? $payload['plan_json']['pages'] : [] as $pageType => $page) {
                if (!\is_string($pageType) || !\is_array($page)) {
                    continue;
                }
                if (\is_array($page['header']['config'] ?? null)) {
                    $page['header']['config']['logo']['url'] = $finalUrl;
                    $page['header']['config']['logo']['image'] = $finalUrl;
                    $page['header']['config']['logo.url'] = $finalUrl;
                    $page['header']['config']['logo.image'] = $finalUrl;
                    $page['header']['config']['identity']['shared_logo_asset'] = $finalUrl;
                    $page['header']['config']['identity.shared_logo_asset'] = $finalUrl;
                }
                if (\is_array($page['footer']['config'] ?? null)) {
                    $page['footer']['config']['identity']['shared_logo_asset'] = $finalUrl;
                    $page['footer']['config']['identity.shared_logo_asset'] = $finalUrl;
                    $page['footer']['config']['brand']['logo'] = $finalUrl;
                    $page['footer']['config']['brand.logo'] = $finalUrl;
                }
                $payload['plan_json']['pages'][$pageType] = $page;
            }
            if (\is_array($payload['asset_manifest']['slots']['identity:website-logo'] ?? null)) {
                $payload['asset_manifest']['slots']['identity:website-logo']['final_url'] = $finalUrl;
                $payload['asset_manifest']['slots']['identity:website-logo']['url'] = $finalUrl;
                if (\is_array($payload['asset_manifest']['slots']['identity:website-logo']['variants'][0] ?? null)) {
                    $payload['asset_manifest']['slots']['identity:website-logo']['variants'][0]['url'] = $finalUrl;
                    $payload['asset_manifest']['slots']['identity:website-logo']['variants'][0]['path'] = \ltrim($finalUrl, '/');
                }
            }
        } elseif ($role === 'icon') {
            if (\is_array($payload['asset_manifest']['slots']['identity:site-title-icon'] ?? null)) {
                $payload['asset_manifest']['slots']['identity:site-title-icon']['final_url'] = $finalUrl;
                $payload['asset_manifest']['slots']['identity:site-title-icon']['url'] = $finalUrl;
                if (\is_array($payload['asset_manifest']['slots']['identity:site-title-icon']['variants'][0] ?? null)) {
                    $payload['asset_manifest']['slots']['identity:site-title-icon']['variants'][0]['url'] = $finalUrl;
                    $payload['asset_manifest']['slots']['identity:site-title-icon']['variants'][0]['path'] = \ltrim($finalUrl, '/');
                }
            }
            if (\is_array($payload['plan_json']['shared_components']['header']['default_config'] ?? null)) {
                $payload['plan_json']['shared_components']['header']['default_config']['identity']['shared_icon_asset'] = $finalUrl;
                $payload['plan_json']['shared_components']['header']['default_config']['identity.shared_icon_asset'] = $finalUrl;
            }
            foreach (\is_array($payload['plan_json']['pages'] ?? null) ? $payload['plan_json']['pages'] : [] as $pageType => $page) {
                if (!\is_string($pageType) || !\is_array($page)) {
                    continue;
                }
                if (\is_array($page['header']['config'] ?? null)) {
                    $page['header']['config']['identity']['shared_icon_asset'] = $finalUrl;
                    $page['header']['config']['identity.shared_icon_asset'] = $finalUrl;
                }
                $payload['plan_json']['pages'][$pageType] = $page;
            }
        }

        unset($payload['shared_components']);

        return $payload;
    }

    /**
     * @param array<string,mixed> $slot
     */
    private function resolveIdentityAssetRole(array $slot): string
    {
        $slotId = \strtolower(\trim((string)($slot['slot_id'] ?? '')));
        $field = \strtolower(\trim((string)($slot['field'] ?? '')));
        $kind = \strtolower(\trim((string)($slot['kind'] ?? '')));

        if (\str_contains($slotId, 'identity:website-logo')) {
            return 'logo';
        }
        if (\str_contains($slotId, 'identity:site-title-icon')) {
            return 'icon';
        }
        if (\in_array($field, ['logo', 'logo.image', 'brand.logo'], true)) {
            return 'logo';
        }
        if (\in_array($field, ['icon', 'favicon', 'site.icon'], true)) {
            return 'icon';
        }
        if (\in_array($kind, ['website_logo', 'brand_logo'], true)) {
            return 'logo';
        }
        if (\in_array($kind, ['site_title_icon', 'favicon'], true)) {
            return 'icon';
        }

        return '';
    }

    /**
     * @param array<string,mixed> $manifest
     * @return list<array<string,mixed>>
     */
    private function pickPendingSlots(
        array $manifest,
        int $limit,
        bool $prioritizeIdentityAssets = false,
        bool $identityOnly = false
    ): array
    {
        $slots = \array_values(\array_filter(
            \is_array($manifest['slots'] ?? null) ? $manifest['slots'] : [],
            function ($slot) use ($identityOnly): bool {
                if (!\is_array($slot)) {
                    return false;
                }
                if ((int)($slot['locked_by_user'] ?? 0) === 1) {
                    return false;
                }
                if ($identityOnly && !$this->isTransparentIdentityAssetSlot((string)($slot['slot_id'] ?? ''), $slot)) {
                    return false;
                }
                $status = \strtolower(\trim((string)($slot['status'] ?? 'pending')));
                $finalUrl = \trim((string)($slot['final_url'] ?? ''));
                $needsRealImageRetry = $finalUrl !== '' && $this->slotNeedsRealImageRetry($slot);
                if (!$needsRealImageRetry && $status !== '' && !\in_array($status, ['pending', 'planned'], true)) {
                    return false;
                }
                if ($finalUrl !== '' && !$needsRealImageRetry) {
                    return false;
                }
                return true;
            }
        ));

        \usort($slots, function (array $left, array $right) use ($prioritizeIdentityAssets): int {
            $leftRank = [
                $this->slotBuildPriority($left, $prioritizeIdentityAssets),
                $this->slotPagePriority((string)($left['page_type'] ?? '')),
                (string)($left['slot_id'] ?? ''),
            ];
            $rightRank = [
                $this->slotBuildPriority($right, $prioritizeIdentityAssets),
                $this->slotPagePriority((string)($right['page_type'] ?? '')),
                (string)($right['slot_id'] ?? ''),
            ];
            foreach ([0, 1] as $index) {
                if ($leftRank[$index] !== $rightRank[$index]) {
                    return $leftRank[$index] <=> $rightRank[$index];
                }
            }
            return \strcmp((string)$leftRank[2], (string)$rightRank[2]);
        });

        return \array_slice($slots, 0, \max(0, $limit));
    }

    /**
     * @param array<string,mixed> $slot
     */
    private function slotBuildPriority(array $slot, bool $prioritizeIdentityAssets): int
    {
        $slotType = (string)($slot['slot_type'] ?? '');
        $required = (int)($slot['required'] ?? 0) === 1;
        if ($prioritizeIdentityAssets && $slotType === 'logo_icon') {
            return 0;
        }
        if ($required && $slotType === 'hero_image' && $this->slotPagePriority((string)($slot['page_type'] ?? '')) === 0) {
            return 1;
        }
        if ($slotType === 'hero_image') {
            return $required ? 1 : 10;
        }
        if ($required) {
            return $slotType === 'logo_icon' ? 50 : 2;
        }

        return match ($slotType) {
            'logo_icon' => $prioritizeIdentityAssets ? 20 : 50,
            'trust_brand_image' => 30,
            'section_image' => 40,
            default => 999,
        };
    }

    private function slotPagePriority(string $pageType): int
    {
        $pageType = \strtolower(\trim($pageType));
        return \in_array($pageType, ['home', 'home_page', 'index', 'landing_page'], true) ? 0 : 10;
    }

    /**
     * Placeholder assets are resumable build artifacts, not terminal success.
     *
     * @param array<string,mixed> $slot
     */
    private function slotNeedsRealImageRetry(array $slot): bool
    {
        foreach (\is_array($slot['variants'] ?? null) ? $slot['variants'] : [] as $variant) {
            if (!\is_array($variant)) {
                continue;
            }
            if ((int)($variant['placeholder'] ?? 0) === 1) {
                return true;
            }
            foreach (['mode', 'model', 'source'] as $key) {
                if (\strtolower(\trim((string)($variant[$key] ?? ''))) === 'placeholder') {
                    return true;
                }
            }
        }

        $source = \strtolower(\trim((string)($slot['source'] ?? '')));
        $finalUrl = \strtolower(\trim((string)($slot['final_url'] ?? '')));
        return $source === 'generated'
            && \str_contains($finalUrl, '/ai-generated/')
            && \str_ends_with($finalUrl, '.svg');
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function firstGeneratedImage(array $result): array
    {
        foreach (\is_array($result['images'] ?? null) ? $result['images'] : [] as $image) {
            if (\is_array($image)) {
                return $image;
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $image
     * @return array{0:string,1:string}
     */
    private function resolveImageBytes(array $image): array
    {
        $mimeType = \trim((string)($image['mime_type'] ?? 'image/png')) ?: 'image/png';
        $b64 = \trim((string)($image['b64_json'] ?? ''));
        if ($b64 !== '') {
            $bytes = \base64_decode($b64, true);
            if ($bytes === false) {
                throw new \RuntimeException('Image generation returned invalid base64 payload.');
            }
            return [$bytes, $mimeType];
        }

        $url = \trim((string)($image['url'] ?? ''));
        if ($url !== '') {
            if (\preg_match('#^data:([^;]+);base64,(.+)$#s', $url, $matches) === 1) {
                $bytes = \base64_decode(\preg_replace('/\s+/', '', (string)$matches[2]) ?? '', true);
                if ($bytes === false) {
                    throw new \RuntimeException('Image generation returned invalid data URL payload.');
                }
                return [$bytes, \strtolower((string)$matches[1]) ?: $mimeType];
            }
            return [$this->downloadImageUrl($url), $mimeType];
        }

        return ['', $mimeType];
    }

    private function downloadImageUrl(string $url): string
    {
        if (\function_exists('curl_init')) {
            $ch = \curl_init($url);
            \curl_setopt_array($ch, [
                \CURLOPT_RETURNTRANSFER => true,
                \CURLOPT_FOLLOWLOCATION => true,
                \CURLOPT_CONNECTTIMEOUT => 30,
                \CURLOPT_TIMEOUT => 120,
            ]);
            $bytes = \curl_exec($ch);
            $error = \curl_error($ch);
            $status = (int)\curl_getinfo($ch, \CURLINFO_HTTP_CODE);
            \curl_close($ch);
            if ($bytes !== false && ($status === 0 || ($status >= 200 && $status < 300))) {
                return (string)$bytes;
            }
            throw new \RuntimeException('Failed to download generated image URL: ' . ($error !== '' ? $error : ('HTTP ' . $status)));
        }

        $bytes = @\file_get_contents($url);
        if ($bytes === false) {
            throw new \RuntimeException('Failed to download generated image URL.');
        }

        return $bytes;
    }

    /**
     * @param array<string,mixed> $scope
     */
    private function buildTargetPath(
        array $scope,
        AiSiteAgentSession $session,
        string $slotId,
        string $bytes,
        string $mimeType
    ): string
    {
        $handle = $this->resolveTargetHandle($scope, $session);
        $safeSlot = $this->sanitizePathSegment($slotId);
        $hash = \substr(\sha1($slotId . ':' . $session->getPublicId() . ':' . $bytes), 0, 12);
        $extension = $this->extensionForMimeType($mimeType);

        return 'pub/media/page-build/ai-generated/' . $handle . '/' . $safeSlot . '-' . $hash . '.' . $extension;
    }

    private function extensionForMimeType(string $mimeType): string
    {
        $mimeType = \strtolower(\trim($mimeType));
        return match ($mimeType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            'image/svg+xml', 'image/svg' => 'svg',
            default => 'png',
        };
    }

    /**
     * @return array<string,string>
     */
    private function buildIdentityImageGenerationParams(string $slotId, array $slot = []): array
    {
        if (!$this->isTransparentIdentityAssetSlot($slotId, $slot)) {
            return [];
        }

        return [
            'output_format' => 'png',
            'background' => 'transparent',
            'identity_transparent_png_required' => true,
            'transparent_png_required' => true,
        ];
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $slot
     * @return array{scope:array<string,mixed>,manifest:array<string,mixed>,final_url:string}|array{}
     */
    private function materializeProfileIdentityAsset(
        array $scope,
        AiSiteAgentSession $session,
        array $manifest,
        string $slotId,
        array $slot,
        string $mode
    ): array {
        $profileAsset = $this->resolveProfileIdentityAssetBytes($scope, $slotId, $slot);
        if ($profileAsset === []) {
            return [];
        }

        $bytes = (string)$profileAsset['bytes'];
        $mimeType = (string)$profileAsset['mime_type'];
        $relativePath = $this->buildTargetPath($scope, $session, $slotId, $bytes, $mimeType);
        $absolutePath = BP . \str_replace('/', \DIRECTORY_SEPARATOR, $relativePath);
        $directory = \dirname($absolutePath);
        if (!\is_dir($directory) && !\mkdir($directory, 0755, true) && !\is_dir($directory)) {
            throw new \RuntimeException('Failed to create image asset directory: ' . $directory);
        }
        if (\file_put_contents($absolutePath, $bytes) === false) {
            throw new \RuntimeException('Failed to write image asset file: ' . $absolutePath);
        }

        $finalUrl = '/' . \str_replace('\\', '/', $relativePath);
        $variant = [
            'url' => $finalUrl,
            'mime_type' => $mimeType,
            'path' => $relativePath,
            'mode' => $mode,
            'model' => 'website_profile_identity_svg',
            'revised_prompt' => '',
        ];
        $manifest = $this->manifestService->recordGenerated($manifest, $slotId, $finalUrl, $variant);
        $scope = $this->manifestService->rememberGeneratedSlotInScope($scope, $manifest, $slotId);
        $scope['asset_manifest'] = $manifest;
        $scope['verified_assets'] = $this->manifestService->extractVerifiedAssets($manifest);
        $scope = $this->applyIdentityAssetPatchToScope($scope, $slot, $finalUrl);
        $scope = $this->applyThemeLogoOptionPatchToScope($scope, $slot, $finalUrl);

        return [
            'scope' => $scope,
            'manifest' => $manifest,
            'final_url' => $finalUrl,
        ];
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $slot
     * @return array{bytes:string,mime_type:string}|array{}
     */
    private function resolveProfileIdentityAssetBytes(array $scope, string $slotId, array $slot): array
    {
        if (!$this->isTransparentIdentityAssetSlot($slotId, $slot)) {
            return [];
        }

        $isIcon = \str_contains(\strtolower($slotId), 'site-title-icon')
            || \in_array(\strtolower(\trim((string)($slot['field'] ?? ''))), ['icon', 'favicon', 'site.icon'], true);
        $role = $isIcon ? 'icon' : 'logo';
        $candidates = $isIcon
            ? [
                $scope['website_profile']['icon'] ?? null,
                $scope['website_profile']['favicon'] ?? null,
                $scope['icon'] ?? null,
                $scope['favicon'] ?? null,
            ]
            : [
                $scope['website_profile']['logo'] ?? null,
                $scope['logo'] ?? null,
            ];

        foreach ($candidates as $candidate) {
            if (!\is_scalar($candidate)) {
                continue;
            }
            $value = \trim((string)$candidate);
            if ($value === '') {
                continue;
            }
            $resolved = $this->decodeProfileIdentityAssetValue($value, $role);
            if ($resolved !== []) {
                return $resolved;
            }
        }

        return [];
    }

    /**
     * @return array{bytes:string,mime_type:string}|array{}
     */
    private function decodeProfileIdentityAssetValue(string $value, string $role): array
    {
        if (\preg_match('#^data:([^;]+);base64,(.+)$#s', $value, $matches) === 1) {
            $mimeType = \strtolower(\trim((string)$matches[1]));
            $bytes = \base64_decode((string)$matches[2], true);
            if (
                \is_string($bytes)
                && AiSiteIdentityAssetTransparencyValidator::isAcceptableIdentityAsset($bytes, $mimeType, $role)
            ) {
                return ['bytes' => $bytes, 'mime_type' => $mimeType];
            }

            return [];
        }

        if (AiSiteIdentityAssetTransparencyValidator::looksLikeSvg($value)) {
            if (AiSiteIdentityAssetTransparencyValidator::isAcceptableIdentityAsset($value, 'image/svg+xml', $role)) {
                return ['bytes' => $value, 'mime_type' => 'image/svg+xml'];
            }

            return [];
        }

        $path = \parse_url($value, \PHP_URL_PATH);
        $path = \is_string($path) && $path !== '' ? $path : $value;
        $path = '/' . \ltrim(\preg_replace('#/+#', '/', \str_replace('\\', '/', $path)) ?? $path, '/');
        if (!\str_starts_with($path, '/pub/media/')) {
            return [];
        }
        $absolutePath = BP . \str_replace('/', \DIRECTORY_SEPARATOR, \ltrim($path, '/'));
        if (!\is_file($absolutePath)) {
            return [];
        }
        $bytes = @\file_get_contents($absolutePath);
        if (!\is_string($bytes) || $bytes === '') {
            return [];
        }
        $mimeType = $this->mimeTypeForIdentityAssetPath($path);
        if (AiSiteIdentityAssetTransparencyValidator::isAcceptableIdentityAsset($bytes, $mimeType, $role)) {
            return ['bytes' => $bytes, 'mime_type' => $mimeType];
        }

        return [];
    }

    /**
     * @param array<string,mixed> $slot
     */
    private function assertIdentityAssetTransparentPng(string $slotId, array $slot, string $bytes, string $mimeType): void
    {
        if (!$this->isTransparentIdentityAssetSlot($slotId, $slot)) {
            return;
        }
        $role = \str_contains(\strtolower($slotId), 'site-title-icon') ? 'icon' : 'logo';
        if (!AiSiteIdentityAssetTransparencyValidator::isAcceptableIdentityAsset($bytes, $mimeType, $role)) {
            throw new \RuntimeException('Identity logo/icon generation must return a transparent PNG or safe transparent SVG asset.');
        }
    }

    /**
     * @param array<string,mixed> $slot
     */
    private function identityAssetFinalUrlNeedsRegeneration(string $slotId, array $slot, string $finalUrl): bool
    {
        if (!$this->isTransparentIdentityAssetSlot($slotId, $slot)) {
            return false;
        }
        $path = \parse_url($finalUrl, \PHP_URL_PATH);
        $path = \is_string($path) && $path !== '' ? $path : $finalUrl;
        $path = '/' . \ltrim(\preg_replace('#/+#', '/', \str_replace('\\', '/', $path)) ?? $path, '/');
        if (!\str_contains($path, '/pub/media/page-build/ai-generated/')) {
            return false;
        }
        $absolutePath = BP . \str_replace('/', \DIRECTORY_SEPARATOR, \ltrim($path, '/'));
        if (!\is_file($absolutePath)) {
            return true;
        }
        $bytes = @\file_get_contents($absolutePath);
        if (!\is_string($bytes) || $bytes === '') {
            return true;
        }

        $role = \str_contains(\strtolower($slotId), 'site-title-icon') ? 'icon' : 'logo';
        return !AiSiteIdentityAssetTransparencyValidator::isAcceptableIdentityAsset($bytes, $this->mimeTypeForIdentityAssetPath($path), $role);
    }

    private function mimeTypeForIdentityAssetPath(string $path): string
    {
        $lowerPath = \strtolower($path);
        if (\str_ends_with($lowerPath, '.svg')) {
            return 'image/svg+xml';
        }
        if (\str_ends_with($lowerPath, '.png')) {
            return 'image/png';
        }

        return '';
    }

    /**
     * @param array<string,mixed> $slot
     */
    private function isTransparentIdentityAssetSlot(string $slotId, array $slot): bool
    {
        $field = \strtolower(\trim((string)($slot['field'] ?? '')));
        $kind = \strtolower(\trim((string)($slot['kind'] ?? '')));
        $slotType = \strtolower(\trim((string)($slot['slot_type'] ?? '')));
        $label = \strtolower(\trim((string)($slot['label'] ?? '')));
        $slotId = \strtolower(\trim($slotId));

        return \str_contains($slotId, 'identity:website-logo')
            || \str_contains($slotId, 'identity:site-title-icon')
            || \in_array($field, ['logo', 'logo.image', 'brand.logo', 'icon', 'favicon', 'site.icon'], true)
            || \in_array($kind, ['website_logo', 'brand_logo', 'site_title_icon', 'favicon'], true)
            || ($slotType === 'logo_icon' && \str_starts_with($slotId, 'identity:') && (\str_contains($label, 'logo') || \str_contains($label, 'icon') || \str_contains($label, 'favicon')));
    }

    private function isPngImageBytes(string $bytes): bool
    {
        return \strncmp($bytes, "\x89PNG\r\n\x1A\n", 8) === 0;
    }

    private function pngAppearsToHaveTransparentBackground(string $bytes): bool
    {
        if (!$this->isPngImageBytes($bytes)) {
            return false;
        }
        if (\function_exists('imagecreatefromstring')) {
            $image = @\imagecreatefromstring($bytes);
            if ($image !== false) {
                $width = \imagesx($image);
                $height = \imagesy($image);
                $points = [
                    [0, 0],
                    [\max(0, $width - 1), 0],
                    [0, \max(0, $height - 1)],
                    [\max(0, $width - 1), \max(0, $height - 1)],
                    [(int)\floor($width / 2), 0],
                    [(int)\floor($width / 2), \max(0, $height - 1)],
                ];
                $transparent = 0;
                foreach ($points as [$x, $y]) {
                    $color = \imagecolorat($image, $x, $y);
                    $alpha = ($color >> 24) & 0x7F;
                    if ($alpha >= 80) {
                        $transparent++;
                    }
                }
                \imagedestroy($image);

                return $transparent >= 4;
            }
        }

        $colorType = \ord($bytes[25] ?? "\0");
        if (\in_array($colorType, [4, 6], true)) {
            return true;
        }

        return \str_contains($bytes, 'tRNS');
    }

    /**
     * @param array<string,mixed> $scope
     */
    private function resolveTargetHandle(array $scope, AiSiteAgentSession $session): string
    {
        $localPreviewHost = $this->resolveLocalPreviewHost($scope);
        if ($localPreviewHost !== '') {
            return $this->sanitizePathSegment($localPreviewHost);
        }

        foreach ([
            $scope['target_domain'] ?? null,
            $scope['selected_domain'] ?? null,
            $scope['website_profile']['target_domain'] ?? null,
            $scope['website_profile']['domain'] ?? null,
            $scope['domain'] ?? null,
            $scope['website_profile']['site_domain'] ?? null,
            $scope['site_domain'] ?? null,
            $scope['website_profile']['public_domain'] ?? null,
            $scope['public_domain'] ?? null,
        ] as $value) {
            $handle = $this->sanitizePathSegmentOrEmpty((string)$value);
            if ($handle !== '') {
                return $handle;
            }
        }

        foreach ([
            $session->getPublicId(),
        ] as $value) {
            $handle = $this->sanitizePathSegmentOrEmpty((string)$value);
            if ($handle !== '') {
                return $handle;
            }
        }

        return $this->sanitizePathSegment($session->getPublicId() ?: 'site');
    }

    /**
     * @param array<string,mixed> $scope
     */
    private function resolveLocalPreviewHost(array $scope): string
    {
        foreach (['preview_full_url', 'visual_preview_url', 'visual_edit_url', 'preview_url'] as $key) {
            $url = \trim((string)($scope[$key] ?? ''));
            if ($url === '') {
                continue;
            }
            $host = \parse_url($url, \PHP_URL_HOST);
            $host = \is_string($host) ? \strtolower(\trim($host)) : '';
            if ($host !== '' && (\str_ends_with($host, '.weline.test') || \str_ends_with($host, '.local.test'))) {
                return $host;
            }
        }

        return '';
    }

    private function isFinalUrlUnderCurrentDomainAssetPath(string $finalUrl, array $scope, AiSiteAgentSession $session): bool
    {
        $path = \parse_url($finalUrl, \PHP_URL_PATH);
        $path = \is_string($path) && $path !== '' ? $path : $finalUrl;
        $path = '/' . \ltrim(\preg_replace('#/+#', '/', \str_replace('\\', '/', $path)) ?? $path, '/');
        $handle = $this->resolveTargetHandle($scope, $session);
        $expectedPrefix = '/pub/media/page-build/ai-generated/' . $handle . '/';

        return \str_starts_with($path, $expectedPrefix);
    }

    private function isGeneratedPageBuildAssetUrl(string $finalUrl): bool
    {
        $path = \parse_url($finalUrl, \PHP_URL_PATH);
        $path = \is_string($path) && $path !== '' ? $path : $finalUrl;
        $path = '/' . \ltrim(\preg_replace('#/+#', '/', \str_replace('\\', '/', $path)) ?? $path, '/');

        return \str_contains($path, '/pub/media/page-build/ai-generated/');
    }

    private function sanitizePathSegment(string $value): string
    {
        $value = \strtolower(\trim($value));
        $value = \preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? '';
        return \trim($value, '-_.') ?: 'asset';
    }

    private function sanitizePathSegmentOrEmpty(string $value): string
    {
        $value = \strtolower(\trim($value));
        $value = \preg_replace('#^https?://#i', '', $value) ?? $value;
        $value = \preg_replace('/[\/?#].*$/', '', $value) ?? $value;
        $value = \preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? '';

        return \trim($value, '-_.');
    }
}
