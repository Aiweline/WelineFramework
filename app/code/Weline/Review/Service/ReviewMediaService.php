<?php

declare(strict_types=1);

namespace Weline\Review\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Review\Model\ReviewMedia;

final class ReviewMediaService
{
    private const IMAGE_MAX_BYTES = 10 * 1024 * 1024;
    private const VIDEO_MAX_BYTES = 50 * 1024 * 1024;
    private const MAX_IMAGES = 6;
    private const MAX_VIDEOS = 2;

    private const ALLOWED_MIME = [
        'image/jpeg' => ['kind' => 'image', 'extension' => 'jpg'],
        'image/png' => ['kind' => 'image', 'extension' => 'png'],
        'image/webp' => ['kind' => 'image', 'extension' => 'webp'],
        'image/gif' => ['kind' => 'image', 'extension' => 'gif'],
        'video/mp4' => ['kind' => 'video', 'extension' => 'mp4'],
        'video/webm' => ['kind' => 'video', 'extension' => 'webm'],
        'video/quicktime' => ['kind' => 'video', 'extension' => 'mov'],
    ];

    /** @return array<string,mixed> */
    public function stage(string $typeCode, string $entityUuid, string $mediaKind, array $upload): array
    {
        $typeCode = strtolower(trim($typeCode));
        $entityUuid = trim($entityUuid);
        $mediaKind = strtolower(trim($mediaKind));
        $data = (string)($upload['data'] ?? '');
        $originalName = $this->plainFilename((string)($upload['name'] ?? 'upload'));
        if ($typeCode === '' || $entityUuid === '' || !in_array($mediaKind, ['image', 'video'], true)) {
            throw new \InvalidArgumentException((string)__('评论媒体参数无效。'));
        }
        if ($data === '') {
            throw new \InvalidArgumentException((string)__('请选择要上传的图片或视频。'));
        }
        if (str_contains($data, ',')) {
            $data = (string)substr($data, strpos($data, ',') + 1);
        }
        $bytes = base64_decode($data, true);
        if ($bytes === false || $bytes === '') {
            throw new \InvalidArgumentException((string)__('媒体文件内容无效。'));
        }
        $maxBytes = $mediaKind === 'image' ? self::IMAGE_MAX_BYTES : self::VIDEO_MAX_BYTES;
        if (strlen($bytes) > $maxBytes) {
            throw new \InvalidArgumentException($mediaKind === 'image'
                ? (string)__('单张图片不能超过 10 MB。')
                : (string)__('单个视频不能超过 50 MB。'));
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->buffer($bytes);
        $definition = self::ALLOWED_MIME[$mime] ?? null;
        if (!is_array($definition) || $definition['kind'] !== $mediaKind) {
            throw new \InvalidArgumentException((string)__('仅支持 JPG、PNG、WEBP、GIF 图片以及 MP4、WEBM、MOV 视频。'));
        }

        $relativeDirectory = 'review/' . $typeCode . '/' . date('Y/m');
        $absoluteDirectory = rtrim(PUB, '/\\') . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
        if (!is_dir($absoluteDirectory) && !@mkdir($absoluteDirectory, 0755, true) && !is_dir($absoluteDirectory)) {
            throw new \RuntimeException((string)__('评论媒体目录无法写入。'));
        }
        $filename = bin2hex(random_bytes(20)) . '.' . $definition['extension'];
        $absolutePath = $absoluteDirectory . DIRECTORY_SEPARATOR . $filename;
        if (@file_put_contents($absolutePath, $bytes, LOCK_EX) === false) {
            throw new \RuntimeException((string)__('评论媒体保存失败。'));
        }

        $relativePath = $relativeDirectory . '/' . $filename;
        $token = bin2hex(random_bytes(32));
        try {
            /** @var ReviewMedia $media */
            $media = ObjectManager::getInstance(ReviewMedia::class);
            $now = date('Y-m-d H:i:s');
            $media->clear()->setData([
                ReviewMedia::schema_fields_REVIEW_ID => null,
                ReviewMedia::schema_fields_UPLOAD_TOKEN => $token,
                ReviewMedia::schema_fields_TYPE_CODE => $typeCode,
                ReviewMedia::schema_fields_ENTITY_UUID => $entityUuid,
                ReviewMedia::schema_fields_MEDIA_KIND => $mediaKind,
                ReviewMedia::schema_fields_PATH => $relativePath,
                ReviewMedia::schema_fields_MIME_TYPE => $mime,
                ReviewMedia::schema_fields_ORIGINAL_NAME => $originalName,
                ReviewMedia::schema_fields_SIZE => strlen($bytes),
                ReviewMedia::schema_fields_STATUS => ReviewMedia::STATUS_STAGED,
                ReviewMedia::schema_fields_CREATED_AT => $now,
                ReviewMedia::schema_fields_EXPIRES_AT => date('Y-m-d H:i:s', time() + 86400),
            ])->save();
        } catch (\Throwable $exception) {
            @unlink($absolutePath);
            throw $exception;
        }

        return [
            'token' => $token,
            'kind' => $mediaKind,
            'url' => '/media/' . $this->encodePath($relativePath),
            'mime_type' => $mime,
            'name' => $originalName,
            'size' => strlen($bytes),
        ];
    }

    /** @param list<string> $tokens @return list<array<string,mixed>> */
    public function assertAttachable(string $typeCode, string $entityUuid, array $tokens): array
    {
        $tokens = array_values(array_unique(array_filter(array_map('strval', $tokens))));
        if (count($tokens) > self::MAX_IMAGES + self::MAX_VIDEOS) {
            throw new \InvalidArgumentException((string)__('评论媒体文件数量超过限制。'));
        }
        $rows = [];
        $counts = ['image' => 0, 'video' => 0];
        foreach ($tokens as $token) {
            if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
                throw new \InvalidArgumentException((string)__('评论媒体票据无效。'));
            }
            /** @var ReviewMedia $media */
            $media = ObjectManager::getInstance(ReviewMedia::class);
            $row = $media->clear()->where(ReviewMedia::schema_fields_UPLOAD_TOKEN, $token)->select()->fetchArray()[0] ?? null;
            if (!is_array($row)
                || (string)($row[ReviewMedia::schema_fields_STATUS] ?? '') !== ReviewMedia::STATUS_STAGED
                || (string)($row[ReviewMedia::schema_fields_TYPE_CODE] ?? '') !== $typeCode
                || (string)($row[ReviewMedia::schema_fields_ENTITY_UUID] ?? '') !== $entityUuid
                || strtotime((string)($row[ReviewMedia::schema_fields_EXPIRES_AT] ?? '')) < time()) {
                throw new \InvalidArgumentException((string)__('评论媒体票据已失效，请重新选择文件。'));
            }
            $kind = (string)$row[ReviewMedia::schema_fields_MEDIA_KIND];
            ++$counts[$kind];
            $rows[] = $row;
        }
        if ($counts['image'] > self::MAX_IMAGES || $counts['video'] > self::MAX_VIDEOS) {
            throw new \InvalidArgumentException((string)__('最多上传 6 张图片和 2 个视频。'));
        }
        return $rows;
    }

    /** @param list<array<string,mixed>> $rows */
    public function attach(int $reviewId, array $rows): void
    {
        foreach ($rows as $row) {
            $mediaId = (int)($row[ReviewMedia::schema_fields_ID] ?? 0);
            if ($mediaId <= 0) {
                continue;
            }
            /** @var ReviewMedia $media */
            $media = ObjectManager::getInstance(ReviewMedia::class);
            $media->clear()->load($mediaId);
            if ((string)$media->getData(ReviewMedia::schema_fields_STATUS) !== ReviewMedia::STATUS_STAGED) {
                throw new \RuntimeException((string)__('评论媒体状态已变化，请重新提交。'));
            }
            $media->setData(ReviewMedia::schema_fields_REVIEW_ID, $reviewId)
                ->setData(ReviewMedia::schema_fields_STATUS, ReviewMedia::STATUS_ATTACHED)
                ->save();
        }
    }

    /** @return list<array<string,mixed>> */
    public function forReview(int $reviewId): array
    {
        if ($reviewId <= 0) {
            return [];
        }
        /** @var ReviewMedia $media */
        $media = ObjectManager::getInstance(ReviewMedia::class);
        $rows = $media->clear()
            ->where(ReviewMedia::schema_fields_REVIEW_ID, $reviewId)
            ->where(ReviewMedia::schema_fields_STATUS, ReviewMedia::STATUS_ATTACHED)
            ->order(ReviewMedia::schema_fields_ID, 'ASC')
            ->select()->fetchArray();
        return array_map(fn(array $row): array => [
            'kind' => (string)($row[ReviewMedia::schema_fields_MEDIA_KIND] ?? ''),
            'url' => '/media/' . $this->encodePath((string)($row[ReviewMedia::schema_fields_PATH] ?? '')),
            'mime_type' => (string)($row[ReviewMedia::schema_fields_MIME_TYPE] ?? ''),
            'name' => (string)($row[ReviewMedia::schema_fields_ORIGINAL_NAME] ?? ''),
        ], $rows);
    }

    private function plainFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', trim($name)));
        $name = (string)(preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? 'upload');
        return mb_substr($name !== '' ? $name : 'upload', 0, 255);
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', array_filter(explode('/', trim($path, '/')), 'strlen')));
    }
}
