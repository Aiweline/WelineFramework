<?php

declare(strict_types=1);

namespace Weline\Theme\Dto;

class ThemeRenderable
{
    public const MODE_TEMPLATE_PATH = 'template_path';
    public const MODE_TEMPLATE_CONTENT = 'template_content';
    public const MODE_BLOCK_CLASS = 'block_class';

    public function __construct(
        public readonly string $mode,
        public readonly ?string $templatePath = null,
        public readonly ?string $templateContent = null,
        public readonly ?string $blockClass = null,
    ) {
    }

    public function isTemplatePath(): bool
    {
        return $this->mode === self::MODE_TEMPLATE_PATH && $this->templatePath !== null && $this->templatePath !== '';
    }

    public function isTemplateContent(): bool
    {
        return $this->mode === self::MODE_TEMPLATE_CONTENT && $this->templateContent !== null;
    }

    public function isBlockClass(): bool
    {
        return $this->mode === self::MODE_BLOCK_CLASS && $this->blockClass !== null && $this->blockClass !== '';
    }
}
