<?php
declare(strict_types=1);

namespace WelineTools\Icon\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Session\Session;
use WelineTools\Icon\Service\IconProcessor;

class IconQueryProvider implements QueryProviderInterface
{
    private const UPLOAD_TICKETS_SESSION_KEY = 'icon_upload_tickets';
    private const UPLOAD_TICKET_TTL = 120;

    public function getProviderName(): string
    {
        return 'icon';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'uploadTicket' => $this->createUploadTicket(),
            'convert' => $this->convert($params),
            'compress' => $this->compress($params),
            default => throw new \InvalidArgumentException('Icon query provider does not support operation: ' . $operation),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'icon',
            'name' => __('Icon frontend worker API'),
            'description' => __('Frontend icon tool operations exposed through Weline worker API.'),
            'module' => 'WelineTools_Icon',
            'operations' => [
                $this->operation('uploadTicket', 'write', false, 2, 'Issue one-time icon upload ticket', []),
                $this->operation('convert', 'write', false, 4, 'Convert or resize icon image', [
                    'source_path' => ['type' => 'string', 'required' => true, 'max_length' => 1024],
                    'target_format' => ['type' => 'string', 'required' => true, 'max_length' => 16],
                    'width' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 8192],
                    'height' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 8192],
                    'quality' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 100],
                ]),
                $this->operation('compress', 'write', false, 4, 'Compress icon image', [
                    'source_path' => ['type' => 'string', 'required' => true, 'max_length' => 1024],
                    'quality' => ['type' => 'int', 'required' => true, 'min' => 1, 'max' => 100],
                ]),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function convert(array $params): array
    {
        try {
            $sourcePath = $this->resolvePublicMediaPath((string)($params['source_path'] ?? ''));
            $targetFormat = \strtolower((string)($params['target_format'] ?? 'ico'));
            $width = isset($params['width']) ? (int)$params['width'] : null;
            $height = isset($params['height']) ? (int)$params['height'] : null;
            $quality = (int)($params['quality'] ?? 90);

            $pathInfo = \pathinfo($sourcePath);
            $targetDir = $pathInfo['dirname'] . '/converted/';
            if (!\is_dir($targetDir)) {
                @\mkdir($targetDir, 0775, true);
            }

            $targetPath = $targetDir . $pathInfo['filename'] . '.' . $targetFormat;
            $result = $this->processor()->convert($sourcePath, $targetPath, $targetFormat, $width, $height, $quality);

            return $this->success([
                'url' => $this->publicUrl($targetPath),
                'path' => $targetPath,
                'size' => $result['size'] ?? (int)@\filesize($targetPath),
                'format' => $result['format'] ?? $targetFormat,
            ], 'Icon converted successfully.');
        } catch (\Throwable $exception) {
            return $this->failure($exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function compress(array $params): array
    {
        try {
            $sourcePath = $this->resolvePublicMediaPath((string)($params['source_path'] ?? ''));
            $quality = (int)($params['quality'] ?? 80);
            $pathInfo = \pathinfo($sourcePath);
            $targetDir = $pathInfo['dirname'] . '/compressed/';
            if (!\is_dir($targetDir)) {
                @\mkdir($targetDir, 0775, true);
            }

            $targetPath = $targetDir . 'compressed_' . $pathInfo['filename'] . '.' . $pathInfo['extension'];
            $result = $this->processor()->compress($sourcePath, $targetPath, $quality);
            $originalSize = (int)@\filesize($sourcePath);
            $compressedSize = (int)($result['size'] ?? @\filesize($targetPath));

            return $this->success([
                'url' => $this->publicUrl($targetPath),
                'path' => $targetPath,
                'original_size' => $originalSize,
                'compressed_size' => $compressedSize,
                'saved_percent' => $originalSize > 0 ? \round((1 - $compressedSize / $originalSize) * 100, 2) : 0,
            ], 'Icon compressed successfully.');
        } catch (\Throwable $exception) {
            return $this->failure($exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function createUploadTicket(): array
    {
        $token = \bin2hex(\random_bytes(24));
        $tickets = $this->getUploadTickets();
        $tickets[\hash('sha256', $token)] = \time() + self::UPLOAD_TICKET_TTL;
        $this->session()->setData(self::UPLOAD_TICKETS_SESSION_KEY, $tickets);

        return $this->success([
            'ticket' => $token,
            'method' => 'POST',
            'url' => $this->url()->getFrontendUrl('icon/icon/upload'),
            'expires_at' => \time() + self::UPLOAD_TICKET_TTL,
        ], 'Upload ticket issued.');
    }

    /**
     * @return array<string, int>
     */
    private function getUploadTickets(): array
    {
        $now = \time();
        $stored = $this->session()->getData(self::UPLOAD_TICKETS_SESSION_KEY);
        $tickets = \is_array($stored) ? $stored : [];

        return \array_filter($tickets, static fn(mixed $expiresAt): bool => \is_int($expiresAt) && $expiresAt > $now);
    }

    private function resolvePublicMediaPath(string $sourcePath): string
    {
        if (!\str_starts_with($sourcePath, '/media/icon/')) {
            throw new \InvalidArgumentException('Invalid icon media path.');
        }

        $root = \realpath(BP . 'pub/media/icon');
        $path = \realpath(BP . 'pub' . $sourcePath);
        if ($root === false || $path === false || !\str_starts_with($path, $root)) {
            throw new \InvalidArgumentException('Icon source file does not exist.');
        }

        return $path;
    }

    private function publicUrl(string $targetPath): string
    {
        $relativePath = \str_replace(BP . 'pub', '', $targetPath);
        return \str_replace('\\', '/', $relativePath);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function success(array $data, string $message): array
    {
        return [
            'success' => true,
            'error' => false,
            'code' => 200,
            'msg' => $message,
            'message' => $message,
            'data' => $data,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(string $message): array
    {
        return [
            'success' => false,
            'error' => true,
            'code' => 500,
            'msg' => $message,
            'message' => $message,
            'data' => null,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $params
     * @return array<string, mixed>
     */
    private function operation(string $name, string $mode, bool $graph, int $cost, string $summary, array $params): array
    {
        return [
            'name' => $name,
            'description' => __($summary),
            'frontend' => true,
            'mode' => $mode,
            'graph' => $graph,
            'cost' => $cost,
            'params' => $params,
            'returns' => ['type' => 'array'],
            'summary' => $summary,
        ];
    }

    private function processor(): IconProcessor
    {
        return ObjectManager::getInstance(IconProcessor::class);
    }

    private function session(): Session
    {
        return ObjectManager::getInstance(Session::class);
    }

    private function url(): Url
    {
        return ObjectManager::getInstance(Url::class);
    }
}
