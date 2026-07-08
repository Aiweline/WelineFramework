<?php

declare(strict_types=1);

namespace Weline\MediaManager\Service;

use Weline\Ai\Service\AiService;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;

class AiDrawService
{
    private const SCENARIO_CODE = 'media_manager_ai_draw';
    private const BATCH_MAX = 8;

    public function __construct(
        private readonly MediaStorageService $mediaStorage,
        private readonly AiDrawSessionStore $sessionStore,
        private readonly ?AiService $aiService = null,
        private readonly ?Url $url = null,
    ) {
    }

    /**
     * @param array<string,mixed> $input
     */
    public function streamGenerate(SseWriter $sse, int $adminId, array $input): void
    {
        $this->sessionStore->purgeExpired();
        $sessionId = \trim((string)($input['session_id'] ?? ''));
        if ($sessionId === '') {
            $sessionId = $this->sessionStore->createSessionId();
        }
        $this->sessionStore->ensureSession($sessionId, $adminId);

        $mode = \strtolower(\trim((string)($input['mode'] ?? 'text2image')));
        if ($mode === '') {
            $mode = 'text2image';
        }
        $target = \trim((string)($input['target'] ?? ''));
        $prompt = \trim((string)($input['prompt'] ?? ''));
        $prompts = $this->normalizePromptList($input['prompts'] ?? []);
        $batchCount = \max(1, \min(self::BATCH_MAX, (int)($input['batch_count'] ?? 1)));
        $jobs = $this->buildJobs($mode, $prompt, $prompts, $batchCount);
        if ($jobs === []) {
            throw new \InvalidArgumentException(__('请输入提示词'));
        }

        $configStatus = $this->getConfigStatus();
        $sse->setHeartbeatInterval(15);
        if (\function_exists('set_time_limit')) {
            @\set_time_limit(0);
        }
        $sse->start();
        $sse->sendEvent('start', [
            'mode' => $mode,
            'session_id' => $sessionId,
            'target' => $target,
            'batch_total' => \count($jobs),
            'scenario_code' => self::SCENARIO_CODE,
            'mock' => (bool)($configStatus['mock'] ?? false),
            'ready' => (bool)($configStatus['ready'] ?? false),
            'model' => (string)($configStatus['model'] ?? ''),
            'message' => (string)($configStatus['message'] ?? ''),
        ]);

        $sourceFileHash = \trim((string)($input['source_file_hash'] ?? ''));
        $parentGenerationId = \trim((string)($input['parent_generation_id'] ?? ''));
        $generationIds = [];
        $failed = 0;

        foreach ($jobs as $index => $jobPrompt) {
            $batchIndex = $index + 1;
            $batchTotal = \count($jobs);
            try {
                $sse->sendEvent('progress', [
                    'stage' => 'validating',
                    'message' => __('正在准备第 %{1}/%{2} 张…', [$batchIndex, $batchTotal]),
                    'batch_index' => $batchIndex,
                    'batch_total' => $batchTotal,
                ]);

                $params = $this->buildGenerationParams($input, $mode, $sourceFileHash, $parentGenerationId, $sessionId, $adminId, $batchIndex, $batchTotal);
                $params['mode'] = $mode === 'batch' ? ($sourceFileHash !== '' || $parentGenerationId !== '' ? 'image2image' : 'text2image') : $mode;

                $sse->sendEvent('progress', [
                    'stage' => 'generating',
                    'message' => __('正在生成第 %{1}/%{2} 张…', [$batchIndex, $batchTotal]),
                    'batch_index' => $batchIndex,
                    'batch_total' => $batchTotal,
                ]);
                $sse->sendHeartbeat();

                $result = $this->generateImageBytesWithSseKeepalive($sse, $jobPrompt, $params, $adminId);
                $generationId = $this->sessionStore->createGenerationId();
                $meta = [
                    'mode' => (string)$params['mode'],
                    'prompt' => $jobPrompt,
                    'mime_type' => $result['mime_type'],
                    'source_file_hash' => $sourceFileHash,
                    'target' => $target,
                    'batch_index' => $batchIndex,
                    'batch_total' => $batchTotal,
                    'suggested_filename' => $this->suggestFilename(
                        $result['mime_type'],
                        $generationId,
                        $batchTotal > 1 ? $batchIndex : 0,
                        $jobPrompt
                    ),
                ];
                $previewToken = $this->sessionStore->storeGeneration($sessionId, $adminId, $generationId, $result['bytes'], $meta);
                $this->sessionStore->appendTurn($sessionId, $adminId, $generationId, $jobPrompt);
                $generationIds[] = $generationId;
                $parentGenerationId = $generationId;

                $sse->sendEvent('preview', [
                    'session_id' => $sessionId,
                    'generation_id' => $generationId,
                    'batch_index' => $batchIndex,
                    'batch_total' => $batchTotal,
                    'mime_type' => $result['mime_type'],
                    'preview_token' => $previewToken,
                    'preview_url' => $this->buildPreviewUrl($sessionId, $generationId, $previewToken),
                    'suggested_filename' => $meta['suggested_filename'],
                ]);
            } catch (\Throwable $throwable) {
                $failed++;
                $sse->sendEvent('error', [
                    'code' => 'GENERATION_FAILED',
                    'message' => $throwable->getMessage(),
                    'batch_index' => $batchIndex,
                    'batch_total' => $batchTotal,
                    'partial' => $generationIds !== [],
                ]);
                if ($mode !== 'batch') {
                    $sse->close();
                    return;
                }
            }
        }

        if ($generationIds === []) {
            $sse->close();
            return;
        }

        $lastMeta = [];
        if ($generationIds !== []) {
            $lastLoaded = $this->sessionStore->loadGeneration($sessionId, $adminId, $generationIds[\count($generationIds) - 1]);
            if ($lastLoaded !== null) {
                $lastMeta = $lastLoaded['meta'];
            }
        }

        $sse->sendEvent('complete', [
            'session_id' => $sessionId,
            'generation_id' => $generationIds[0],
            'generation_ids' => $generationIds,
            'preview_token' => (string)($lastMeta['preview_token'] ?? ''),
            'preview_url' => $generationIds !== []
                ? $this->buildPreviewUrl(
                    $sessionId,
                    $generationIds[0],
                    (string)($lastMeta['preview_token'] ?? '')
                )
                : '',
            'suggested_filename' => (string)($lastMeta['suggested_filename'] ?? ''),
            'partial' => $failed > 0,
            'failed_count' => $failed,
        ]);
        $sse->close();
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function save(int $adminId, array $input): array
    {
        $saveMode = \strtolower(\trim((string)($input['save_mode'] ?? 'save_as')));
        $sessionId = \trim((string)($input['session_id'] ?? ''));
        $generationIds = $this->normalizeGenerationIds($input);
        if ($generationIds === []) {
            throw new \InvalidArgumentException(__('缺少生成结果 ID'));
        }
        if ($sessionId === '') {
            throw new \InvalidArgumentException(__('缺少会话 ID'));
        }

        if ($saveMode === 'overwrite') {
            if (\count($generationIds) !== 1) {
                throw new \InvalidArgumentException(__('覆盖原图仅支持单张保存'));
            }
            $sourceFileHash = \trim((string)($input['source_file_hash'] ?? ''));
            if ($sourceFileHash === '') {
                throw new \InvalidArgumentException(__('缺少源文件'));
            }
            $loaded = $this->sessionStore->loadGeneration($sessionId, $adminId, $generationIds[0]);
            if ($loaded === null) {
                throw new \RuntimeException(__('生成结果已过期，请重新生成'));
            }
            $meta = $loaded['meta'];
            if ((string)($meta['source_file_hash'] ?? '') !== '' && (string)$meta['source_file_hash'] !== $sourceFileHash) {
                throw new \RuntimeException(__('源文件与生成记录不匹配'));
            }
            $filename = \trim((string)($input['filename'] ?? ''));
            $updated = $this->mediaStorage->overwriteFile(
                $sourceFileHash,
                $loaded['bytes'],
                $filename !== '' ? $filename : null
            );

            return ['updated' => [$updated]];
        }

        $target = \trim((string)($input['target'] ?? ''));
        if ($target === '') {
            throw new \InvalidArgumentException(__('缺少目标目录'));
        }
        $filenames = \is_array($input['filenames'] ?? null) ? $input['filenames'] : [];
        $filename = \trim((string)($input['filename'] ?? ''));
        $added = [];
        foreach ($generationIds as $idx => $generationId) {
            $loaded = $this->sessionStore->loadGeneration($sessionId, $adminId, $generationId);
            if ($loaded === null) {
                throw new \RuntimeException(__('生成结果已过期，请重新生成'));
            }
            $meta = $loaded['meta'];
            $name = \trim((string)($filenames[$idx] ?? ''));
            if ($name === '') {
                $name = $filename !== '' ? $filename : (string)($meta['suggested_filename'] ?? ('ai-draw-' . $generationId . '.png'));
            }
            if (\count($generationIds) > 1 && $filename === '' && !isset($filenames[$idx])) {
                $name = $this->suggestFilename(
                    (string)($meta['mime_type'] ?? 'image/png'),
                    $generationId,
                    (int)($meta['batch_index'] ?? ($idx + 1)),
                    (string)($meta['prompt'] ?? '')
                );
            }
            $added[] = $this->mediaStorage->writeNewFile($target, $name, $loaded['bytes']);
        }

        return ['added' => $added];
    }

    /**
     * @return array{bytes:string,mime_type:string}|null
     */
    public function loadPreview(int $adminId, string $sessionId, string $generationId, string $previewToken = ''): ?array
    {
        $previewToken = \trim($previewToken);
        if ($previewToken !== '') {
            $loaded = $this->sessionStore->loadGenerationByPreviewToken($sessionId, $generationId, $previewToken);
        } elseif ($adminId > 0) {
            $loaded = $this->sessionStore->loadGeneration($sessionId, $adminId, $generationId);
        } else {
            return null;
        }
        if ($loaded === null) {
            return null;
        }
        $mime = \trim((string)($loaded['meta']['mime_type'] ?? 'image/png')) ?: 'image/png';

        return ['bytes' => $loaded['bytes'], 'mime_type' => $mime];
    }

    public function buildPreviewUrl(string $sessionId, string $generationId, string $previewToken = ''): string
    {
        $params = [
            'session_id' => $sessionId,
            'generation_id' => $generationId,
        ];
        $previewToken = \trim($previewToken);
        if ($previewToken !== '') {
            $params['preview_token'] = $previewToken;
        }

        return $this->resolveUrl()->getBackendUrl('media/backend/ai-draw/preview', $params);
    }

    /**
     * @return array<string,mixed>
     */
    public function getConfigStatus(): array
    {
        try {
            $service = $this->resolveAiService();
            $model = $service->resolveModel(null, self::SCENARIO_CODE, 'text2image');
            $modelCode = \is_array($model) ? (string)($model['model_code'] ?? '') : '';

            return [
                'ready' => $model !== null,
                'scenario_code' => self::SCENARIO_CODE,
                'model' => $modelCode,
                'model_info' => $model,
                'mock' => $this->isMockEnabled(),
            ];
        } catch (\Throwable $throwable) {
            return [
                'ready' => false,
                'scenario_code' => self::SCENARIO_CODE,
                'message' => $throwable->getMessage(),
                'mock' => $this->isMockEnabled(),
            ];
        }
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function buildGenerationParams(
        array $input,
        string $mode,
        string $sourceFileHash,
        string $parentGenerationId,
        string $sessionId,
        int $adminId,
        int $batchIndex,
        int $batchTotal
    ): array {
        $params = [
            'mode' => $mode,
            'is_backend' => true,
            'user_id' => $adminId,
            'disable_conversation_history' => $mode !== 'edit_turn',
            'disable_conversation_persist' => true,
            'disable_skill_prompt_injection' => true,
            'disable_style_prompt_injection' => true,
            'size' => \trim((string)($input['size'] ?? '1024x1024')) ?: '1024x1024',
            'aspect_ratio' => \trim((string)($input['aspect_ratio'] ?? '1:1')) ?: '1:1',
            'output_format' => \trim((string)($input['output_format'] ?? 'png')) ?: 'png',
            'negative_prompt' => \trim((string)($input['negative_prompt'] ?? '')),
            'source_file_hash' => $sourceFileHash,
            'batch_index' => $batchIndex,
            'batch_total' => $batchTotal,
            'session_id' => $sessionId,
        ];
        if ($parentGenerationId !== '') {
            $loaded = $this->sessionStore->loadGeneration($sessionId, $adminId, $parentGenerationId);
            if ($loaded === null) {
                throw new \RuntimeException(__('上一轮生成结果已过期'));
            }
            $params['reference_image'] = 'data:' . ($loaded['meta']['mime_type'] ?? 'image/png') . ';base64,' . \base64_encode($loaded['bytes']);
            $params['parent_generation_id'] = $parentGenerationId;
        } elseif ($sourceFileHash !== '') {
            $ref = $this->mediaStorage->readFileBytes($sourceFileHash);
            if (!\str_starts_with((string)$ref['mime'], 'image/')) {
                throw new \InvalidArgumentException(__('参考文件必须是图片'));
            }
            $params['reference_image'] = 'data:' . $ref['mime'] . ';base64,' . \base64_encode($ref['bytes']);
            $params['image'] = $params['reference_image'];
        }

        return $params;
    }

    /**
     * @param array<string,mixed> $params
     * @return array{bytes:string,mime_type:string}
     */
    private function generateImageBytes(string $prompt, array $params, int $adminId): array
    {
        if ($this->isMockEnabled()) {
            return $this->mockImageBytes((string)($params['output_format'] ?? 'png'));
        }
        $service = $this->resolveAiService();
        $result = $service->generateImage($prompt, null, self::SCENARIO_CODE, $params);
        $image = $this->firstImage($result);
        if ($image === []) {
            throw new \RuntimeException(__('图片生成未返回有效结果'));
        }
        [$bytes, $mime] = $this->resolveImageBytes($image);
        if ($bytes === '') {
            throw new \RuntimeException(__('图片生成未返回有效字节'));
        }

        return ['bytes' => $bytes, 'mime_type' => $mime];
    }

    /**
     * 长耗时文生图期间维持 SSE 心跳，避免 WLS/浏览器因长时间无字节而静默断连。
     *
     * @param array<string,mixed> $params
     * @return array{bytes:string,mime_type:string}
     */
    private function generateImageBytesWithSseKeepalive(SseWriter $sse, string $prompt, array $params, int $adminId): array
    {
        if ($this->isMockEnabled()) {
            return $this->mockImageBytes((string)($params['output_format'] ?? 'png'));
        }

        if (!\class_exists(\Fiber::class)) {
            $sse->sendHeartbeat();

            return $this->generateImageBytes($prompt, $params, $adminId);
        }

        $state = ['done' => false, 'result' => null, 'error' => null];
        $runner = new \Weline\Framework\Php\FiberTaskRunner(defaultConcurrency: 2);
        $runner->run([
            'generate' => function () use (&$state, $prompt, $params, $adminId): void {
                try {
                    $state['result'] = $this->generateImageBytes($prompt, $params, $adminId);
                } catch (\Throwable $throwable) {
                    $state['error'] = $throwable;
                } finally {
                    $state['done'] = true;
                }
            },
            'keepalive' => function () use ($sse, &$state): void {
                while (!$state['done']) {
                    $sse->sendHeartbeat();
                    \Weline\Framework\Runtime\SchedulerSystem::sleep(5);
                }
            },
        ], 2);

        if ($state['error'] instanceof \Throwable) {
            throw $state['error'];
        }
        if (!\is_array($state['result'])) {
            throw new \RuntimeException(__('图片生成失败'));
        }

        return $state['result'];
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function firstImage(array $result): array
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
                throw new \RuntimeException(__('图片 base64 无效'));
            }

            return [$bytes, $mimeType];
        }
        $url = \trim((string)($image['url'] ?? ''));
        if ($url !== '') {
            if (\preg_match('#^data:([^;]+);base64,(.+)$#s', $url, $matches) === 1) {
                $bytes = \base64_decode(\preg_replace('/\s+/', '', (string)$matches[2]) ?? '', true);
                if ($bytes === false) {
                    throw new \RuntimeException(__('图片 data URL 无效'));
                }

                return [$bytes, \strtolower((string)$matches[1]) ?: $mimeType];
            }
            $bytes = $this->downloadImageUrl($url);
            return [$bytes, $mimeType];
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
                \CURLOPT_CONNECTTIMEOUT => 10,
                \CURLOPT_TIMEOUT => 120,
            ]);
            $body = \curl_exec($ch);
            \curl_close($ch);
            if (\is_string($body) && $body !== '') {
                return $body;
            }
        }
        $body = @\file_get_contents($url);
        if ($body === false || $body === '') {
            throw new \RuntimeException(__('无法下载生成的图片'));
        }

        return $body;
    }

    /**
     * @return list<string>
     */
    private function buildJobs(string $mode, string $prompt, array $prompts, int $batchCount): array
    {
        if ($mode === 'batch') {
            if ($prompts !== []) {
                return \array_slice($prompts, 0, self::BATCH_MAX);
            }
            if ($prompt === '') {
                return [];
            }

            return \array_fill(0, $batchCount, $prompt);
        }
        if ($prompt === '') {
            return [];
        }

        return [$prompt];
    }

    /**
     * @return list<string>
     */
    private function normalizePromptList(mixed $prompts): array
    {
        if (!\is_array($prompts)) {
            return [];
        }
        $items = [];
        foreach ($prompts as $item) {
            $text = \trim((string)$item);
            if ($text !== '') {
                $items[] = $text;
            }
        }

        return \array_slice($items, 0, self::BATCH_MAX);
    }

    /**
     * @param array<string,mixed> $input
     * @return list<string>
     */
    private function normalizeGenerationIds(array $input): array
    {
        $ids = [];
        if (\is_array($input['generation_ids'] ?? null)) {
            foreach ($input['generation_ids'] as $id) {
                $value = \trim((string)$id);
                if ($value !== '') {
                    $ids[] = $value;
                }
            }
        }
        $single = \trim((string)($input['generation_id'] ?? ''));
        if ($single !== '') {
            $ids[] = $single;
        }

        return \array_values(\array_unique($ids));
    }

    private function suggestFilename(string $mimeType, string $generationId, int $index = 0, string $prompt = ''): string
    {
        $ext = match (true) {
            \str_contains($mimeType, 'webp') => 'webp',
            \str_contains($mimeType, 'jpeg') || \str_contains($mimeType, 'jpg') => 'jpg',
            default => 'png',
        };
        $suffix = \substr($generationId, 0, 8);
        $altStem = $this->promptToAltFilenameStem($prompt);
        if ($index > 1) {
            $base = $altStem !== '' ? $altStem . '-batch-' . $index : 'ai-draw-batch-' . $index;
        } else {
            $base = $altStem !== '' ? $altStem : 'ai-draw';
        }

        return $base . '-' . $suffix . '.' . $ext;
    }

    /**
     * 将用户描述压缩为 alt 级文件名主干（简短可读，不含扩展名）。
     */
    private function promptToAltFilenameStem(string $prompt): string
    {
        $prompt = \trim($prompt);
        if ($prompt === '') {
            return '';
        }

        $firstLine = \trim((string)\strtok($prompt, "\r\n"));
        if ($firstLine === '') {
            return '';
        }

        $firstLine = (string)(\preg_replace('/\s+/u', ' ', $firstLine) ?? $firstLine);
        $firstLine = $this->truncateUtf8($firstLine, 36);
        $stem = (string)(\preg_replace('/[<>:"|?*\\\\\/\x00-\x1F\x7F]/u', '', $firstLine) ?? '');
        $stem = \trim($stem, " ._\t-");
        $stem = (string)(\preg_replace('/\s+/u', '-', $stem) ?? '');
        $stem = (string)(\preg_replace('/-+/', '-', $stem) ?? '');
        $stem = \trim($stem, '-');

        if ($stem === '' || $stem === '.' || $stem === '..') {
            return '';
        }

        return $this->truncateUtf8($stem, 48);
    }

    private function truncateUtf8(string $text, int $maxChars): string
    {
        if ($maxChars <= 0 || $text === '') {
            return '';
        }
        if (\function_exists('mb_substr')) {
            return (string)\mb_substr($text, 0, $maxChars, 'UTF-8');
        }

        return \strlen($text) <= $maxChars ? $text : \substr($text, 0, $maxChars);
    }

    /**
     * @return array{bytes:string,mime_type:string}
     */
    private function mockImageBytes(string $format): array
    {
        $mime = match (\strtolower($format)) {
            'webp' => 'image/webp',
            'jpeg', 'jpg' => 'image/jpeg',
            default => 'image/png',
        };
        if (\function_exists('imagecreatetruecolor')) {
            $width = 256;
            $height = 256;
            $image = \imagecreatetruecolor($width, $height);
            if ($image !== false) {
                $background = \imagecolorallocate($image, 45, 85, 135);
                $foreground = \imagecolorallocate($image, 255, 255, 255);
                \imagefilledrectangle($image, 0, 0, $width, $height, $background);
                \imagestring($image, 5, 88, 118, 'MOCK', $foreground);
                \ob_start();
                if (\str_contains($mime, 'webp') && \function_exists('imagewebp')) {
                    \imagewebp($image, null, 90);
                } elseif (\str_contains($mime, 'jpeg') || \str_contains($mime, 'jpg')) {
                    \imagejpeg($image, null, 90);
                } else {
                    \imagepng($image);
                    $mime = 'image/png';
                }
                $bytes = \ob_get_clean();
                \imagedestroy($image);
                if (\is_string($bytes) && $bytes !== '') {
                    return ['bytes' => $bytes, 'mime_type' => $mime];
                }
            }
        }
        $png = \base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+X2ZkAAAAASUVORK5CYII=',
            true
        );
        if ($png === false) {
            throw new \RuntimeException(__('Mock 图片生成失败'));
        }

        return ['bytes' => $png, 'mime_type' => 'image/png'];
    }

    private function isMockEnabled(): bool
    {
        if (!\defined('WELINE_MEDIA_AI_DRAW_ACCEPTANCE') || !WELINE_MEDIA_AI_DRAW_ACCEPTANCE) {
            return false;
        }
        $flag = \getenv('WELINE_MEDIA_AI_DRAW_MOCK');
        if ($flag === false || $flag === '') {
            return false;
        }

        return !\in_array(\strtolower((string)$flag), ['0', 'false', 'no', 'off'], true);
    }

    private function resolveAiService(): AiService
    {
        if ($this->aiService instanceof AiService) {
            return $this->aiService;
        }

        return ObjectManager::getInstance(AiService::class);
    }

    private function resolveUrl(): Url
    {
        if ($this->url instanceof Url) {
            return $this->url;
        }

        return ObjectManager::getInstance(Url::class);
    }
}
