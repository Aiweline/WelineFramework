<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\View\Template;
use Weline\Framework\View\TemplateCacheManager;

class RuntimeTemplateMaterializer
{
    /** @var array L1 memory cache: hash => compiled_path */
    private array $compiledCache = [];

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
    public function materializeContent(string $templateContent): string
    {
        // Content-based hash for reliable cache detection
        $hash = md5($templateContent);

        // L1: Check memory cache first
        if (isset($this->compiledCache[$hash]) && is_file($this->compiledCache[$hash])) {
            return $this->compiledCache[$hash];
        }

        $runtimeDir = $this->getRuntimeDirectory();
        if (!is_dir($runtimeDir)) {
            mkdir($runtimeDir, 0775, true);
        }

        $compiledPath = $runtimeDir . DS . $hash . '.phtml';

        // Check if we can use existing compiled file (content hash embedded)
        if (is_file($compiledPath)) {
            $content = file_get_contents($compiledPath);
            if ($content !== false && str_starts_with($content, "<?php /* hash:")) {
                // Verify hash matches
                if (preg_match('/^\<\?php \/\* hash:([a-f0-9]+) \*\//', $content, $matches)) {
                    if ($matches[1] === $hash) {
                        $this->compiledCache[$hash] = $compiledPath;
                        return $compiledPath;
                    }
                }
            }
        }

        // Compile the content
        $compiled = (string)$this->template->tmp_replace($templateContent, $compiledPath);

        // Embed content hash for reliable cache detection
        $hashHeader = "<?php /* hash:{$hash} */ ?>\n";
        file_put_contents($compiledPath, $hashHeader . $compiled);

        $this->compiledCache[$hash] = $compiledPath;

        return $compiledPath;
    }

    /**
     * Materialize a template file into compiled form
     *
     * Uses file path + content hash for cache key.
     * Delegates to TemplateCacheManager for enhanced features.
     */
    public function materializeFile(string $filePath): string
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException(__('模板文件不存在：%{1}', $filePath));
        }

        $content = file_get_contents($filePath);
        if (!is_string($content)) {
            throw new \RuntimeException(__('读取模板文件失败：%{1}', $filePath));
        }

        // Use path + content for hash (matches TemplateCacheManager strategy)
        $hash = md5($filePath . '|' . $content);

        // L1: Check memory cache
        if (isset($this->compiledCache[$hash]) && is_file($this->compiledCache[$hash])) {
            return $this->compiledCache[$hash];
        }

        // Try TemplateCacheManager for enhanced caching
        try {
            $cacheManager = TemplateCacheManager::getInstance();
            $cachedFile = $cacheManager->getCachedFile($filePath, DEV);
            if ($cachedFile !== null && is_file($cachedFile)) {
                $this->compiledCache[$hash] = $cachedFile;
                return $cachedFile;
            }
        } catch (\Throwable) {
            // Fall through to local compilation
        }

        $runtimeDir = $this->getRuntimeDirectory();
        if (!is_dir($runtimeDir)) {
            mkdir($runtimeDir, 0775, true);
        }

        $compiledPath = $runtimeDir . DS . $hash . '.phtml';

        if (!is_file($compiledPath)) {
            $compiled = (string)$this->template->tmp_replace($content, $compiledPath);

            // Embed content hash
            $hashHeader = "<?php /* hash:{$hash} */ ?>\n";
            file_put_contents($compiledPath, $hashHeader . $compiled);

            // Update enhanced cache manager
            try {
                $cacheManager->writeCache($filePath, $compiled);
            } catch (\Throwable) {
                // Non-critical - continue without enhanced cache
            }
        }

        $this->compiledCache[$hash] = $compiledPath;

        return $compiledPath;
    }

    public function renderContent(string $templateContent, array $dictionary = []): string
    {
        return $this->renderCompiledFile($this->materializeContent($templateContent), $dictionary);
    }

    public function renderFile(string $filePath, array $dictionary = []): string
    {
        return $this->renderCompiledFile($this->materializeFile($filePath), $dictionary);
    }

    private function renderCompiledFile(string $compiledPath, array $dictionary = []): string
    {
        $this->template->unsetData();
        return $this->template->ob_file($compiledPath, $dictionary);
    }

    private function getRuntimeDirectory(): string
    {
        return BP . 'var' . DS . 'runtime' . DS . 'theme-components';
    }
}
