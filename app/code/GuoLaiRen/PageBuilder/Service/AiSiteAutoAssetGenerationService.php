<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;

class AiSiteAutoAssetGenerationService
{
    private const DEFAULT_LIMIT = 4;

    public function __construct(
        private readonly AiSiteAssetManifestService $manifestService,
        private readonly ?AiSiteReferenceImageInsightService $referenceImageInsightService = null,
        private readonly mixed $imageGenerator = null,
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
        $manifest = $this->manifestService->syncFromTaskPlan($scope);
        if (!$this->shouldUsePlaceholderFallback($scope)) {
            $placeholderUrls = $this->manifestService->extractPlaceholderAssetUrls($manifest);
            $manifest = $this->manifestService->discardPlaceholderGeneratedAssets($manifest);
            if ($placeholderUrls !== []) {
                $scope = $this->clearPlaceholderIdentityAssetsFromScope($scope, $placeholderUrls);
            }
        }
        $scope['asset_manifest'] = $manifest;
        $scope['verified_assets'] = $this->manifestService->extractVerifiedAssets($manifest);

        $generatedSlots = [];
        $failedSlots = [];
        foreach ($this->pickPendingSlots($manifest, $limit) as $slot) {
            $slotId = (string)($slot['slot_id'] ?? '');
            if ($slotId === '') {
                continue;
            }

            try {
                $prompt = $this->manifestService->buildPrompt($slot, $scope);
                if ($prompt === '') {
                    throw new \RuntimeException('Asset slot prompt brief is empty: ' . $slotId);
                }

                $manifest = $this->manifestService->markGenerating($manifest, $slotId);
                if ($this->shouldUsePlaceholderFallback($scope)) {
                    [$finalUrl, $variant] = $this->writePlaceholderAsset($scope, $session, $slotId, $slot, $prompt);
                    $manifest = $this->manifestService->recordGenerated($manifest, $slotId, $finalUrl, $variant);
                    $scope['asset_manifest'] = $manifest;
                    $scope['verified_assets'] = $this->manifestService->extractVerifiedAssets($manifest);
                    $scope = $this->applyIdentityAssetPatchToScope($scope, $slot, $finalUrl);
                    $generatedSlots[] = $slotId;
                    continue;
                }

                $result = $this->generateImage($prompt, $adminId, $slotId);

                $image = $this->firstGeneratedImage($result);
                [$bytes, $mimeType] = $this->resolveImageBytes($image);
                if ($bytes === '') {
                    throw new \RuntimeException('Image generation returned empty image bytes.');
                }

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
                    'mode' => 'auto_build',
                    'model' => (string)($result['model'] ?? ''),
                    'revised_prompt' => (string)($image['revised_prompt'] ?? ''),
                ];
                $manifest = $this->manifestService->recordGenerated($manifest, $slotId, $finalUrl, $variant);
                $scope['asset_manifest'] = $manifest;
                $scope['verified_assets'] = $this->manifestService->extractVerifiedAssets($manifest);
                $scope = $this->applyIdentityAssetPatchToScope($scope, $slot, $finalUrl);
                $generatedSlots[] = $slotId;
            } catch (\Throwable $throwable) {
                $manifest = $this->manifestService->recordError($manifest, $slotId, $throwable->getMessage());
                $scope['asset_manifest'] = $manifest;
                $scope['verified_assets'] = $this->manifestService->extractVerifiedAssets($manifest);
                $failedSlots[] = [
                    'slot_id' => $slotId,
                    'message' => $throwable->getMessage(),
                ];
            }
        }

        return [
            'scope' => $scope,
            'generated_slots' => $generatedSlots,
            'failed_slots' => $failedSlots,
        ];
    }

    /**
     * Placeholder image files are an explicit operator fallback only. Normal
     * build flow must call the text-to-image model or expose a visible failure.
     *
     * @param array<string,mixed> $scope
     */
    private function shouldUsePlaceholderFallback(array $scope): bool
    {
        return (int)($scope['allow_placeholder_image_assets'] ?? 0) === 1;
    }

    /**
     * @return array<string,mixed>
     */
    private function generateImage(string $prompt, int $adminId, string $slotId): array
    {
        if ($this->imageGenerator !== null) {
            if (!\is_callable($this->imageGenerator)) {
                throw new \RuntimeException('Image generator callback is not callable.');
            }
            $result = ($this->imageGenerator)($prompt, $adminId, $slotId);
        } else {
            $result = \w_query('ai', 'generateImage', [
                'prompt' => $prompt,
                'scenario_code' => 'pagebuilder_ai_site_assets',
                'params' => [
                    'disable_conversation_history' => true,
                    'disable_conversation_persist' => true,
                    'is_backend' => true,
                    'user_id' => $adminId,
                    'slot_id' => $slotId,
                    'size' => '1024x1024',
                ],
            ]);
        }

        if (!\is_array($result)) {
            throw new \RuntimeException('Image generation returned invalid result.');
        }

        return $result;
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

    /**
     * @param array<string,mixed> $scope
     * @param list<string> $placeholderUrls
     * @return array<string,mixed>
     */
    private function clearPlaceholderIdentityAssetsFromScope(array $scope, array $placeholderUrls): array
    {
        $urlMap = \array_fill_keys($placeholderUrls, true);
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
     * @param array<string,mixed> $slot
     * @return array{0:string,1:array<string,mixed>}
     */
    private function writePlaceholderAsset(array $scope, AiSiteAgentSession $session, string $slotId, array $slot, string $prompt): array
    {
        $relativePath = $this->buildPlaceholderTargetPath($scope, $session, $slotId);
        $absolutePath = BP . \str_replace('/', \DIRECTORY_SEPARATOR, $relativePath);
        $directory = \dirname($absolutePath);
        if (!\is_dir($directory) && !\mkdir($directory, 0755, true) && !\is_dir($directory)) {
            throw new \RuntimeException('Failed to create placeholder image asset directory: ' . $directory);
        }
        $label = \trim((string)($slot['label'] ?? $slot['slot_type'] ?? $slotId));
        $brief = \trim((string)($slot['brief'] ?? $slot['prompt_brief'] ?? $prompt));
        $svg = $this->buildPlaceholderSvg($label !== '' ? $label : $slotId, $brief);
        if (\file_put_contents($absolutePath, $svg) === false) {
            throw new \RuntimeException('Failed to write placeholder image asset file: ' . $absolutePath);
        }

        $finalUrl = '/' . \str_replace('\\', '/', $relativePath);
        return [$finalUrl, [
            'url' => $finalUrl,
            'mime_type' => 'image/svg+xml',
            'path' => $relativePath,
            'mode' => 'placeholder',
            'model' => 'placeholder',
            'revised_prompt' => $prompt,
            'placeholder' => 1,
        ]];
    }

    private function buildPlaceholderSvg(string $label, string $brief): string
    {
        $label = \htmlspecialchars($this->excerpt($label, 48), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $brief = \htmlspecialchars($this->excerpt($brief, 110), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800" viewBox="0 0 1200 800">'
            . '<defs><linearGradient id="g" x1="0" x2="1" y1="0" y2="1"><stop stop-color="#12233f"/><stop offset="1" stop-color="#22c7a9"/></linearGradient></defs>'
            . '<rect width="1200" height="800" fill="url(#g)"/>'
            . '<circle cx="1020" cy="120" r="180" fill="#ffffff" opacity=".13"/>'
            . '<circle cx="180" cy="700" r="240" fill="#000000" opacity=".16"/>'
            . '<rect x="96" y="104" width="1008" height="592" rx="48" fill="#ffffff" opacity=".10" stroke="#ffffff" stroke-opacity=".32"/>'
            . '<text x="140" y="310" fill="#ffffff" font-family="Arial, sans-serif" font-size="56" font-weight="700">Image Placeholder</text>'
            . '<text x="140" y="390" fill="#dffaf5" font-family="Arial, sans-serif" font-size="40" font-weight="600">' . $label . '</text>'
            . '<text x="140" y="466" fill="#ffffff" fill-opacity=".82" font-family="Arial, sans-serif" font-size="28">' . $brief . '</text>'
            . '<text x="140" y="612" fill="#ffffff" fill-opacity=".62" font-family="Arial, sans-serif" font-size="24">Text-to-image is not connected yet. This placeholder keeps site build resumable.</text>'
            . '</svg>';
    }

    private function excerpt(string $value, int $limit): string
    {
        $value = \trim(\preg_replace('/\s+/u', ' ', $value) ?? $value);
        if (\mb_strlen($value, 'UTF-8') <= $limit) {
            return $value;
        }

        return \mb_substr($value, 0, \max(0, $limit - 3), 'UTF-8') . '...';
    }

    /**
     * @param array<string,mixed> $manifest
     * @return list<array<string,mixed>>
     */
    private function pickPendingSlots(array $manifest, int $limit): array
    {
        $slots = \array_values(\array_filter(
            \is_array($manifest['slots'] ?? null) ? $manifest['slots'] : [],
            static function ($slot): bool {
                if (!\is_array($slot)) {
                    return false;
                }
                if ((int)($slot['locked_by_user'] ?? 0) === 1) {
                    return false;
                }
                if (\trim((string)($slot['final_url'] ?? '')) !== '') {
                    return false;
                }
                return true;
            }
        ));

        \usort($slots, function (array $left, array $right): int {
            $priority = [
                'hero_image' => 10,
                'logo_icon' => 20,
                'trust_brand_image' => 30,
                'section_image' => 40,
            ];
            $leftPriority = $priority[(string)($left['slot_type'] ?? '')] ?? 999;
            $rightPriority = $priority[(string)($right['slot_type'] ?? '')] ?? 999;
            if ($leftPriority !== $rightPriority) {
                return $leftPriority <=> $rightPriority;
            }
            $leftPage = (string)($left['page_type'] ?? '');
            $rightPage = (string)($right['page_type'] ?? '');
            if ($leftPage === 'home' && $rightPage !== 'home') {
                return -1;
            }
            if ($rightPage === 'home' && $leftPage !== 'home') {
                return 1;
            }
            return \strcmp((string)($left['slot_id'] ?? ''), (string)($right['slot_id'] ?? ''));
        });

        return \array_slice($slots, 0, \max(0, $limit));
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

        return 'pub/media/page-build/' . $handle . '/ai-generated/' . $safeSlot . '-' . $hash . '.' . $extension;
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
     */
    private function buildPlaceholderTargetPath(array $scope, AiSiteAgentSession $session, string $slotId): string
    {
        $handle = $this->resolveTargetHandle($scope, $session);
        $safeSlot = $this->sanitizePathSegment($slotId);
        $hash = \substr(\sha1('placeholder:' . $slotId . ':' . $session->getPublicId()), 0, 12);

        return 'pub/media/page-build/' . $handle . '/ai-generated/' . $safeSlot . '-' . $hash . '.svg';
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
            $handle = $this->sanitizePathSegment((string)$value);
            if ($handle !== '') {
                return $handle;
            }
        }

        return 'site';
    }

    private function sanitizePathSegment(string $value): string
    {
        $value = \strtolower(\trim($value));
        $value = \preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? '';
        return \trim($value, '-_.') ?: 'asset';
    }
}
