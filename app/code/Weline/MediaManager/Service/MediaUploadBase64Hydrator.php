<?php

declare(strict_types=1);

namespace Weline\MediaManager\Service;

/**
 * Turns bin-query JSON upload_base64 payloads into $_FILES['upload'] for ConnectorService.
 */
final class MediaUploadBase64Hydrator
{
    private const MAX_BYTES = 20 * 1024 * 1024;

    /**
     * @param array<string,mixed> $params
     * @return list<string> temporary file paths that the caller must unlink
     */
    public function hydrate(array $params): array
    {
        $uploads = $params['upload_base64'] ?? $params['_files'] ?? null;
        if (!\is_array($uploads) || $uploads === []) {
            return [];
        }

        $names = [];
        $types = [];
        $tmpNames = [];
        $errors = [];
        $sizes = [];
        $created = [];

        foreach ($uploads as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $data = (string)($item['data'] ?? '');
            if ($data === '') {
                continue;
            }
            $bin = \base64_decode($data, true);
            if ($bin === false || $bin === '') {
                continue;
            }
            if (\strlen($bin) > self::MAX_BYTES) {
                throw new \InvalidArgumentException((string)__('上传文件超过大小限制。'));
            }
            $tmp = \tempnam(\sys_get_temp_dir(), 'mmup_');
            if ($tmp === false) {
                continue;
            }
            if (@\file_put_contents($tmp, $bin) === false) {
                @\unlink($tmp);
                continue;
            }
            $created[] = $tmp;
            $names[] = (string)($item['name'] ?? 'upload.bin');
            $types[] = (string)($item['type'] ?? 'application/octet-stream');
            $tmpNames[] = $tmp;
            $errors[] = \UPLOAD_ERR_OK;
            $sizes[] = \strlen($bin);
        }

        if ($names === []) {
            return [];
        }

        $_FILES['upload'] = [
            'name' => $names,
            'type' => $types,
            'tmp_name' => $tmpNames,
            'error' => $errors,
            'size' => $sizes,
        ];

        return $created;
    }

    /**
     * @param list<string> $tmpFiles
     */
    public function cleanup(array $tmpFiles): void
    {
        foreach ($tmpFiles as $tmp) {
            if (\is_string($tmp) && $tmp !== '' && \is_file($tmp)) {
                @\unlink($tmp);
            }
        }
    }
}
