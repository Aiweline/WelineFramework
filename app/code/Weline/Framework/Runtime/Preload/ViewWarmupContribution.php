<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Preload;

/**
 * Immutable, data-only description of view resources a persistent worker may
 * prime during bootstrap. The executor remains owned by the view module.
 */
final readonly class ViewWarmupContribution
{
    /**
     * @param list<string> $templates
     * @param array<string, list<string>> $tagTemplates
     * @param list<string> $staticFiles Repository-relative paths using `/`.
     * @param list<string> $hookNames
     * @param list<string> $fpcPaths Module-owned public page paths using `/`.
     */
    public function __construct(
        public array $templates = [],
        public array $tagTemplates = [],
        public array $staticFiles = [],
        public array $hookNames = [],
        public array $fpcPaths = [],
    ) {
        self::assertStringList($this->templates, 'templates');
        self::assertStringList($this->staticFiles, 'static_files');
        self::assertStringList($this->hookNames, 'hook_names');
        self::assertStringList($this->fpcPaths, 'fpc_paths');
        foreach ($this->tagTemplates as $type => $templates) {
            if (!\is_string($type) || \preg_match('/^[a-z][a-z0-9_-]*$/', $type) !== 1) {
                throw new \InvalidArgumentException('View warmup tag template type is invalid.');
            }
            if (!\is_array($templates)) {
                throw new \InvalidArgumentException("View warmup tag templates for {$type} must be a list.");
            }
            self::assertStringList($templates, "tag_templates.{$type}");
        }

        foreach ($this->staticFiles as $path) {
            if (\str_contains($path, "\\")
                || \str_starts_with($path, '/')
                || \preg_match('/^[A-Za-z]:\//', $path) === 1
                || \preg_match('#(?:^|/)\.\.(?:/|$)#', $path) === 1
            ) {
                throw new \InvalidArgumentException(
                    "View warmup static file must be a portable repository-relative path: {$path}",
                );
            }
        }

        foreach ($this->fpcPaths as $path) {
            if (!\str_starts_with($path, '/')
                || \strlen($path) > 2048
                || \preg_match('/[\r\n\t\s]/', $path) === 1
                || \str_contains($path, '://')
            ) {
                throw new \InvalidArgumentException(
                    "View warmup FPC path must be a public absolute path: {$path}",
                );
            }
        }
    }

    /**
     * @param list<string> $items
     */
    private static function assertStringList(array $items, string $label): void
    {
        foreach ($items as $key => $item) {
            if (!\is_int($key) || !\is_string($item) || \trim($item) === '') {
                throw new \InvalidArgumentException(
                    "View warmup {$label} must be a list of non-empty strings.",
                );
            }
        }
    }
}
