<?php

declare(strict_types=1);

namespace Weline\Storage\Api\Data;

final readonly class StorageObjectReference
{
    public function __construct(
        public string $diskCode,
        public string $objectKey,
    ) {
        StorageDiskCode::parse($diskCode);
        self::assertObjectKey($objectKey);
    }

    public static function assertObjectKey(string $objectKey): void
    {
        if (
            $objectKey === ''
            || strlen($objectKey) > 768
            || str_starts_with($objectKey, '/')
            || str_contains($objectKey, '\\')
            || str_contains($objectKey, "\0")
            || preg_match('/[\x00-\x1F\x7F]/', $objectKey) === 1
        ) {
            throw new \InvalidArgumentException((string)__('对象键必须是非空相对路径。'));
        }
        foreach (explode('/', $objectKey) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException((string)__('对象键包含不安全路径段。'));
            }
        }
    }
}
