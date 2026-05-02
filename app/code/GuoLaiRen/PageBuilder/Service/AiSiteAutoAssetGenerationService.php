<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;

class AiSiteAutoAssetGenerationService
{
    private const DEFAULT_LIMIT = 4;

    public function __construct(
        private readonly AiSiteAssetManifestService $manifestService,
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
        $manifest = $this->manifestService->syncFromTaskPlan($scope);
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
                if (!\is_array($result)) {
                    throw new \RuntimeException('Image generation returned invalid result.');
                }

                $image = $this->firstGeneratedImage($result);
                [$bytes, $mimeType] = $this->resolveImageBytes($image);
                if ($bytes === '') {
                    throw new \RuntimeException('Image generation returned empty image bytes.');
                }

                $relativePath = $this->buildTargetPath($scope, $session, $slotId);
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
    private function buildTargetPath(array $scope, AiSiteAgentSession $session, string $slotId): string
    {
        $handle = $this->resolveTargetHandle($scope, $session);
        $safeSlot = $this->sanitizePathSegment($slotId);
        $hash = \substr(\sha1($slotId . ':' . $session->getPublicId()), 0, 12);

        return 'pub/media/page-build/' . $handle . '/ai-generated/' . $safeSlot . '-' . $hash . '.webp';
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
