<?php

declare(strict_types=1);

namespace Weline\FileManager\Api\Data;

/** Runtime-only image rendering result. Never persist its URL or HTML. */
final readonly class ResolvedFileImage
{
    public function __construct(
        public string $src,
        public string $html,
        public string $alt = '',
        public ?string $caption = null,
    ) {
        if (trim($src) === '' || trim($html) === '') {
            throw new \InvalidArgumentException((string)__('已解析图片结果无效。'));
        }
    }
}
