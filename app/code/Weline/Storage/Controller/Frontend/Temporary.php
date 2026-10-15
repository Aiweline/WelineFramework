<?php

declare(strict_types=1);

namespace Weline\Storage\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Cache\SharedResponseCachePolicy;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Storage\Api\StorageManagerInterface;
use Weline\Storage\Service\LocalTemporaryUrlSigner;

/**
 * Serves HMAC-signed local temporary URLs for private FileAssets.
 *
 * @Extra type=fpc enabled=false
 */
final class Temporary extends FrontendController
{
    private const MAX_INLINE_BYTES = 32 * 1024 * 1024;

    public function __construct(
        private readonly StorageManagerInterface $storage,
        private readonly LocalTemporaryUrlSigner $signer,
    ) {
    }

    public function index(): string
    {
        SharedResponseCachePolicy::forbid('local_storage_temporary_url');

        $encodedDisk = trim((string)$this->request->getParam('disk', ''));
        $encodedKey = trim((string)$this->request->getParam('key', ''));
        $expiresAt = (int)$this->request->getParam('e', 0);
        $configRevision = (int)$this->request->getParam('r', 0);
        $signature = strtolower(trim((string)$this->request->getParam('sig', '')));

        try {
            $diskCode = $this->signer->decodeDiskCode($encodedDisk);
            $objectKey = $this->signer->decodeObjectKey($encodedKey);
        } catch (\Throwable) {
            throw new ResponseTerminateException(403, (string)__('临时下载链接无效或已过期。'), [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        if (!$this->signer->verify($diskCode, $objectKey, $expiresAt, $configRevision, $signature)) {
            throw new ResponseTerminateException(403, (string)__('临时下载链接无效或已过期。'), [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        try {
            $disk = $this->storage->disk($diskCode);
            if ($disk->snapshot()->configRevision !== $configRevision) {
                throw new ResponseTerminateException(403, (string)__('临时下载链接无效或已过期。'), [
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'Cache-Control' => 'private, no-store',
                ]);
            }
            if (!$disk->exists($objectKey)) {
                throw new ResponseTerminateException(404, (string)__('下载文件不存在。'), [
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'Cache-Control' => 'private, no-store',
                ]);
            }
            $stat = $disk->stat($objectKey);
            if ($stat->bytes > self::MAX_INLINE_BYTES) {
                throw new ResponseTerminateException(413, (string)__('下载文件过大。'), [
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'Cache-Control' => 'private, no-store',
                ]);
            }
            $handle = $disk->openRead($objectKey);
        } catch (ResponseTerminateException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new ResponseTerminateException(404, (string)__('下载文件不存在。'), [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        $body = '';
        try {
            while (!$handle->eof()) {
                $chunk = $handle->read(1024 * 1024);
                if ($chunk === '') {
                    break;
                }
                $body .= $chunk;
                if (strlen($body) > self::MAX_INLINE_BYTES) {
                    throw new \RuntimeException('inline limit');
                }
            }
        } catch (\Throwable) {
            try {
                $handle->close();
            } catch (\Throwable) {
            }
            throw new ResponseTerminateException(500, (string)__('下载文件读取失败。'), [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'private, no-store',
            ]);
        }
        try {
            $handle->close();
        } catch (\Throwable) {
            throw new ResponseTerminateException(500, (string)__('下载文件读取失败。'), [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        if ($stat->bytes > 0 && strlen($body) !== $stat->bytes) {
            throw new ResponseTerminateException(500, (string)__('下载文件读取失败。'), [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        $fileName = basename(str_replace('\\', '/', $objectKey));
        if ($fileName === '' || $fileName === '.' || $fileName === '..') {
            $fileName = 'download';
        }
        // Force octet-stream + attachment so frontend HTML pipelines (CSP title
        // injectors, etc.) cannot mutate private download bytes.
        $mime = 'application/octet-stream';

        throw new ResponseTerminateException(200, $body, [
            'Content-Type' => $mime,
            'Content-Length' => (string)strlen($body),
            'Content-Disposition' => 'attachment; filename="' . addcslashes($fileName, '"\\') . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }
}
