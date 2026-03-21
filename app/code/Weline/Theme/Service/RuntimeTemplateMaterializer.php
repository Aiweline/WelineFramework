<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\View\Template;

class RuntimeTemplateMaterializer
{
    private array $compiledCache = [];

    public function __construct(
        private readonly Template $template,
    ) {
    }

    public function materializeContent(string $templateContent): string
    {
        $hash = sha1($templateContent);
        if (isset($this->compiledCache[$hash]) && is_file($this->compiledCache[$hash])) {
            return $this->compiledCache[$hash];
        }

        $runtimeDir = $this->getRuntimeDirectory();
        if (!is_dir($runtimeDir)) {
            mkdir($runtimeDir, 0775, true);
        }

        $compiledPath = $runtimeDir . DS . $hash . '.phtml';
        if (!is_file($compiledPath)) {
            $compiled = (string)$this->template->tmp_replace($templateContent, $compiledPath);
            file_put_contents($compiledPath, $compiled);
        }

        $this->compiledCache[$hash] = $compiledPath;

        return $compiledPath;
    }

    public function materializeFile(string $filePath): string
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException(__('模板文件不存在：%{1}', $filePath));
        }

        $content = file_get_contents($filePath);
        if (!is_string($content)) {
            throw new \RuntimeException(__('读取模板文件失败：%{1}', $filePath));
        }

        $hash = sha1($filePath . '|' . $content);
        if (isset($this->compiledCache[$hash]) && is_file($this->compiledCache[$hash])) {
            return $this->compiledCache[$hash];
        }

        $runtimeDir = $this->getRuntimeDirectory();
        if (!is_dir($runtimeDir)) {
            mkdir($runtimeDir, 0775, true);
        }

        $compiledPath = $runtimeDir . DS . $hash . '.phtml';
        if (!is_file($compiledPath)) {
            $compiled = (string)$this->template->tmp_replace($content, $compiledPath);
            file_put_contents($compiledPath, $compiled);
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
