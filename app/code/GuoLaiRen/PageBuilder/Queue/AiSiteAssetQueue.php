<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Queue;

use GuoLaiRen\PageBuilder\Http\Sse\QueueDbWriter;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSiteAssetManifestService;
use GuoLaiRen\PageBuilder\Service\AiSiteReferenceImageInsightService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Queue\Model\Queue;
use Weline\Queue\QueueInterface;

class AiSiteAssetQueue implements QueueInterface
{
    public function name(): string
    {
        return 'PageBuilder AI image asset generation queue';
    }

    public function tip(): string
    {
        return 'Generate PageBuilder AI site image assets asynchronously and write asset_manifest updates.';
    }

    public function attributes(): array
    {
        return [];
    }

    public function validate(Queue &$queue): bool
    {
        $content = $this->decodeContent($queue);
        $publicId = \trim((string)($content['public_id'] ?? ''));
        $adminId = (int)($content['admin_id'] ?? 0);
        $executionToken = \trim((string)($content['execution_token'] ?? ''));
        $slotId = \trim((string)($content['slot_id'] ?? ''));
        if ($publicId === '' || $adminId <= 0 || $executionToken === '' || $slotId === '') {
            return false;
        }

        /** @var AiSiteAgentSessionService $sessionService */
        $sessionService = ObjectManager::getInstance(AiSiteAgentSessionService::class);
        return $sessionService->loadByPublicId($publicId, $adminId) instanceof AiSiteAgentSession;
    }

    public function execute(Queue &$queue): string
    {
        $content = $this->decodeContent($queue);
        $publicId = \trim((string)($content['public_id'] ?? ''));
        $adminId = (int)($content['admin_id'] ?? 0);
        $executionToken = \trim((string)($content['execution_token'] ?? ''));
        $slotId = \trim((string)($content['slot_id'] ?? ''));
        $mode = \trim((string)($content['mode'] ?? 'generate')) ?: 'generate';
        $queueId = (int)$queue->getId();

        /** @var AiSiteAgentSessionService $sessionService */
        $sessionService = ObjectManager::getInstance(AiSiteAgentSessionService::class);
        /** @var AiSiteScopeCompatibilityService $scopeService */
        $scopeService = ObjectManager::getInstance(AiSiteScopeCompatibilityService::class);
        /** @var AiSiteAssetManifestService $manifestService */
        $manifestService = ObjectManager::getInstance(AiSiteAssetManifestService::class);
        /** @var AiSiteReferenceImageInsightService $referenceImageInsightService */
        $referenceImageInsightService = ObjectManager::getInstance(AiSiteReferenceImageInsightService::class);

        $session = $sessionService->loadByPublicId($publicId, $adminId);
        if (!$session instanceof AiSiteAgentSession) {
            throw new \RuntimeException('AI site session not found for asset generation.');
        }

        $sse = new QueueDbWriter(
            (int)$session->getId(),
            $adminId,
            $queueId,
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'image_asset',
            $executionToken,
            \trim((string)($content['job_key'] ?? '')),
            \trim((string)($content['job_type'] ?? ''))
        );

        try {
            $scope = $scopeService->normalizeScope(
                $sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
            );
            $referenceImageInsights = $referenceImageInsightService->analyze($scope, $this->resolveInsightLocale($scope));
            if ($referenceImageInsights !== []) {
                $scope['reference_image_insights'] = $referenceImageInsights;
                $referenceInsightSignature = $referenceImageInsightService->buildSignature($scope);
                if ($referenceInsightSignature !== '') {
                    $scope['reference_image_insights_signature'] = $referenceInsightSignature;
                }
            }
            $manifest = $manifestService->syncFromBuildPlan($scope);
            if ((int)($scope['allow_placeholder_image_assets'] ?? 0) !== 1) {
                $manifest = $manifestService->discardPlaceholderGeneratedAssets($manifest);
                $scope['asset_manifest'] = $manifest;
                $scope['verified_assets'] = $manifestService->extractVerifiedAssets($manifest);
            }
            $slot = $manifestService->getSlot($manifest, $slotId);
            if ($slot === []) {
                throw new \RuntimeException('Asset slot does not exist: ' . $slotId);
            }
            if ((int)($slot['locked_by_user'] ?? 0) === 1) {
                throw new \RuntimeException('Asset slot is locked by user: ' . $slotId);
            }
            $expectedPlanningSignature = \trim((string)($content['planning_signature'] ?? ''));
            $currentPlanningSignature = \trim((string)($slot['planning_signature'] ?? ''));
            if (
                $expectedPlanningSignature !== ''
                && $currentPlanningSignature !== ''
                && !\hash_equals($expectedPlanningSignature, $currentPlanningSignature)
            ) {
                $staleState = [
                    'asset_manifest' => $manifest,
                    'verified_assets' => $manifestService->extractVerifiedAssets($manifest),
                    'asset_block_cache' => \is_array($scope['asset_block_cache'] ?? null)
                        ? $scope['asset_block_cache']
                        : [],
                    'planning_signature' => $currentPlanningSignature,
                    'stale_planning_signature' => $expectedPlanningSignature,
                ];
                $staleScopePatch = [
                    'asset_manifest' => $manifest,
                    'verified_assets' => $manifestService->extractVerifiedAssets($manifest),
                    'asset_block_cache' => \is_array($scope['asset_block_cache'] ?? null)
                        ? $scope['asset_block_cache']
                        : [],
                ];
                $sessionService->mergeScope((int)$session->getId(), $adminId, \array_merge(
                    $staleScopePatch,
                    $this->buildReferenceImageInsightScopePatch($scope)
                ));
                $sse->sendEvent('asset_generation_skipped', [
                    'slot_id' => $slotId,
                    'message' => 'Image asset generation skipped because the planning contract changed.',
                    'state' => $staleState,
                ]);
                $sse->complete([
                    'success' => true,
                    'slot_id' => $slotId,
                    'stale' => true,
                    'state' => $staleState,
                ]);

                return 'Image asset generation skipped because planning contract changed: ' . $slotId;
            }
            $previousUrl = \trim((string)($slot['final_url'] ?? ''));
            if ($mode !== 'regenerate' && $previousUrl !== '' && $manifestService->isReusableSessionBlockAsset($scope, $slot, $previousUrl)) {
                $scope = $manifestService->rememberGeneratedSlotInScope($scope, $manifest, $slotId);
                $scope = $this->applyIdentityAssetPatchToScope($scope, $slot, $previousUrl);
                $reuseState = \array_merge([
                    'asset_manifest' => $manifest,
                    'verified_assets' => $manifestService->extractVerifiedAssets($manifest),
                    'asset_block_cache' => \is_array($scope['asset_block_cache'] ?? null)
                        ? $scope['asset_block_cache']
                        : [],
                    'asset_image_generation_failures' => \is_array($scope['asset_image_generation_failures'] ?? null)
                        ? $scope['asset_image_generation_failures']
                        : [],
                ], $this->buildIdentityAssetScopePatch($scope));
                $sessionService->mergeScope((int)$session->getId(), $adminId, \array_merge(
                    $reuseState,
                    $this->buildReferenceImageInsightScopePatch($scope)
                ));
                $sse->sendEvent('asset_manifest_updated', ['slot_id' => $slotId, 'asset_manifest' => $manifest, 'state' => $reuseState]);
                $sse->sendEvent('asset_generation_done', [
                    'slot_id' => $slotId,
                    'final_url' => $previousUrl,
                    'asset_manifest' => $manifest,
                    'website_profile' => \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [],
                    'state' => $reuseState,
                    'message' => 'Image asset generation reused from the unchanged planning contract.',
                ]);
                $sse->complete([
                    'success' => true,
                    'slot_id' => $slotId,
                    'final_url' => $previousUrl,
                    'asset_manifest' => $manifest,
                    'verified_assets' => $manifestService->extractVerifiedAssets($manifest),
                    'state' => $reuseState,
                    'reused' => true,
                ] + $this->buildIdentityAssetScopePatch($scope));

                return 'Image asset generation reused: ' . $slotId;
            }
            $prompt = $manifestService->buildPrompt($slot, $scope);
            if ($prompt === '') {
                throw new \RuntimeException('Asset slot prompt brief is empty: ' . $slotId);
            }

            $sse->sendEvent('asset_generation_started', [
                'slot_id' => $slotId,
                'mode' => $mode,
                'message' => 'Image asset generation started.',
            ]);

            $manifest = $manifestService->markGenerating($manifest, $slotId);
            $sessionService->mergeScope((int)$session->getId(), $adminId, \array_merge([
                'asset_manifest' => $manifest,
                'verified_assets' => $manifestService->extractVerifiedAssets($manifest),
            ], $this->buildReferenceImageInsightScopePatch($scope)));
            $sse->sendEvent('asset_manifest_updated', ['slot_id' => $slotId, 'asset_manifest' => $manifest]);

            $sse->sendEvent('asset_generation_progress', [
                'slot_id' => $slotId,
                'message' => 'Calling text-to-image model.',
            ]);
            $result = w_query('ai', 'generateImage', [
                'prompt' => $prompt,
                'scenario_code' => 'pagebuilder_ai_site_assets',
                'params' => [
                    'disable_conversation_history' => true,
                    'disable_conversation_persist' => true,
                    'is_backend' => true,
                    'user_id' => $adminId,
                    'slot_id' => $slotId,
                    'size' => \trim((string)($content['size'] ?? '')) ?: '1024x1024',
                    'response_format' => \trim((string)($content['response_format'] ?? '')),
                    'output_format' => \trim((string)($content['output_format'] ?? '')),
                ],
            ]);
            if (!\is_array($result)) {
                throw new \RuntimeException('Image generation returned invalid result.');
            }

            $image = $this->firstGeneratedImage($result);
            [$bytes, $mimeType] = $this->resolveImageBytes($image);
            if ($bytes === '') {
                throw new \RuntimeException('Image generation returned empty image bytes.');
            }

            $relativePath = $this->resolveTargetRelativePath($content, $scope, $session, $slotId, $bytes, $mimeType);
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
                'model' => (string)($result['model'] ?? ''),
                'revised_prompt' => (string)($image['revised_prompt'] ?? ''),
            ];
            $manifest = $manifestService->recordGenerated($manifest, $slotId, $finalUrl, $variant);
            $scope = $this->applyIdentityAssetPatchToScope($scope, $slot, $finalUrl);
            $scope = $this->clearAssetImageGenerationFailureForSlot($scope, $slotId);
            $imagePatch = $this->applyGeneratedImagePatchToScope($scope, $content, $slot, $previousUrl, $finalUrl);
            $scope = \is_array($imagePatch['scope'] ?? null) ? $imagePatch['scope'] : $scope;
            $imageScopePatch = \is_array($imagePatch['patch'] ?? null) ? $imagePatch['patch'] : [];
            $scope = $manifestService->rememberGeneratedSlotInScope($scope, $manifest, $slotId);
            $successState = \array_merge([
                'asset_manifest' => $manifest,
                'verified_assets' => $manifestService->extractVerifiedAssets($manifest),
                'asset_block_cache' => \is_array($scope['asset_block_cache'] ?? null)
                    ? $scope['asset_block_cache']
                    : [],
                'asset_image_generation_failures' => \is_array($scope['asset_image_generation_failures'] ?? null)
                    ? $scope['asset_image_generation_failures']
                    : [],
            ], $this->buildIdentityAssetScopePatch($scope), $imageScopePatch);
            $sessionService->mergeScope((int)$session->getId(), $adminId, \array_merge([
                'asset_manifest' => $manifest,
                'verified_assets' => $manifestService->extractVerifiedAssets($manifest),
                'asset_block_cache' => \is_array($scope['asset_block_cache'] ?? null)
                    ? $scope['asset_block_cache']
                    : [],
                'asset_image_generation_failures' => \is_array($scope['asset_image_generation_failures'] ?? null)
                    ? $scope['asset_image_generation_failures']
                    : [],
            ], $this->buildIdentityAssetScopePatch($scope), $this->buildReferenceImageInsightScopePatch($scope), $imageScopePatch));

            $sse->sendEvent('asset_manifest_updated', ['slot_id' => $slotId, 'asset_manifest' => $manifest, 'state' => $successState]);
            $sse->sendEvent('asset_generation_done', [
                'slot_id' => $slotId,
                'final_url' => $finalUrl,
                'asset_manifest' => $manifest,
                'website_profile' => \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [],
                'state' => $successState,
                'message' => 'Image asset generation completed.',
            ]);
            $sse->complete([
                'success' => true,
                'slot_id' => $slotId,
                'final_url' => $finalUrl,
                'asset_manifest' => $manifest,
                'verified_assets' => $manifestService->extractVerifiedAssets($manifest),
                'state' => $successState,
            ] + $this->buildIdentityAssetScopePatch($scope));

            return 'Image asset generation completed: ' . $slotId;
        } catch (\Throwable $throwable) {
            $scope = $scopeService->normalizeScope(
                $sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
            );
            $referenceImageInsights = $referenceImageInsightService->analyze($scope, $this->resolveInsightLocale($scope));
            if ($referenceImageInsights !== []) {
                $scope['reference_image_insights'] = $referenceImageInsights;
                $referenceInsightSignature = $referenceImageInsightService->buildSignature($scope);
                if ($referenceInsightSignature !== '') {
                    $scope['reference_image_insights_signature'] = $referenceInsightSignature;
                }
            }
            $manifest = $manifestService->recordError(
                $manifestService->syncFromBuildPlan($scope),
                $slotId,
                $throwable->getMessage()
            );
            $scope = $this->recordAssetImageGenerationFailure($scope, $slotId, $throwable->getMessage());
            $sessionService->mergeScope((int)$session->getId(), $adminId, \array_merge([
                'asset_manifest' => $manifest,
                'verified_assets' => $manifestService->extractVerifiedAssets($manifest),
                'asset_image_generation_failures' => \is_array($scope['asset_image_generation_failures'] ?? null)
                    ? $scope['asset_image_generation_failures']
                    : [],
            ], $this->buildReferenceImageInsightScopePatch($scope)));
            $errorState = [
                'asset_manifest' => $manifest,
                'verified_assets' => $manifestService->extractVerifiedAssets($manifest),
                'asset_image_generation_failures' => \is_array($scope['asset_image_generation_failures'] ?? null)
                    ? $scope['asset_image_generation_failures']
                    : [],
            ] + $this->buildIdentityAssetScopePatch($scope);
            $sse->sendEvent('asset_generation_failed', [
                'slot_id' => $slotId,
                'message' => $throwable->getMessage(),
                'state' => $errorState,
            ]);
            $sse->sendEvent('asset_manifest_updated', ['slot_id' => $slotId, 'asset_manifest' => $manifest, 'state' => $errorState]);
            $sse->complete([
                'success' => false,
                'slot_id' => $slotId,
                'message' => $throwable->getMessage(),
                'state' => $errorState,
            ]);

            return 'Image asset generation failed and was recorded for retry: ' . $slotId . ' - ' . $throwable->getMessage();
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeContent(Queue $queue): array
    {
        $content = \json_decode((string)$queue->getContent(), true);
        return \is_array($content) ? $content : [];
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
            throw new \RuntimeException('Failed to download generated image: ' . ($error !== '' ? $error : ('HTTP ' . $status)));
        }

        $context = \stream_context_create(['http' => ['timeout' => 120, 'follow_location' => 1]]);
        $bytes = @\file_get_contents($url, false, $context);
        if ($bytes === false) {
            throw new \RuntimeException('Failed to download generated image.');
        }

        return $bytes;
    }

    /**
     * @param array<string,mixed> $content
     * @param array<string,mixed> $scope
     */
    private function resolveTargetRelativePath(
        array $content,
        array $scope,
        AiSiteAgentSession $session,
        string $slotId,
        string $bytes,
        string $mimeType
    ): string {
        $extension = $this->extensionForMimeType($mimeType);
        $targetPath = \str_replace('\\', '/', \trim((string)($content['target_path'] ?? '')));
        $targetPath = \ltrim($targetPath, '/');
        if ($targetPath !== '') {
            $targetPath = \preg_replace('/\.[a-z0-9]+$/i', '.' . $extension, $targetPath) ?? $targetPath;
            $targetPath = $this->normalizeMediaRelativePath($targetPath);
            if ($this->isTargetPathUnderCurrentHandle($targetPath, $scope, $session)) {
                return $targetPath;
            }
        }

        $handle = $this->resolveTargetHandle($scope, $session);
        $safeSlotId = $this->safeFileSegment($slotId);
        $hash = \substr(\sha1($slotId . ':' . $bytes), 0, 16);

        return 'pub/media/page-build/ai-generated/' . $handle . '/' . $safeSlotId . '-' . $hash . '.' . $extension;
    }

    private function normalizeMediaRelativePath(string $targetPath): string
    {
        $targetPath = \preg_replace('#/+#', '/', \str_replace('\\', '/', $targetPath)) ?? $targetPath;
        $targetPath = \ltrim($targetPath, '/');
        if (!\str_starts_with($targetPath, 'pub/media/page-build/')) {
            throw new \RuntimeException('Asset target_path must stay under pub/media/page-build/.');
        }

        return $targetPath;
    }

    /**
     * @param array<string,mixed> $scope
     */
    private function resolveTargetHandle(array $scope, AiSiteAgentSession $session): string
    {
        $localPreviewHost = $this->resolveLocalPreviewHost($scope);
        if ($localPreviewHost !== '') {
            return $this->safeFileSegment($localPreviewHost);
        }

        foreach ([
            $scope['target_domain'] ?? null,
            $scope['selected_domain'] ?? null,
            $scope['website_profile']['site_title'] ?? null,
            $scope['site_title'] ?? null,
            $session->getPublicId(),
        ] as $value) {
            $handle = $this->safeFileSegment((string)$value);
            if ($handle !== '') {
                return $handle;
            }
        }

        return 'site';
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

    /**
     * @param array<string,mixed> $scope
     */
    private function isTargetPathUnderCurrentHandle(string $targetPath, array $scope, AiSiteAgentSession $session): bool
    {
        $targetPath = '/' . \ltrim(\preg_replace('#/+#', '/', \str_replace('\\', '/', $targetPath)) ?? $targetPath, '/');
        $handle = $this->resolveTargetHandle($scope, $session);
        if ($handle === '') {
            return false;
        }

        return \str_starts_with($targetPath, '/pub/media/page-build/ai-generated/' . $handle . '/');
    }

    private function safeFileSegment(string $value): string
    {
        $value = \strtolower(\trim($value));
        $value = \preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? '';
        return \trim($value, '-_.');
    }

    private function extensionForMimeType(string $mimeType): string
    {
        $mimeType = \strtolower(\trim($mimeType));
        return match ($mimeType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $content
     * @param array<string,mixed> $slot
     * @return array{scope:array<string,mixed>,patch:array<string,mixed>,changed:bool}
     */
    private function applyGeneratedImagePatchToScope(
        array $scope,
        array $content,
        array $slot,
        string $previousUrl,
        string $finalUrl
    ): array {
        $pageType = \trim((string)($content['page_type'] ?? $slot['page_type'] ?? ''));
        $finalUrl = \trim($finalUrl);
        if ($pageType === '' || $finalUrl === '') {
            return ['scope' => $scope, 'patch' => [], 'changed' => false];
        }

        $candidates = $this->buildImageReplacementCandidates($content, $slot, $previousUrl);
        if ($candidates === []) {
            return ['scope' => $scope, 'patch' => [], 'changed' => false];
        }

        $targetBlockId = \trim((string)($content['block_id'] ?? ''));
        $targetComponentCode = \trim((string)($content['component_code'] ?? ''));
        $updatedAt = \date('Y-m-d H:i:s');
        $changed = false;
        $patchedBlocks = [];

        $virtualPages = \is_array($scope['virtual_pages_by_type'] ?? null) ? $scope['virtual_pages_by_type'] : [];
        $virtualPage = \is_array($virtualPages[$pageType] ?? null) ? $virtualPages[$pageType] : [];
        if (\is_array($virtualPage['blocks'] ?? null)) {
            [$blocks, $blockChanged] = $this->patchBlocksImageUrls(
                \array_values($virtualPage['blocks']),
                $candidates,
                $finalUrl,
                $targetBlockId,
                $targetComponentCode
            );
            if ($blockChanged) {
                $virtualPage['blocks'] = \array_values($blocks);
                $virtualPage['updated_at'] = $updatedAt;
                $virtualPages[$pageType] = $virtualPage;
                $scope['virtual_pages_by_type'] = $virtualPages;
                $changed = true;
                $patchedBlocks = \array_values($blocks);
            }
        }

        if (!$changed) {
            return ['scope' => $scope, 'patch' => [], 'changed' => false];
        }

        $patch = [
            'preview_page_type' => $pageType,
            'asset_image_patch' => [
                'slot_id' => \trim((string)($slot['slot_id'] ?? $content['slot_id'] ?? '')),
                'page_type' => $pageType,
                'block_id' => $targetBlockId,
                'component_code' => $targetComponentCode,
                'previous_url' => $previousUrl,
                'final_url' => $finalUrl,
                'updated_at' => $updatedAt,
            ],
        ];
        if (\is_array($scope['virtual_pages_by_type'] ?? null)) {
            $patch['virtual_pages_by_type'] = $scope['virtual_pages_by_type'];
        }

        return ['scope' => $scope, 'patch' => $patch, 'changed' => true];
    }

    /**
     * @param array<string,mixed> $content
     * @param array<string,mixed> $slot
     * @return list<string>
     */
    private function buildImageReplacementCandidates(array $content, array $slot, string $previousUrl): array
    {
        $raw = [
            $content['current_url'] ?? '',
            $content['resolved_url'] ?? '',
            $previousUrl,
            $slot['final_url'] ?? '',
            $slot['url'] ?? '',
        ];
        foreach (\is_array($slot['variants'] ?? null) ? $slot['variants'] : [] as $variant) {
            if (!\is_array($variant)) {
                continue;
            }
            $raw[] = $variant['url'] ?? '';
            $raw[] = $variant['path'] ?? '';
        }

        $expanded = [];
        foreach ($raw as $value) {
            foreach ($this->expandImageUrlCandidate((string)$value) as $candidate) {
                $expanded[] = $candidate;
            }
        }
        $expanded = \array_values(\array_unique(\array_filter(
            \array_map(static fn(string $value): string => \trim($value), $expanded),
            static fn(string $value): bool => $value !== ''
        )));
        \usort($expanded, static fn(string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return $expanded;
    }

    /**
     * @return list<string>
     */
    private function expandImageUrlCandidate(string $value): array
    {
        $value = \trim(\html_entity_decode($value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
        if ($value === '') {
            return [];
        }

        $candidates = [$value];
        $decoded = \rawurldecode($value);
        if ($decoded !== $value) {
            $candidates[] = $decoded;
        }

        $path = \parse_url($value, \PHP_URL_PATH);
        if (\is_string($path) && \trim($path) !== '') {
            $candidates[] = $path;
        }

        foreach ($candidates as $candidate) {
            $candidate = \trim((string)$candidate);
            if ($candidate === '') {
                continue;
            }
            if (\str_starts_with($candidate, '/pub/media/')) {
                $candidates[] = \ltrim($candidate, '/');
            } elseif (\str_starts_with($candidate, 'pub/media/')) {
                $candidates[] = '/' . $candidate;
            }
        }

        return \array_values(\array_unique($candidates));
    }

    /**
     * @param list<mixed> $blocks
     * @param list<string> $candidates
     * @return array{0:list<mixed>,1:bool}
     */
    private function patchBlocksImageUrls(
        array $blocks,
        array $candidates,
        string $finalUrl,
        string $targetBlockId,
        string $targetComponentCode
    ): array {
        $changed = false;
        $hasTarget = $targetBlockId !== '' || $targetComponentCode !== '';
        foreach ($blocks as $index => $block) {
            if (!\is_array($block)) {
                continue;
            }
            if ($hasTarget && !$this->blockMatchesTarget($block, $targetBlockId, $targetComponentCode)) {
                continue;
            }
            if (!$this->mixedContainsAnyCandidate($block, $candidates)) {
                continue;
            }
            $blockChanged = false;
            $nextBlock = $this->replaceCandidatesInMixed($block, $candidates, $finalUrl, $blockChanged);
            if ($blockChanged && \is_array($nextBlock)) {
                $blocks[$index] = $nextBlock;
                $changed = true;
            }
        }

        return [\array_values($blocks), $changed];
    }

    /**
     * @param array<string,mixed> $block
     */
    private function blockMatchesTarget(array $block, string $targetBlockId, string $targetComponentCode): bool
    {
        $needles = [];
        foreach ([$targetBlockId, $targetComponentCode] as $target) {
            foreach ($this->normalizeLookupCandidates($target) as $candidate) {
                $needles[$candidate] = true;
            }
        }
        if ($needles === []) {
            return true;
        }

        foreach ($this->buildBlockLookupValues($block) as $value) {
            foreach ($this->normalizeLookupCandidates($value) as $candidate) {
                if (isset($needles[$candidate])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $block
     * @return list<string>
     */
    private function buildBlockLookupValues(array $block): array
    {
        $values = [];
        foreach ([$block, $block['config'] ?? [], $block['metadata'] ?? [], $block['meta'] ?? []] as $payload) {
            if (!\is_array($payload)) {
                continue;
            }
            foreach (['block_id', 'component_code', 'section_code', 'component', 'code', 'block_code', 'block_key', 'task_key', '_pb_server_component_code'] as $key) {
                if (isset($payload[$key]) && (\is_scalar($payload[$key]) || (\is_object($payload[$key]) && \method_exists($payload[$key], '__toString')))) {
                    $values[] = (string)$payload[$key];
                }
            }
        }

        return \array_values(\array_unique(\array_filter(
            \array_map(static fn(string $value): string => \trim($value), $values),
            static fn(string $value): bool => $value !== ''
        )));
    }

    /**
     * @return list<string>
     */
    private function normalizeLookupCandidates(string $value): array
    {
        $value = \trim($value);
        if ($value === '') {
            return [];
        }
        $candidates = [
            $this->normalizeLookupKey($value),
            $this->normalizeLookupKey(\str_replace(['content/', '/'], ['', '-'], $value)),
            $this->normalizeLookupKey('content/' . $value),
            $this->normalizeLookupKey(\str_replace(['_', '/'], ['-', '-'], $value)),
        ];

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
     * @param list<string> $candidates
     */
    private function mixedContainsAnyCandidate(mixed $value, array $candidates, int $depth = 0): bool
    {
        if ($depth > 10) {
            return false;
        }
        if (\is_string($value)) {
            foreach ($candidates as $candidate) {
                if ($candidate !== '' && \str_contains($value, $candidate)) {
                    return true;
                }
            }
            return false;
        }
        if (\is_array($value)) {
            foreach ($value as $child) {
                if ($this->mixedContainsAnyCandidate($child, $candidates, $depth + 1)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<string> $candidates
     */
    private function replaceCandidatesInMixed(mixed $value, array $candidates, string $finalUrl, bool &$changed, int $depth = 0): mixed
    {
        if ($depth > 10) {
            return $value;
        }
        if (\is_string($value)) {
            $next = $value;
            foreach ($candidates as $candidate) {
                if ($candidate === '' || $candidate === $finalUrl || !\str_contains($next, $candidate)) {
                    continue;
                }
                $next = \str_replace($candidate, $finalUrl, $next);
            }
            if ($next !== $value) {
                $changed = true;
            }

            return $next;
        }
        if (\is_array($value)) {
            foreach ($value as $key => $child) {
                $value[$key] = $this->replaceCandidatesInMixed($child, $candidates, $finalUrl, $changed, $depth + 1);
            }
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function buildReferenceImageInsightScopePatch(array $scope): array
    {
        $patch = [];
        if (\is_array($scope['reference_image_insights'] ?? null) && $scope['reference_image_insights'] !== []) {
            $patch['reference_image_insights'] = $scope['reference_image_insights'];
        }
        $signature = \trim((string)($scope['reference_image_insights_signature'] ?? ''));
        if ($signature !== '') {
            $patch['reference_image_insights_signature'] = $signature;
        }

        return $patch;
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function buildIdentityAssetScopePatch(array $scope): array
    {
        $patch = [];
        if (\is_array($scope['website_profile'] ?? null) && $scope['website_profile'] !== []) {
            $patch['website_profile'] = $scope['website_profile'];
        }
        foreach (['logo', 'icon', 'favicon'] as $key) {
            $value = \trim((string)($scope[$key] ?? ''));
            if ($value !== '') {
                $patch[$key] = $value;
            }
        }

        return $patch;
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

        $websiteProfile = \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [];
        if ($role === 'logo') {
            $scope['logo'] = $finalUrl;
            $websiteProfile['logo'] = $finalUrl;
        } elseif ($role === 'icon') {
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
        if (\is_array($scope['build_workbench']['contracts']['render_data']['payload'] ?? null)) {
            $payload = $this->applyIdentityAssetToRenderPayload($scope['build_workbench']['contracts']['render_data']['payload'], $role, $finalUrl);
            $scope['build_workbench']['contracts']['render_data']['payload'] = $payload;
        }

        return $scope;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function applyIdentityAssetToRenderPayload(array $payload, string $role, string $finalUrl): array
    {
        if ($role === 'logo') {
            if (\is_array($payload['shared_components']['header']['default_config'] ?? null)) {
                $payload['shared_components']['header']['default_config']['logo']['url'] = $finalUrl;
                $payload['shared_components']['header']['default_config']['logo']['image'] = $finalUrl;
                $payload['shared_components']['header']['default_config']['logo.url'] = $finalUrl;
                $payload['shared_components']['header']['default_config']['logo.image'] = $finalUrl;
                $payload['shared_components']['header']['default_config']['identity']['shared_logo_asset'] = $finalUrl;
                $payload['shared_components']['header']['default_config']['identity.shared_logo_asset'] = $finalUrl;
            }
            if (\is_array($payload['shared_components']['footer']['default_config'] ?? null)) {
                $payload['shared_components']['footer']['default_config']['identity']['shared_logo_asset'] = $finalUrl;
                $payload['shared_components']['footer']['default_config']['identity.shared_logo_asset'] = $finalUrl;
                $payload['shared_components']['footer']['default_config']['brand']['logo'] = $finalUrl;
                $payload['shared_components']['footer']['default_config']['brand.logo'] = $finalUrl;
            }
            foreach (\is_array($payload['page_type_layouts'] ?? null) ? $payload['page_type_layouts'] : [] as $pageType => $layout) {
                if (!\is_string($pageType) || !\is_array($layout)) {
                    continue;
                }
                if (\is_array($layout['header']['config'] ?? null)) {
                    $layout['header']['config']['logo']['url'] = $finalUrl;
                    $layout['header']['config']['logo']['image'] = $finalUrl;
                    $layout['header']['config']['logo.url'] = $finalUrl;
                    $layout['header']['config']['logo.image'] = $finalUrl;
                    $layout['header']['config']['identity']['shared_logo_asset'] = $finalUrl;
                    $layout['header']['config']['identity.shared_logo_asset'] = $finalUrl;
                }
                if (\is_array($layout['footer']['config'] ?? null)) {
                    $layout['footer']['config']['identity']['shared_logo_asset'] = $finalUrl;
                    $layout['footer']['config']['identity.shared_logo_asset'] = $finalUrl;
                    $layout['footer']['config']['brand']['logo'] = $finalUrl;
                    $layout['footer']['config']['brand.logo'] = $finalUrl;
                }
                $payload['page_type_layouts'][$pageType] = $layout;
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
            if (\is_array($payload['shared_components']['header']['default_config'] ?? null)) {
                $payload['shared_components']['header']['default_config']['identity']['shared_icon_asset'] = $finalUrl;
                $payload['shared_components']['header']['default_config']['identity.shared_icon_asset'] = $finalUrl;
            }
            foreach (\is_array($payload['page_type_layouts'] ?? null) ? $payload['page_type_layouts'] : [] as $pageType => $layout) {
                if (!\is_string($pageType) || !\is_array($layout)) {
                    continue;
                }
                if (\is_array($layout['header']['config'] ?? null)) {
                    $layout['header']['config']['identity']['shared_icon_asset'] = $finalUrl;
                    $layout['header']['config']['identity.shared_icon_asset'] = $finalUrl;
                }
                $payload['page_type_layouts'][$pageType] = $layout;
            }
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $slot
     */
    private function resolveIdentityAssetRole(array $slot): string
    {
        $field = \strtolower(\trim((string)($slot['field'] ?? '')));
        if (\in_array($field, ['logo', 'logo.image', 'brand.logo'], true)) {
            return 'logo';
        }
        if (\in_array($field, ['icon', 'favicon', 'site.icon'], true)) {
            return 'icon';
        }

        $haystack = \strtolower(\implode(' ', [
            (string)($slot['slot_id'] ?? ''),
            (string)($slot['label'] ?? ''),
            (string)($slot['kind'] ?? ''),
        ]));
        if (\str_contains($haystack, 'logo')) {
            return 'logo';
        }
        if (\str_contains($haystack, 'icon') || \str_contains($haystack, 'favicon')) {
            return 'icon';
        }

        return '';
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
        $scope['asset_image_generation_failures'] = \array_values(\array_filter($trail, static function (mixed $row) use ($slotId): bool {
            if (!\is_array($row)) {
                return true;
            }
            return \trim((string)($row['slot_id'] ?? $row['slotId'] ?? '')) !== $slotId;
        }));

        return $scope;
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function recordAssetImageGenerationFailure(array $scope, string $slotId, string $message): array
    {
        $slotId = \trim($slotId);
        if ($slotId === '') {
            return $scope;
        }
        $scope = $this->clearAssetImageGenerationFailureForSlot($scope, $slotId);
        $trail = \is_array($scope['asset_image_generation_failures'] ?? null)
            ? $scope['asset_image_generation_failures']
            : [];
        $trail[] = [
            'slot_id' => $slotId,
            'message' => $message,
            'updated_at' => \date('Y-m-d H:i:s'),
        ];
        $scope['asset_image_generation_failures'] = $trail;

        return $scope;
    }
}
