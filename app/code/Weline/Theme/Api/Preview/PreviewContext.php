<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Preview;

/** Immutable preview request values shared with dependent modules. */
final readonly class PreviewContext
{
    private function __construct(
        public string $previewMode,
        public string $shell,
        public string $editorArea,
    ) {
    }

    public static function frontend(): self
    {
        return new self('live', 'preview', 'frontend');
    }
}
