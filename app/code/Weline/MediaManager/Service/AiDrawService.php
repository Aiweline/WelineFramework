<?php

declare(strict_types=1);

namespace Weline\MediaManager\Service;

use Weline\Ai\Api\Image\ImageRuntimeInterface;
use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Storage\Api\Data\StorageDiskCode;
use Weline\Storage\Api\Runtime\StorageRequestResourceFactoryInterface;

class AiDrawService
{
    private const SCENARIO_CODE = 'media_manager_ai_draw';
    private const BATCH_MAX = 8;

    public function __construct(
        private readonly MediaStorageService $mediaStorage,
        private readonly AiDrawSessionStore $sessionStore,
        private readonly MediaFileAccessContextFactory $fileAccessContexts,
        private readonly StorageRequestResourceFactoryInterface $resourceFactory,
        private readonly ?ImageRuntimeInterface $aiService = null,
        private readonly ?Url $url = null,
    ) {
    }

    /**
     * @param array<string,mixed> $input
     */
    /**
     * @param SseWriter|CollectingSseWriter $sse
     */
    public function streamGenerate(object $sse, int $adminId, array $input): void
    {
        $fileAccess = $this->fileAccessContexts->fromFrozen($input, $adminId);
        $diskCode = $this->requiredDiskCode($input);
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

                $params = $this->buildGenerationParams(
                    $input,
                    $mode,
                    $sourceFileHash,
                    $parentGenerationId,
                    $sessionId,
                    $adminId,
                    $batchIndex,
                    $batchTotal,
                    $diskCode,
                    $fileAccess,
                );
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
        $fileAccess = $this->fileAccessContexts->fromFrozen($input, $adminId);
        $diskCode = $this->requiredDiskCode($input);
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
            throw new \InvalidArgumentException((string)__(
                '统一 FileAsset 模式不允许原位覆盖共享资源，请另存为新资源后更新业务引用。',
            ));
        }
        if ($saveMode !== 'save_as') {
            throw new \InvalidArgumentException((string)__('图片保存模式无效。'));
        }

        $target = \trim((string)($input['target'] ?? ''));
        if ($target === '') {
            throw new \InvalidArgumentException(__('缺少目标目录'));
        }
        $filenames = \is_array($input['filenames'] ?? null) ? $input['filenames'] : [];
        $filename = \trim((string)($input['filename'] ?? ''));
        $defaultAlt = \trim((string)($input['default_alt'] ?? ''));
        $description = \trim((string)($input['description'] ?? ''));
        $caption = \trim((string)($input['default_caption'] ?? ''));
        if ($defaultAlt === '' || $description === '') {
            throw new \InvalidArgumentException((string)__('保存生成图片必须填写默认 alt 和资源描述。'));
        }
        $visibility = $this->mediaStorage->defaultVisibility($diskCode);
        $added = [];
        try {
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
                if (\count($generationIds) > 1) {
                    $name = isset($filenames[$idx]) && \trim((string)$filenames[$idx]) !== ''
                        ? \trim((string)$filenames[$idx])
                        : $this->indexedFilename($name, $idx + 1);
                }
                $displayName = \trim((string)($input['display_name'] ?? ''));
                if ($displayName === '') {
                    $displayName = (string)pathinfo($name, PATHINFO_FILENAME);
                }
                $added[] = $this->mediaStorage->writeNewFile(
                    $diskCode,
                    $target,
                    $name,
                    $loaded['bytes'],
                    (string)($meta['mime_type'] ?? 'image/png'),
                    $fileAccess,
                    [
                        'display_name' => $displayName,
                        'default_alt' => $defaultAlt,
                        'description' => $description,
                        'default_caption' => $caption,
                    ],
                    $visibility,
                    [
                        'ai_generation_id' => $generationId,
                        'ai_session_id_hash' => hash('sha256', $sessionId),
                    ],
                );
            }
        } catch (\Throwable $throwable) {
            $rollbackFailed = false;
            foreach (array_reverse($added) as $asset) {
                try {
                    $this->mediaStorage->deleteFile(
                        $diskCode,
                        (string)($asset['hash'] ?? ''),
                        $fileAccess,
                    );
                } catch (\Throwable) {
                    $rollbackFailed = true;
                }
            }
            if ($rollbackFailed) {
                throw new \RuntimeException(
                    (string)__('保存生成图片未全部完成，且部分资源无法自动回收，请立即刷新并人工核对。'),
                    0,
                    $throwable,
                );
            }
            throw $throwable;
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

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function generateAndStoreResumable(
        int $adminId,
        string $taskId,
        string $sessionId,
        string $generationId,
        string $prompt,
        array $input,
        string $mode,
        string $sourceFileHash,
        string $parentGenerationId,
        int $batchIndex,
        int $batchTotal,
        string $idempotencyKey,
        callable $heartbeat,
    ): array {
        $fileAccess = $this->fileAccessContexts->fromFrozen($input, $adminId);
        $params = $this->buildGenerationParams(
            $input,
            $mode,
            $sourceFileHash,
            $parentGenerationId,
            $sessionId,
            $adminId,
            $batchIndex,
            $batchTotal,
            $this->requiredDiskCode($input),
            $fileAccess,
        );
        $params['mode'] = $mode === 'batch'
            ? ($sourceFileHash !== '' || $parentGenerationId !== '' ? 'image2image' : 'text2image')
            : $mode;
        $params['idempotency_key'] = $idempotencyKey;
        $result = $this->generateImageBytesWithHeartbeat($prompt, $params, $adminId, $heartbeat);
        $meta = [
            'mode' => (string)$params['mode'],
            'prompt' => $prompt,
            'mime_type' => $result['mime_type'],
            'source_file_hash' => $sourceFileHash,
            'target' => trim((string)($input['target'] ?? '')),
            'batch_index' => $batchIndex,
            'batch_total' => $batchTotal,
            'task_id' => $taskId,
            'suggested_filename' => $this->suggestFilename(
                $result['mime_type'],
                $generationId,
                $batchTotal > 1 ? $batchIndex : 0,
                $prompt,
            ),
        ];
        $previewToken = $this->sessionStore->storeGeneration(
            $sessionId,
            $adminId,
            $generationId,
            $result['bytes'],
            $meta,
        );
        $this->sessionStore->appendTurn($sessionId, $adminId, $generationId, $prompt);

        return [
            'session_id' => $sessionId,
            'generation_id' => $generationId,
            'batch_index' => $batchIndex,
            'batch_total' => $batchTotal,
            'mime_type' => $result['mime_type'],
            'preview_token' => $previewToken,
            'preview_url' => $this->buildPreviewUrl($sessionId, $generationId, $previewToken),
            'suggested_filename' => $meta['suggested_filename'],
        ];
    }

    /** @return array<string,mixed>|null */
    public function loadResumableGenerationPayload(
        int $adminId,
        string $sessionId,
        string $generationId,
    ): ?array {
        $loaded = $this->sessionStore->loadGeneration($sessionId, $adminId, $generationId);
        if ($loaded === null) {
            return null;
        }
        $meta = $loaded['meta'];
        $previewToken = trim((string)($meta['preview_token'] ?? ''));

        return [
            'session_id' => $sessionId,
            'generation_id' => $generationId,
            'batch_index' => max(1, (int)($meta['batch_index'] ?? 1)),
            'batch_total' => max(1, (int)($meta['batch_total'] ?? 1)),
            'mime_type' => (string)($meta['mime_type'] ?? 'image/png'),
            'preview_token' => $previewToken,
            'preview_url' => $this->buildPreviewUrl($sessionId, $generationId, $previewToken),
            'suggested_filename' => (string)($meta['suggested_filename'] ?? ''),
        ];
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
     * Rewrite a short user prompt into a richer image-generation prompt.
     *
     * @return array{success:bool,message:string,data?:array{prompt:string}}
     */
    public function polishPrompt(string $prompt): array
    {
        $prompt = \trim($prompt);
        if ($prompt === '') {
            return [
                'success' => false,
                'message' => (string)__('请先输入提示词'),
            ];
        }
        if (\mb_strlen($prompt, 'UTF-8') > 4000) {
            $prompt = (string)\mb_substr($prompt, 0, 4000, 'UTF-8');
        }

        if ($this->isMockEnabled()) {
            $mock = \trim($prompt . '，主体清晰，构图完整，柔和光线，高质量细节，适合文生图');
            return [
                'success' => true,
                'message' => (string)__('润色完成'),
                'data' => ['prompt' => $mock],
            ];
        }

        $instruction = (string)__(
            "你是图像生成提示词润色助手。请把用户的简短描述改写成更适合文生图的提示词：保留原意，补充主体、构图、风格、光线与画质关键词；不要解释、不要前后缀说明，只输出润色后的提示词本身。\n\n用户描述：\n%{1}",
            [$prompt]
        );

        try {
            $textModelCode = $this->resolvePolishModelCode();
            if ($textModelCode === '') {
                return [
                    'success' => false,
                    'message' => (string)__(
                        '润色需要可用的文本模型：请在「AI - 场景适配器」为 %{1} 绑定 text2text 模型，或在「AI - 默认模型」配置全局默认文本模型。',
                        [self::SCENARIO_CODE]
                    ),
                ];
            }

            /** @var \Weline\Ai\Api\AiRuntimeInterface $aiRuntime */
            $aiRuntime = ObjectManager::getInstance(\Weline\Ai\Api\AiRuntimeInterface::class);
            $polished = \trim($aiRuntime->generate(
                $instruction,
                $textModelCode,
                null,
                null,
                ['disable_conversation_history' => true, 'disable_conversation_persist' => true],
                null,
                true
            ));
            $polished = \preg_replace('/^["“]|["”]$/u', '', $polished) ?? $polished;
            $polished = \trim((string)$polished);
            if ($polished === '') {
                return [
                    'success' => false,
                    'message' => (string)__('润色失败：模型未返回内容'),
                ];
            }

            return [
                'success' => true,
                'message' => (string)__('润色完成'),
                'data' => ['prompt' => $polished],
            ];
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'message' => (string)__('润色失败：%{1}', [$this->toPlainMessage($throwable->getMessage())]),
            ];
        }
    }

    private function resolvePolishModelCode(): string
    {
        $model = $this->resolveAiService()->resolveModel(null, self::SCENARIO_CODE, 'text2text');

        return \is_array($model) ? \trim((string)($model['model_code'] ?? '')) : '';
    }

    /**
     * AI 层的错误消息可能带有配置引导 HTML，弹层/Toast 只展示纯文本。
     */
    private function toPlainMessage(string $message): string
    {
        $message = (string)\preg_replace('#<div class="ai-config-links".*?</div>#is', '', $message);
        $message = \strip_tags($message);

        return \trim((string)\preg_replace('/\s+/u', ' ', $message));
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
        int $batchTotal,
        string $diskCode,
        FileAccessContext $fileAccess,
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
            $ref = $this->mediaStorage->readFileBytes($diskCode, $sourceFileHash, $fileAccess);
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
        $result = $service->generate($prompt, null, self::SCENARIO_CODE, $params);
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
     * @param SseWriter|CollectingSseWriter $sse
     * @param array<string,mixed> $params
     * @return array{bytes:string,mime_type:string}
     */
    private function generateImageBytesWithSseKeepalive(object $sse, string $prompt, array $params, int $adminId): array
    {
        if (!($sse instanceof SseWriter) && !($sse instanceof CollectingSseWriter)) {
            throw new \InvalidArgumentException('SSE writer must be SseWriter or CollectingSseWriter.');
        }
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

    /** @return array{bytes:string,mime_type:string} */
    private function generateImageBytesWithHeartbeat(
        string $prompt,
        array $params,
        int $adminId,
        callable $heartbeat,
    ): array {
        $heartbeat();
        if ($this->isMockEnabled() || !class_exists(\Fiber::class)) {
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
            'heartbeat' => function () use (&$state, $heartbeat): void {
                while (!$state['done']) {
                    $heartbeat();
                    \Weline\Framework\Runtime\SchedulerSystem::sleep(5);
                }
            },
        ], 2);
        if ($state['error'] instanceof \Throwable) {
            throw $state['error'];
        }
        if (!is_array($state['result'])) {
            throw new \RuntimeException((string)__('图片生成失败。'));
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

    private function downloadImageUrl(string $url, int $redirects = 0): string
    {
        if ($redirects > 3 || !function_exists('curl_init')) {
            throw new \RuntimeException((string)__('无法安全下载生成的图片。'));
        }
        [$host, $port, $resolvedIp] = $this->assertPublicImageUrl($url);
        $curl = curl_init($url);
        if (!$curl instanceof \CurlHandle) {
            throw new \RuntimeException((string)__('无法初始化图片下载客户端。'));
        }
        $lease = $this->resourceFactory->clientLease(
            $curl,
            static fn (object $client) => curl_close($client),
        );
        $body = '';
        $tooLarge = false;
        $location = '';
        $contentType = '';
        try {
            $handle = $lease->client();
            $resolveIp = str_contains($resolvedIp, ':') ? '[' . $resolvedIp . ']' : $resolvedIp;
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $resolveIp],
                CURLOPT_HEADERFUNCTION => static function (mixed $_handle, string $line) use (&$location, &$contentType, &$tooLarge): int {
                    $trimmed = trim($line);
                    if (stripos($trimmed, 'Location:') === 0) {
                        $location = trim(substr($trimmed, 9));
                    } elseif (stripos($trimmed, 'Content-Type:') === 0) {
                        $contentType = strtolower(trim(explode(';', substr($trimmed, 13), 2)[0]));
                    } elseif (stripos($trimmed, 'Content-Length:') === 0
                        && (int)trim(substr($trimmed, 15)) > MediaStorageService::MAX_IMAGE_BYTES
                    ) {
                        $tooLarge = true;
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function (mixed $_handle, string $chunk) use (&$body, &$tooLarge): int {
                    if ($tooLarge || strlen($body) + strlen($chunk) > MediaStorageService::MAX_IMAGE_BYTES) {
                        $tooLarge = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($handle);
            $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = curl_error($handle);
        } finally {
            $lease->close();
        }
        if ($tooLarge) {
            throw new \RuntimeException((string)__('生成图片超过下载大小限制。'));
        }
        if ($status >= 300 && $status < 400 && $location !== '') {
            return $this->downloadImageUrl($this->resolveImageRedirect($url, $location), $redirects + 1);
        }
        if ($ok !== true || $status < 200 || $status >= 300 || $body === '') {
            throw new \RuntimeException((string)__('无法下载生成的图片：%{1}', [$error !== '' ? $error : 'HTTP ' . $status]));
        }
        if ($contentType !== '' && !str_starts_with($contentType, 'image/')) {
            throw new \RuntimeException((string)__('生成图片下载响应类型无效。'));
        }

        return $body;
    }

    /** @return array{0:string,1:int,2:string} */
    private function assertPublicImageUrl(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower(rtrim(trim((string)($parts['host'] ?? '')), '.'));
        $port = (int)($parts['port'] ?? 443);
        if ($scheme !== 'https' || $host === '' || $port !== 443
            || isset($parts['user']) || isset($parts['pass'])
            || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.localhost')
        ) {
            throw new \InvalidArgumentException((string)__('生成图片地址必须是公共 HTTPS 地址。'));
        }

        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } elseif (function_exists('dns_get_record')) {
            foreach ((array)@dns_get_record($host, DNS_A | DNS_AAAA) as $record) {
                $ip = (string)($record['ip'] ?? $record['ipv6'] ?? '');
                if ($ip !== '') {
                    $ips[] = $ip;
                }
            }
        } else {
            $ips = (array)@gethostbynamel($host);
        }
        $ips = array_values(array_unique($ips));
        if ($ips === []) {
            throw new \InvalidArgumentException((string)__('生成图片地址无法解析。'));
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \InvalidArgumentException((string)__('生成图片地址不能解析到内网或保留地址。'));
            }
        }

        return [$host, $port, $ips[0]];
    }

    private function resolveImageRedirect(string $baseUrl, string $location): string
    {
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }
        if (!str_starts_with($location, '/')) {
            throw new \InvalidArgumentException((string)__('生成图片重定向地址无效。'));
        }
        $parts = parse_url($baseUrl);
        $host = (string)($parts['host'] ?? '');
        if ($host === '') {
            throw new \InvalidArgumentException((string)__('生成图片重定向地址无效。'));
        }

        return 'https://' . $host . $location;
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

    /** @param array<string,mixed> $input */
    private function requiredDiskCode(array $input): string
    {
        $diskCode = trim((string)($input['disk_code'] ?? $input['storage'] ?? ''));
        StorageDiskCode::parse($diskCode);

        return $diskCode;
    }

    private function indexedFilename(string $filename, int $index): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $stem = pathinfo($filename, PATHINFO_FILENAME);
        $stem = $stem !== '' ? $stem : 'ai-draw';

        return $stem . '-' . $index . ($extension !== '' ? '.' . $extension : '');
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

    private function resolveAiService(): ImageRuntimeInterface
    {
        if ($this->aiService instanceof ImageRuntimeInterface) {
            return $this->aiService;
        }

        return ObjectManager::getInstance(ImageRuntimeInterface::class);
    }

    private function resolveUrl(): Url
    {
        if ($this->url instanceof Url) {
            return $this->url;
        }

        return ObjectManager::getInstance(Url::class);
    }
}
