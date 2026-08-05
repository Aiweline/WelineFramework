<?php

declare(strict_types=1);

namespace Weline\MediaManager\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Resumable\ResumableTaskAccessDeniedException;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Service\Runtime\ResumableTaskOwnerResolver;
use Weline\MediaManager\Helper\MimeTypes;
use Weline\MediaManager\Service\AiDrawService;
use Weline\MediaManager\Service\CollectingSseWriter;
use Weline\MediaManager\Service\ConnectorOptionsBuilder;
use Weline\MediaManager\Service\ConnectorService;
use Weline\MediaManager\Service\MediaUploadBase64Hydrator;
use Weline\Framework\Http\Cookie;

/**
 * Browser-facing MediaManager operations that need the authenticated backend
 * session.  AI generation itself is intentionally absent: it is started only
 * through runtime_task.start and executes in the detached Runner.
 */
final class MediaManagerQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly AiDrawService $aiDrawService,
        private readonly ResumableTaskOwnerResolver $ownerResolver,
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
                        ['name' => 'target', 'type' => 'string', 'required' => false, 'max_length' => 512],
                        ['name' => 'source_file_hash', 'type' => 'string', 'required' => false, 'max_length' => 512],
                        ['name' => 'filename', 'type' => 'string', 'required' => false, 'max_length' => 512],
                        ['name' => 'generation_id', 'type' => 'string', 'required' => false, 'max_length' => 64],
                        ['name' => 'generation_ids', 'type' => 'array', 'required' => false],
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
                        ['name' => 'target', 'type' => 'string', 'required' => false, 'max_length' => 512],
                        ['name' => 'targets', 'type' => 'array', 'required' => false],
                        ['name' => 'name', 'type' => 'string', 'required' => false, 'max_length' => 512],
                        ['name' => 'ext', 'type' => 'string', 'required' => false, 'max_length' => 128],
                        ['name' => 'startPath', 'type' => 'string', 'required' => false, 'max_length' => 1024],
                        // FE open(init) sends path=START_PATH; ConnectorService.handleOpen reads src.path.
                        ['name' => 'path', 'type' => 'string', 'required' => false, 'max_length' => 1024],
                        ['name' => 'storage', 'type' => 'string', 'required' => false, 'max_length' => 128],
                        ['name' => 'init', 'type' => 'string', 'required' => false, 'max_length' => 16],
                        ['name' => 'tree', 'type' => 'string', 'required' => false, 'max_length' => 16],
                        ['name' => 'reload', 'type' => 'string', 'required' => false, 'max_length' => 16],
                        ['name' => 'upload_base64', 'type' => 'array', 'required' => false],
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
                        ['name' => 'target', 'type' => 'string', 'required' => false, 'max_length' => 512],
                        ['name' => 'model', 'type' => 'string', 'required' => false, 'max_length' => 128],
                        ['name' => 'width', 'type' => 'int', 'required' => false],
                        ['name' => 'height', 'type' => 'int', 'required' => false],
                        ['name' => 'size', 'type' => 'string', 'required' => false, 'max_length' => 32],
                        ['name' => 'output_format', 'type' => 'string', 'required' => false, 'max_length' => 16],
                        ['name' => 'aspect_ratio', 'type' => 'string', 'required' => false, 'max_length' => 16],
                        ['name' => 'negative_prompt', 'type' => 'string', 'required' => false, 'max_length' => 4000],
                        ['name' => 'source_file_hash', 'type' => 'string', 'required' => false, 'max_length' => 512],
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
        $this->backendAdminId();
        return $this->aiDrawService->getConfigStatus();
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<string,mixed>
     */
    private function polishPrompt(array $params): array
    {
        $this->backendAdminId();
        return $this->aiDrawService->polishPrompt((string)($params['prompt'] ?? ''));
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<string,mixed>
     */
    private function save(array $params): array
    {
        return $this->aiDrawService->save($this->backendAdminId(), $params);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function connector(array $params): array
    {
        $this->backendAdminId();
        /** @var \Weline\Framework\Http\Request $request */
        $request = ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
        /** @var MediaUploadBase64Hydrator $hydrator */
        $hydrator = ObjectManager::getInstance(MediaUploadBase64Hydrator::class);
        $tmpFiles = $hydrator->hydrate($params);
        try {
            foreach ($params as $key => $value) {
                if ($key === '_files' || $key === 'upload_base64') {
                    continue;
                }
                $request->setGet((string)$key, $value);
                $request->setPost((string)$key, $value);
            }
            $request->setData('params', $params);
            if ($tmpFiles !== []) {
                $request->setServer('REQUEST_METHOD', 'POST');
            }

            /** @var ConnectorOptionsBuilder $optionsBuilder */
            $optionsBuilder = ObjectManager::getInstance(ConnectorOptionsBuilder::class);
            /** @var ConnectorService $connectorService */
            $connectorService = ObjectManager::getInstance(ConnectorService::class);

            $ext = $params['ext'] ?? '';
            $mimes = MimeTypes::collectMimes($ext);
            $rootPath = \rtrim(PUB, '/\\') . \DIRECTORY_SEPARATOR . 'media' . \DIRECTORY_SEPARATOR;
            $rootUrl = '/pub/media';
            $startPath = $params['startPath'] ?? null;
            $local = Cookie::getLangLocal();
            $opts = $optionsBuilder->build($rootPath, $rootUrl, $mimes, $startPath, $local);
            $result = $connectorService->execute($request, $opts);
            if (!\is_array($result)) {
                return ['success' => true, 'data' => $result];
            }
            unset($result['header']);
            return $result;
        } finally {
            $hydrator->cleanup($tmpFiles);
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
        $adminId = $this->backendAdminId();
        $collector = new CollectingSseWriter();
        try {
            $this->aiDrawService->streamGenerate($collector, $adminId, $params);
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

    private function backendAdminId(): int
    {
        $owner = $this->ownerResolver->resolve();
        if ($owner->area !== 'backend'
            || \preg_match('/^backend:([1-9][0-9]*)$/', $owner->principal, $matches) !== 1) {
            throw new ResumableTaskAccessDeniedException('Media AI draw requires an authenticated backend owner.');
        }

        return (int)$matches[1];
    }
}
