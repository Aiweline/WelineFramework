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
            $manifest = $manifestService->syncFromTaskPlan($scope);
            $slot = $manifestService->getSlot($manifest, $slotId);
            if ($slot === []) {
                throw new \RuntimeException('Asset slot does not exist: ' . $slotId);
            }
            if ((int)($slot['locked_by_user'] ?? 0) === 1) {
                throw new \RuntimeException('Asset slot is locked by user: ' . $slotId);
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
            $successState = [
                'asset_manifest' => $manifest,
                'verified_assets' => $manifestService->extractVerifiedAssets($manifest),
            ] + $this->buildIdentityAssetScopePatch($scope);
            $sessionService->mergeScope((int)$session->getId(), $adminId, \array_merge([
                'asset_manifest' => $manifest,
                'verified_assets' => $manifestService->extractVerifiedAssets($manifest),
            ], $this->buildIdentityAssetScopePatch($scope), $this->buildReferenceImageInsightScopePatch($scope)));

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
                $manifestService->syncFromTaskPlan($scope),
                $slotId,
                $throwable->getMessage()
            );
            $sessionService->mergeScope((int)$session->getId(), $adminId, \array_merge([
                'asset_manifest' => $manifest,
                'verified_assets' => $manifestService->extractVerifiedAssets($manifest),
            ], $this->buildReferenceImageInsightScopePatch($scope)));
            $errorState = [
                'asset_manifest' => $manifest,
                'verified_assets' => $manifestService->extractVerifiedAssets($manifest),
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
            return $this->normalizeMediaRelativePath($targetPath);
        }

        $handle = $this->resolveTargetHandle($scope, $session);
        $safeSlotId = $this->safeFileSegment($slotId);
        $hash = \substr(\sha1($slotId . ':' . $bytes), 0, 16);

        return 'pub/media/page-build/' . $handle . '/ai-generated/' . $safeSlotId . '-' . $hash . '.' . $extension;
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

        return $scope;
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
}
