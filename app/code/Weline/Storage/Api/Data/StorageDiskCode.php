<?php

declare(strict_types=1);

namespace Weline\Storage\Api\Data;

final readonly class StorageDiskCode implements \Stringable
{
    public const BUILTIN_LOCAL_MEDIA = 'local::filesystem::media';

    private const SEGMENT_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    private function __construct(
        public string $type,
        public string $vendor,
        public string $instance,
    ) {
    }

    public static function parse(string $diskCode): self
    {
        $normalized = strtolower(trim($diskCode));
        if (strlen($normalized) > 190) {
            throw new \InvalidArgumentException((string)__('磁盘代码超过 190 字节限制。'));
        }
        $parts = explode('::', $normalized);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException((string)__('磁盘代码必须使用 type::vendor::instance 三段式格式。'));
        }
        foreach ($parts as $part) {
            if (preg_match(self::SEGMENT_PATTERN, $part) !== 1) {
                throw new \InvalidArgumentException((string)__('磁盘代码包含无效段：%{1}', [$part]));
            }
        }

        return new self($parts[0], $parts[1], $parts[2]);
    }

    public static function fromProvider(string $providerCode, string $instance): self
    {
        return self::parse(trim($providerCode) . '::' . trim($instance));
    }

    public function providerCode(): string
    {
        return $this->type . '::' . $this->vendor;
    }

    public function __toString(): string
    {
        return $this->providerCode() . '::' . $this->instance;
    }
}
