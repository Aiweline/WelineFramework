<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\View\Template;

class RuntimeTemplateMaterializer
{
    private const MAX_COMPILED_CACHE_ENTRIES = 512;
    /** @var array L1 memory cache: hash => compiled_path */
    private array $compiledCache = [];

    /** @var array<string, string> Worker L1 cache shared by materializer instances */
    private static array $workerCompiledCache = [];

    public static function clearProcessCache(): void
    {
        self::$workerCompiledCache = [];
    }

    public static function processCacheItemCount(): int
    {
        return count(self::$workerCompiledCache);
    }

    public function __construct(
        private readonly Template $template,
    ) {
    }

    /**
     * Materialize template content into a compiled file
     *
     * Uses content-based hash for reliable cache detection.
     * Falls back to TemplateCacheManager for enhanced caching.
     */
    public function materializeContent(string $templateContent, string $originPath = '', string $contextKey = ''): string
    {
        $logicalPath = 'virtual-' . hash('sha256', $templateContent) . '.phtml';
        $compiled = $this->template->getFetchFileFromSource($logicalPath, $templateContent, $originPath, $contextKey);
        $this->rememberCompiled($compiled, $compiled);
        return $compiled;
    }

    public function materializeFile(string $filePath): string
    {
        $compiled = $this->template->getFetchFile($filePath);
        $this->rememberCompiled($compiled, $compiled);
        return $compiled;
    }

    public function renderContent(string $templateContent, array $dictionary = [], string $originPath = '', string $contextKey = ''): string
    {
        return $this->template->ob_file($this->materializeContent($templateContent, $originPath, $contextKey), $dictionary);
    }

    public function renderFile(string $filePath, array $dictionary = []): string
    {
        return $this->template->ob_file($this->materializeFile($filePath), $dictionary);
    }

    private function rememberCompiled(string $hash, string $compiledPath): void
    {
        unset(self::$workerCompiledCache[$hash], $this->compiledCache[$hash]);
        while (count(self::$workerCompiledCache) >= self::MAX_COMPILED_CACHE_ENTRIES) {
            $oldest = array_key_first(self::$workerCompiledCache);
            if ($oldest === null) {
                break;
            }
            unset(self::$workerCompiledCache[$oldest]);
        }
        while (count($this->compiledCache) >= self::MAX_COMPILED_CACHE_ENTRIES) {
            $oldest = array_key_first($this->compiledCache);
            if ($oldest === null) {
                break;
            }
            unset($this->compiledCache[$oldest]);
        }
        self::$workerCompiledCache[$hash] = $compiledPath;
        $this->compiledCache[$hash] = $compiledPath;
    }
}
