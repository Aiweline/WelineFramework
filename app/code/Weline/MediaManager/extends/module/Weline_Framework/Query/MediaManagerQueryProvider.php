<?php

declare(strict_types=1);

namespace Weline\MediaManager\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Service\Query\Value\FrontendWorkerExecutionContext;
use Weline\Framework\Session\SessionFactory;
use Weline\MediaManager\Helper\MimeTypes;
use Weline\MediaManager\Service\AiDrawService;
use Weline\MediaManager\Service\CollectingSseWriter;
use Weline\MediaManager\Service\ConnectorService;
use Weline\MediaManager\Service\MediaAssetUploadService;
use Weline\MediaManager\Service\MediaFileAccessContextFactory;
use Weline\MediaManager\Service\MediaUploadBase64Hydrator;

/**
 * Browser-facing MediaManager operations that need the authenticated backend
 * session.  AI generation itself is intentionally absent: it is started only
 * through runtime_task.start and executes in the detached Runner.
 */
final class MediaManagerQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly AiDrawService $aiDrawService,
        private readonly SessionFactory $sessions,
        private readonly MediaFileAccessContextFactory $fileAccessContexts,
    ) {
    }

    public function getProviderName(): string
    {
        return 'media_manager';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'config' => $this->config(),
            'save' => $this->save($params),
            'connector' => $this->connector($params),
            'generate' => $this->generate($params),
            'polishPrompt' => $this->polishPrompt($params),
            default => throw new \InvalidArgumentException(
                (string)__('媒体管理查询器不支持的操作：%{1}', $operation),
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => $this->getProviderName(),
            'name' => (string)__('媒体管理器'),
            'description' => (string)__('提供后台文件连接器、AI 作图配置/生成及结果保存。'),
            'module' => 'Weline_MediaManager',
            'operations' => [
                [
                    'name' => 'config',
                    'description' => (string)__('读取 AI 作图模型可用状态。'),
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => false,
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_MediaManager::query:ai_draw',
                    ],
                    'params' => [],
                ],
                [
                    'name' => 'save',
                    'description' => (string)__('保存当前后台用户拥有的 AI 作图结果。'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_MediaManager::query:ai_draw_save',
                    ],
                    'params' => [
                        ['name' => 'session_id', 'type' => 'string', 'required' => true, 'max_length' => 64],
                        ['name' => 'save_mode', 'type' => 'string', 'required' => false, 'max_length' => 32],
                        ['name' => 'target', 'type' => 'string', 'required' => false, 'max_length' => 2048],
                        ['name' => 'disk_code', 'type' => 'string', 'required' => true, 'max_length' => 190],
                        ['name' => 'locale_code', 'type' => 'string', 'required' => true, 'max_length' => 16],
                        ['name' => 'source_file_hash', 'type' => 'string', 'required' => false, 'max_length' => 2048],
                        ['name' => 'filename', 'type' => 'string', 'required' => false, 'max_length' => 512],
                        ['name' => 'generation_id', 'type' => 'string', 'required' => false, 'max_length' => 64],
                        ['name' => 'generation_ids', 'type' => 'array', 'required' => false],
                        ['name' => 'display_name', 'type' => 'string', 'required' => false, 'max_length' => 255],
                        ['name' => 'default_alt', 'type' => 'string', 'required' => true, 'max_length' => 512],
                        ['name' => 'description', 'type' => 'string', 'required' => true, 'max_length' => 8000],
                        ['name' => 'default_caption', 'type' => 'string', 'required' => false, 'max_length' => 2000],
                        ['name' => 'crop', 'type' => 'object', 'required' => false],
                        ['name' => 'crops', 'type' => 'object', 'required' => false],
                    ],
                ],
                [
                    'name' => 'connector',
                    'description' => (string)__('媒体文件连接器（open/mkdir/rename/rm/upload 等）。'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_MediaManager::file_manager',
                    ],
                    'params' => [
                        ['name' => 'cmd', 'type' => 'string', 'required' => false, 'max_length' => 32],
                        ['name' => 'target', 'type' => 'string', 'required' => false, 'max_length' => 2048],
                        ['name' => 'targets', 'type' => 'array', 'required' => false, 'max_items' => MediaAssetUploadService::MAX_UPLOAD_FILES],
                        ['name' => 'name', 'type' => 'string', 'required' => false, 'max_length' => 512],
                        ['name' => 'ext', 'type' => 'string', 'required' => false, 'max_length' => 128],
                        ['name' => 'size', 'type' => 'int', 'required' => false, 'min' => 1, 'max' => MediaUploadBase64Hydrator::MAX_BYTES],
                        ['name' => 'startPath', 'type' => 'string', 'required' => false, 'max_length' => 1024],
                        // FE open(init) sends path=START_PATH; ConnectorService.handleOpen reads src.path.
                        ['name' => 'path', 'type' => 'string', 'required' => false, 'max_length' => 1024],
                        ['name' => 'storage', 'type' => 'string', 'required' => false, 'max_length' => 190],
                        ['name' => 'init', 'type' => 'string', 'required' => false, 'max_length' => 16],
                        ['name' => 'tree', 'type' => 'string', 'required' => false, 'max_length' => 16],
                        ['name' => 'reload', 'type' => 'string', 'required' => false, 'max_length' => 16],
                        ['name' => 'locale_code', 'type' => 'string', 'required' => false, 'max_length' => 16],
                        ['name' => 'asset_id', 'type' => 'string', 'required' => false, 'max_length' => 36],
                        ['name' => 'asset_revision', 'type' => 'int', 'required' => false, 'min' => 1],
                        ['name' => 'display_name', 'type' => 'string', 'required' => false, 'max_length' => 255],
                        ['name' => 'default_alt', 'type' => 'string', 'required' => false, 'max_length' => 512],
                        ['name' => 'description', 'type' => 'string', 'required' => false, 'max_length' => 8000],
                        ['name' => 'default_caption', 'type' => 'string', 'required' => false, 'max_length' => 2000],
                        ['name' => 'visibility', 'type' => 'string', 'required' => false, 'max_length' => 16],
                        ['name' => 'upload_base64', 'type' => 'array', 'required' => false, 'max_items' => MediaAssetUploadService::MAX_UPLOAD_FILES],
                        ['name' => 'upload_metadata', 'type' => 'array', 'required' => false, 'max_items' => MediaAssetUploadService::MAX_UPLOAD_FILES],
                    ],
                ],
                [
                    'name' => 'generate',
                    'description' => (string)__('后台 AI 作图（服务端收集事件，禁止浏览器 EventSource）。'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_MediaManager::query:ai_draw',
                    ],
                    'params' => [
                        ['name' => 'prompt', 'type' => 'string', 'required' => false, 'max_length' => 8000],
                        ['name' => 'prompts', 'type' => 'array', 'required' => false],
                        ['name' => 'mode', 'type' => 'string', 'required' => false, 'max_length' => 64],
                        ['name' => 'session_id', 'type' => 'string', 'required' => false, 'max_length' => 64],
                        ['name' => 'target', 'type' => 'string', 'required' => false, 'max_length' => 2048],
                        ['name' => 'model', 'type' => 'string', 'required' => false, 'max_length' => 128],
                        ['name' => 'width', 'type' => 'int', 'required' => false],
                        ['name' => 'height', 'type' => 'int', 'required' => false],
                        ['name' => 'size', 'type' => 'string', 'required' => false, 'max_length' => 32],
                        ['name' => 'output_format', 'type' => 'string', 'required' => false, 'max_length' => 16],
                        ['name' => 'aspect_ratio', 'type' => 'string', 'required' => false, 'max_length' => 16],
                        ['name' => 'negative_prompt', 'type' => 'string', 'required' => false, 'max_length' => 4000],
                        ['name' => 'source_file_hash', 'type' => 'string', 'required' => false, 'max_length' => 2048],
                        ['name' => 'parent_generation_id', 'type' => 'string', 'required' => false, 'max_length' => 64],
                        ['name' => 'batch_count', 'type' => 'int', 'required' => false],
                    ],
                ],
                [
                    'name' => 'polishPrompt',
                    'description' => (string)__('润色 AI 作图提示词。'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_MediaManager::query:ai_draw',
                    ],
                    'params' => [
                        ['name' => 'prompt', 'type' => 'string', 'required' => true, 'max_length' => 4000],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function config(): array
    {
        $this->backendUserId();
        return $this->aiDrawService->getConfigStatus();
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<string,mixed>
     */
    private function polishPrompt(array $params): array
    {
        $this->backendUserId();
        return $this->aiDrawService->polishPrompt((string)($params['prompt'] ?? ''));
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<string,mixed>
     */
    private function save(array $params): array
    {
        $backendUserId = $this->backendUserId();
        return $this->aiDrawService->save(
            $backendUserId,
            $this->fileAccessContexts->freeze($params, $backendUserId),
        );
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function connector(array $params): array
    {
        $backendUserId = $this->backendUserId();
        /** @var MediaUploadBase64Hydrator $hydrator */
        $hydrator = ObjectManager::getInstance(MediaUploadBase64Hydrator::class);
        $hydrated = $hydrator->hydrate($params);
        try {
            /** @var ConnectorService $connectorService */
            $connectorService = ObjectManager::getInstance(ConnectorService::class);

            $ext = $params['ext'] ?? '';
            $mimes = MimeTypes::collectMimes($ext);
            $opts = [
                'locale' => (string)($params['locale_code'] ?? ''),
                'actor_id' => $backendUserId,
                'allowed_mimes' => $mimes,
                'allowed_extensions' => MimeTypes::collectExtensions((string)$ext),
                'max_upload_bytes' => max(1, min(
                    MediaUploadBase64Hydrator::MAX_BYTES,
                    (int)($params['size'] ?? MediaUploadBase64Hydrator::MAX_BYTES),
                )),
            ];
            $result = $connectorService->execute($params, $opts, $hydrated['files']);
            if (!\is_array($result)) {
                return ['success' => true, 'data' => $result];
            }
            unset($result['header']);
            return $result;
        } finally {
            $hydrator->cleanup($hydrated['temporary_resources']);
        }
    }

    /**
     * Non-stream AI draw for bin-query (collect SSE events server-side).
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function generate(array $params): array
    {
        $backendUserId = $this->backendUserId();
        $params = $this->fileAccessContexts->freeze($params, $backendUserId);
        $collector = new CollectingSseWriter();
        try {
            $this->aiDrawService->streamGenerate($collector, $backendUserId, $params);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'events' => $collector->events(),
            ];
        }
        return [
            'success' => true,
            'events' => $collector->events(),
        ];
    }

    private function backendUserId(): int
    {
        $fromWorker = $this->backendUserIdFromWorkerContext();
        if ($fromWorker !== null) {
            return $fromWorker;
        }

        $session = $this->sessions->createBackendSession();
        if (!$session->isStarted()) {
            $session->start();
        }
        $userId = $session->isLoggedIn() ? (int)($session->getUserId() ?? 0) : 0;
        if ($userId <= 0) {
            $this->denyBackendLogin();
        }

        return $userId;
    }

    /**
     * QueryBin already restored and ACL-checked the attested backend binding.
     * Re-starting a PHP Session from the API cookie would create a different
     * empty identity and fail closed as "please log in".
     */
    private function backendUserIdFromWorkerContext(): ?int
    {
        if (!RequestContext::has(FrontendWorkerExecutionContext::REQUEST_CONTEXT_KEY)) {
            return null;
        }

        $execution = RequestContext::get(FrontendWorkerExecutionContext::REQUEST_CONTEXT_KEY);
        if (!$execution instanceof FrontendWorkerExecutionContext
            || $execution->area !== FrontendWorkerExecutionContext::AREA_BACKEND
            || $execution->backendBinding === null
            || $execution->backendBinding->backendUserId <= 0) {
            $this->denyBackendLogin();
        }

        return $execution->backendBinding->backendUserId;
    }

    private function denyBackendLogin(): never
    {
        throw new FrontendQueryException(
            'auth_error',
            (string)__('请先登录后台'),
            401,
        );
    }
}
