<?php
declare(strict_types=1);

namespace Weline\Framework\View\Engine;

use Weline\Framework\View\Template;

/**
 * Resolves logical template paths to compiled com_*.phtml files.
 * Theme inheritance / RequestContext memo stay inside Template::getFetchFile.
 */
final class TemplateResolver
{
    public function __construct(private readonly Template $template)
    {
    }

    public function resolve(string $logical): string
    {
        return $this->template->getFetchFile($logical);
    }

    /**
     * Accept either a logical Module::path or an already-resolved compiled absolute path.
     */
    public function resolveCompiled(string $logicalOrCompiled): string
    {
        if ($this->isCompiledPath($logicalOrCompiled)) {
            return $logicalOrCompiled;
        }

        return $this->resolve($logicalOrCompiled);
    }

    public function isCompiledPath(string $path): bool
    {
        if ($path === '' || !\is_file($path)) {
            return false;
        }

        // Absolute filesystem path already resolved (compiled or include-ready).
        if ($path[0] === '/' || (\strlen($path) > 2 && $path[1] === ':')) {
            return true;
        }

        return \str_contains($path, '/com_') || \str_contains($path, '\\com_');
    }
}
