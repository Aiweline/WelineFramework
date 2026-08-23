<?php

declare(strict_types=1);

namespace Weline\MediaManager\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\DownloadException;
use Weline\Framework\Http\RedirectException;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Http\Sse\SseContext;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\Ui\FormKey;
use Weline\MediaManager\Helper\MimeTypes;
use Weline\MediaManager\Service\ConnectorService;
use Weline\MediaManager\Service\MediaAssetUploadService;
use Weline\MediaManager\Service\MediaResumableUploadService;
use Weline\Storage\Api\Runtime\StorageRequestResourceFactoryInterface;
use Weline\Storage\Api\Runtime\StorageRequestStreamInterface;
use Weline\Storage\Api\StorageReadHandle;

#[Acl('Weline_MediaManager::file_manager', '媒体管理器接口', 'image', '浏览、上传并维护文件资源', 'Weline_Backend::media_group')]
class Connector extends BackendController
{
    private const MUTATING_COMMANDS = [
        'mkdir',
        'rename',
        'move',
        'rm',
        'upload',
        'asset_metadata',
        'upload_session_start',
        'upload_session_chunk',
        'upload_session_complete',
        'upload_session_abort',
    ];
    private const MAX_PROXY_DOWNLOAD_BYTES = 100 * 1024 * 1024;

    public function __construct(
        private readonly ConnectorService $connectorService,
        private readonly StorageRequestResourceFactoryInterface $resourceFactory,
        private readonly MediaResumableUploadService $resumableUploads,
    ) {
    }

    protected function csrf(): string
    {
        return FormKey::key_name;
    }

    /**
     * GET/POST 统一入口
     */
    public function index()
    {
        $command = strtolower(trim((string)($this->request->getParam('cmd') ?? 'open')));
        if (in_array($command, self::MUTATING_COMMANDS, true) && !$this->request->isPost()) {
            throw new ResponseTerminateException(405, (string)json_encode(
                ['error' => (string)__('写操作仅允许使用 POST 请求。')],
                JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ), [
                'Allow' => 'POST',
                'Content-Type' => 'application/json; charset=utf-8',
            ]);
        }
        $ext = (string)($this->request->getParam('ext') ?? '');
        $mimes = MimeTypes::collectMimes($ext);
        $limitBytes = max(1, min(
            MediaAssetUploadService::MAX_UPLOAD_BYTES,
            (int)$this->request->getParam('size', MediaAssetUploadService::MAX_UPLOAD_BYTES),
        ));
        $params = $this->request->getParams();
        $params = is_array($params) ? $params : [];
        if (trim((string)($params['locale_code'] ?? '')) === '') {
            // Cookie access is confined to the HTTP assembly boundary. The
            // connector receives an explicit immutable locale value and never
            // reads process-global request state by itself.
            $params['locale_code'] = Cookie::getLangLocal();
        }
        if (str_starts_with($command, 'upload_session_')) {
            return $this->handleJsonResponse($this->handleResumableUpload(
                $command,
                $params,
                $mimes,
                MimeTypes::collectExtensions($ext),
            ));
        }
        $files = $this->request->getFile('upload');
        $result = $this->connectorService->execute($params, [
            'allowed_mimes' => $mimes,
            'allowed_extensions' => MimeTypes::collectExtensions((string)$ext),
            'max_upload_bytes' => $limitBytes,
            'locale' => (string)$params['locale_code'],
            'actor_id' => max(0, (int)($this->session->getUserId() ?? 0)),
        ], is_array($files) ? $files : []);

        if (!empty($result['__abort'])) {
            throw new ResponseTerminateException(204);
        }

        if (isset($result['redirect_url']) && is_string($result['redirect_url'])) {
            throw new RedirectException($result['redirect_url'], 302);
        }

        if (isset($result['pointer'])) {
            return $this->handlePointerResponse($result);
        }

        return $this->handleJsonResponse($result);
    }

    /**
     * Each chunk is a separate bounded request. Durable session manifests hold
     * data only; no Request, Session, stream or SDK object crosses WLS requests.
     *
     * @param array<string,mixed> $params
     * @param list<string> $allowedMimes
     * @param list<string> $allowedExtensions
     * @return array<string,mixed>
     */
    private function handleResumableUpload(
        string $command,
        array $params,
        array $allowedMimes,
        array $allowedExtensions,
    ): array {
        $actorId = max(0, (int)($this->session->getUserId() ?? 0));
        if ($actorId < 1) {
            return ['error' => (string)__('分块上传需要已登录的后台操作人。')];
        }
        $requestedMax = filter_var(
            $params['size'] ?? MediaAssetUploadService::MAX_ASSET_UPLOAD_BYTES,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        $requestedMax = $requestedMax === false
            ? MediaAssetUploadService::MAX_ASSET_UPLOAD_BYTES
            : min(MediaAssetUploadService::MAX_ASSET_UPLOAD_BYTES, $requestedMax);
        $chunk = $command === 'upload_session_chunk' ? $this->request->getFile('chunk') : [];

        try {
            return match ($command) {
                'upload_session_start' => ['upload_session' => $this->resumableUploads->start(
                    $params,
                    $actorId,
                    $allowedMimes,
                    $allowedExtensions,
                    $requestedMax,
                )],
                'upload_session_chunk' => ['upload_session' => $this->resumableUploads->appendChunk(
                    (string)($params['session_id'] ?? ''),
                    $actorId,
                    is_int($params['offset'] ?? null)
                        ? $params['offset']
                        : (string)($params['offset'] ?? ''),
                    (string)($params['chunk_sha256'] ?? ''),
                    is_array($chunk) ? $chunk : [],
                )],
                'upload_session_complete' => ['added' => [$this->resumableUploads->complete(
                    (string)($params['session_id'] ?? ''),
                    $actorId,
                )]],
                'upload_session_abort' => $this->abortResumableUpload(
                    (string)($params['session_id'] ?? ''),
                    $actorId,
                ),
                default => ['error' => (string)__('分块上传命令无效。')],
            };
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            return ['error' => $exception->getMessage()];
        } catch (\Throwable) {
            return ['error' => (string)__('分块上传操作失败。')];
        }
    }

    /** @return array{aborted:true} */
    private function abortResumableUpload(string $sessionId, int $actorId): array
    {
        $this->resumableUploads->abort($sessionId, $actorId);
        return ['aborted' => true];
    }

    /**
     * JSON 响应分支
     */
    private function handleJsonResponse(array $result): string
    {
        $header = $result['header'] ?? null;
        unset($result['header']);

        $contentType = 'application/json; charset=utf-8';
        if ($header) {
            if (\is_string($header) && \str_starts_with($header, 'Content-Type:')) {
                $contentType = \trim(\substr($header, 13));
            }
        }

        if (!empty($result['raw']) && isset($result['error'])) {
            $json = (string)$result['error'];
        } else {
            try {
                $json = \json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                );
            } catch (\JsonException) {
                $json = (string)\json_encode(
                    ['error' => (string)__('连接器响应编码失败。')],
                    JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                );
            }
        }

        $this->request->getResponse()->setHeader('Content-Type', $contentType);
        $this->request->getResponse()->setHeader('Content-Length', (string) \strlen($json));
        return $json;
    }

    /**
     * 文件流响应分支（下载/预览），通过临时文件 + DownloadException 实现 WLS 兼容
     */
    private function handlePointerResponse(array $result): void
    {
        $fp = $result['pointer'];
        $info = $result['info'] ?? [];
        $volume = $result['volume'] ?? null;
        $resourceHandle = $result['resource_handle'] ?? null;
        $forceDownload = !empty($result['force_download']);

        if (!\is_resource($fp)) {
            $this->closePointer($fp, $volume, \is_array($info) ? $info : [], $resourceHandle);
            throw new ResponseTerminateException(500, (string)\json_encode(
                ['error' => (string)__('下载源流无效。')],
                JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ), ['Content-Type' => 'application/json; charset=utf-8']);
        }
        $info = \is_array($info) ? $info : [];

        $contentType = 'application/octet-stream';
        $cacheControl = '';
        if (!empty($result['header'])) {
            $headers = \is_array($result['header']) ? $result['header'] : [$result['header']];
            foreach ($headers as $h) {
                if (!\is_scalar($h)) {
                    continue;
                }
                $h = (string)$h;
                if (\str_starts_with($h, 'Content-Type:')) {
                    $contentType = \trim(\substr($h, 13));
                } elseif (\str_starts_with($h, 'Cache-Control:')) {
                    $cacheControl = \trim(\substr($h, 14));
                }
            }
        }
        if ($contentType === ''
            || \strlen($contentType) > 255
            || !\str_contains($contentType, '/')
            || \preg_match('/[\x00-\x1F\x7F]/', $contentType) === 1
        ) {
            $contentType = 'application/octet-stream';
        }
        if (\strlen($cacheControl) > 512 || \preg_match('/[\x00-\x1F\x7F]/', $cacheControl) === 1) {
            $cacheControl = '';
        }

        $declaredSize = max(0, (int)($info['size'] ?? 0));
        if ($declaredSize > self::MAX_PROXY_DOWNLOAD_BYTES) {
            $this->closePointer($fp, $volume, $info, $resourceHandle);
            throw new ResponseTerminateException(413, (string)\json_encode(
                ['error' => (string)__('代理下载文件超过大小限制。')],
                JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ), ['Content-Type' => 'application/json; charset=utf-8']);
        }

        $source = $this->resourceFactory->stream(
            $fp,
            StorageRequestStreamInterface::KIND_PROXY_FILE,
            fn (mixed $stream) => $this->closePointer($stream, $volume, $info, $resourceHandle),
        );
        try {
            $temporary = $this->resourceFactory->temporaryFile(\sys_get_temp_dir(), 'mmf_');
        } catch (\Throwable $temporaryFailure) {
            try {
                $source->close();
            } catch (\Throwable $cleanupFailure) {
                throw $cleanupFailure;
            }
            throw $temporaryFailure;
        }
        $tmpFile = $temporary->path();
        $tmpFp = @\fopen($tmpFile, 'wb');
        if ($tmpFp === false) {
            $cleanupFailure = null;
            try {
                $temporary->close();
            } catch (\Throwable $throwable) {
                $cleanupFailure = $throwable;
            }
            try {
                $source->close();
            } catch (\Throwable $throwable) {
                $cleanupFailure ??= $throwable;
            }
            if ($cleanupFailure !== null) {
                throw $cleanupFailure;
            }
            throw new ResponseTerminateException(500, (string)\json_encode(
                ['error' => (string)__('无法创建下载临时文件。')],
                JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ), [
                'Content-Type' => 'application/json; charset=utf-8',
            ]);
        }
        try {
            $target = $this->resourceFactory->stream($tmpFp, StorageRequestStreamInterface::KIND_PROXY_FILE);
        } catch (\Throwable $registrationFailure) {
            $cleanupFailure = null;
            if (\is_resource($tmpFp) && !@\fclose($tmpFp)) {
                $cleanupFailure = new \RuntimeException((string)__('无法关闭未登记的下载临时文件流。'));
            }
            try {
                $temporary->close();
            } catch (\Throwable $throwable) {
                $cleanupFailure ??= $throwable;
            }
            try {
                $source->close();
            } catch (\Throwable $throwable) {
                $cleanupFailure ??= $throwable;
            }
            if ($cleanupFailure !== null) {
                throw $cleanupFailure;
            }
            throw $registrationFailure;
        }

        $copyFailed = false;
        $copiedBytes = 0;
        $emptyReads = 0;
        try {
            while (!\feof($source->stream())) {
                if (\connection_aborted()
                    || (\is_callable(SseContext::getAliveCallback()) && !SseContext::isConnectionAlive())
                ) {
                    $copyFailed = true;
                    break;
                }
                try {
                    $chunk = $resourceHandle instanceof StorageReadHandle
                        ? $resourceHandle->read(64 * 1024)
                        : \fread($source->stream(), 64 * 1024);
                } catch (\Throwable) {
                    $copyFailed = true;
                    break;
                }
                if ($chunk === false) {
                    $copyFailed = true;
                    break;
                }
                if ($chunk === '') {
                    if (\feof($source->stream())) {
                        break;
                    }
                    if (++$emptyReads >= 3) {
                        $copyFailed = true;
                        break;
                    }
                    SchedulerSystem::yield();
                    continue;
                }
                $emptyReads = 0;
                $offset = 0;
                $chunkBytes = \strlen($chunk);
                $copiedBytes += $chunkBytes;
                if ($copiedBytes > self::MAX_PROXY_DOWNLOAD_BYTES) {
                    $copyFailed = true;
                    break;
                }
                while ($offset < $chunkBytes) {
                    $written = \fwrite($target->stream(), \substr($chunk, $offset));
                    if ($written === false || $written === 0) {
                        $copyFailed = true;
                        break;
                    }
                    $offset += $written;
                }
                if ($copyFailed) {
                    break;
                }
                SchedulerSystem::yield();
            }
        } catch (\Throwable) {
            $copyFailed = true;
        } finally {
            try {
                $target->close();
            } catch (\Throwable) {
                $copyFailed = true;
            }
            try {
                $source->close();
            } catch (\Throwable) {
                $copyFailed = true;
            }
        }
        if (!$copyFailed && $declaredSize > 0 && $copiedBytes !== $declaredSize) {
            $copyFailed = true;
        }
        if ($copyFailed) {
            $temporary->close();
            throw new ResponseTerminateException(500, (string)\json_encode(
                ['error' => (string)__('下载临时文件写入不完整。')],
                JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ), ['Content-Type' => 'application/json; charset=utf-8']);
        }

        $fileName = (string)($info['name'] ?? \basename($tmpFile));
        if (preg_match('//u', $fileName) !== 1) {
            $fileName = \basename($tmpFile);
        }
        $fileName = preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/u', '_', $fileName) ?: \basename($tmpFile);
        $fileName = trim($fileName, " .\t");
        if ($fileName === '') {
            $fileName = \basename($tmpFile);
        }

        $download = new DownloadException($temporary->detach(), $fileName, true);
        $download->setHeader('Content-Type', $contentType);
        $download->setHeader('X-Content-Type-Options', 'nosniff');
        if ($forceDownload) {
            $download->setHeader('Content-Disposition', 'attachment; filename="' . addcslashes($fileName, '"\\') . '"');
        } elseif (\str_starts_with($contentType, 'image/')) {
            $download->setHeader('Content-Disposition', 'inline; filename="' . addcslashes($fileName, '"\\') . '"');
        }
        if ($cacheControl !== '') {
            $download->setHeader('Cache-Control', $cacheControl);
        }
        throw $download;
    }

    /** @param array<string,mixed> $info */
    private function closePointer(
        mixed $pointer,
        mixed $volume,
        array $info,
        mixed $resourceHandle = null,
    ): void
    {
        if ($resourceHandle instanceof StorageReadHandle) {
            $resourceHandle->close();
            return;
        }
        if ($volume && !empty($info['hash'])) {
            $volume->close($pointer, $info['hash']);
            return;
        }
        if (\is_resource($pointer)) {
            \fclose($pointer);
        }
    }
}
