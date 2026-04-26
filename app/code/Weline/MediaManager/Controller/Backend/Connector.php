<?php

declare(strict_types=1);

namespace Weline\MediaManager\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\DownloadException;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\MediaManager\Helper\MimeTypes;
use Weline\MediaManager\Service\ConnectorOptionsBuilder;
use Weline\MediaManager\Service\ConnectorService;

class Connector extends BackendController
{
    private ConnectorOptionsBuilder $optionsBuilder;
    private ConnectorService $connectorService;

    public function __construct(
        ConnectorOptionsBuilder $optionsBuilder,
        ConnectorService $connectorService
    ) {
        $this->optionsBuilder = $optionsBuilder;
        $this->connectorService = $connectorService;
    }

    /**
     * GET/POST 统一入口
     */
    public function index()
    {
        $ext = $this->request->getParam('ext') ?? '';
        $mimes = MimeTypes::collectMimes($ext);
        $rootPath = PUB . 'media';
        $rootUrl = '/pub/media';
        $startPath = $this->request->getParam('startPath');
        $local = Cookie::getLangLocal();

        $opts = $this->optionsBuilder->build($rootPath, $rootUrl, $mimes, $startPath, $local);
        $result = $this->connectorService->execute($this->request, $opts);

        if (!empty($result['__abort'])) {
            throw new ResponseTerminateException(204);
        }

        if (isset($result['pointer'])) {
            return $this->handlePointerResponse($result);
        }

        return $this->handleJsonResponse($result);
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

        $json = !empty($result['raw']) && isset($result['error'])
            ? $result['error']
            : \json_encode($result);

        $this->request->getResponse()->setHeader('Content-Type', $contentType);
        $this->request->getResponse()->setHeader('Content-Length', (string) \strlen($json));
        $this->request->getResponse()->setHeader('Connection', 'close');
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

        $contentType = 'application/octet-stream';
        $cacheControl = '';
        if (!empty($result['header'])) {
            $headers = \is_array($result['header']) ? $result['header'] : [$result['header']];
            foreach ($headers as $h) {
                if (\str_starts_with($h, 'Content-Type:')) {
                    $contentType = \trim(\substr($h, 13));
                } elseif (\str_starts_with($h, 'Cache-Control:')) {
                    $cacheControl = \trim(\substr($h, 14));
                }
            }
        }

        $tmpFile = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mmf_' . \uniqid('', true) . '.tmp';
        $tmpFp = @\fopen($tmpFile, 'wb');
        if ($tmpFp === false) {
            if ($volume && !empty($info['hash'])) {
                $volume->close($fp, $info['hash']);
            } else {
                @\fclose($fp);
            }
            throw new ResponseTerminateException(500, \json_encode(['error' => 'Cannot create temp file']), [
                'Content-Type' => 'application/json; charset=utf-8',
            ]);
        }

        while (!\feof($fp)) {
            $chunk = \fread($fp, 64 * 1024);
            if ($chunk === false) {
                break;
            }
            \fwrite($tmpFp, $chunk);
        }
        \fclose($tmpFp);

        if ($volume && !empty($info['hash'])) {
            $volume->close($fp, $info['hash']);
        } else {
            @\fclose($fp);
        }

        $fileName = $info['name'] ?? \basename($tmpFile);

        if (\str_starts_with($contentType, 'image/')) {
            $this->handleInlineImageResponse($tmpFile, $contentType, $cacheControl);
        }

        throw new DownloadException($tmpFile, $fileName, true);
    }

    /**
     * 图片内联响应（直接显示图片，不触发下载）
     */
    private function handleInlineImageResponse(string $tmpFile, string $contentType, string $cacheControl = ''): void
    {
        $fileSize = @\filesize($tmpFile) ?: 0;
        $headers = [
            'Content-Type' => $contentType,
            'Content-Length' => (string) $fileSize,
            'Content-Disposition' => 'inline',
            'Connection' => 'close',
        ];
        if ($cacheControl) {
            $headers['Cache-Control'] = $cacheControl;
        }

        $content = @\file_get_contents($tmpFile);
        @\unlink($tmpFile);

        if ($content === false) {
            throw new ResponseTerminateException(500, \json_encode(['error' => 'Cannot read temp file']), [
                'Content-Type' => 'application/json; charset=utf-8',
            ]);
        }

        throw new ResponseTerminateException(200, $content, $headers);
    }
}
