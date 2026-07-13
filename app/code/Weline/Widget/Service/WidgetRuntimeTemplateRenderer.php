<?php

declare(strict_types=1);

namespace Weline\Widget\Service;

use Weline\Framework\View\Template;
use Weline\Widget\Api\Rendering\RuntimeTemplateRendererInterface;

class WidgetRuntimeTemplateRenderer implements RuntimeTemplateRendererInterface
{
    private array $compiledCache = [];
    private static array $workerCompiledCache = [];

    public function __construct(
        private readonly Template $template,
    ) {
    }

    public function renderContent(string $templateContent, array $dictionary = []): string
    {
        return $this->renderCompiledFile($this->materializeContent($templateContent), $dictionary);
    }

    public function materializeContent(string $templateContent): string
    {
        $hash = md5($templateContent);
        if (isset(self::$workerCompiledCache[$hash]) && is_file(self::$workerCompiledCache[$hash])) {
            return self::$workerCompiledCache[$hash];
        }
        if (isset($this->compiledCache[$hash]) && is_file($this->compiledCache[$hash])) {
            self::$workerCompiledCache[$hash] = $this->compiledCache[$hash];
            return $this->compiledCache[$hash];
        }

        $runtimeDir = $this->getRuntimeDirectory();
        if (!is_dir($runtimeDir)) {
            mkdir($runtimeDir, 0775, true);
        }

        $compiledPath = $runtimeDir . DS . $hash . '.phtml';
        if (is_file($compiledPath)) {
            $content = file_get_contents($compiledPath);
            if (is_string($content) && preg_match('/^\<\?php \/\* hash:([a-f0-9]+) \*\//', $content, $matches) && $matches[1] === $hash) {
                self::$workerCompiledCache[$hash] = $compiledPath;
                $this->compiledCache[$hash] = $compiledPath;
                return $compiledPath;
            }
        }

        $compiled = (string)$this->template->tmp_replace($templateContent, $compiledPath);
        file_put_contents($compiledPath, "<?php /* hash:{$hash} */ ?>\n" . $compiled);

        self::$workerCompiledCache[$hash] = $compiledPath;
        $this->compiledCache[$hash] = $compiledPath;

        return $compiledPath;
    }

    private function renderCompiledFile(string $compiledPath, array $dictionary = []): string
    {
        $this->template->unsetData();
        $html = $this->template->ob_file($compiledPath, $dictionary);
        return is_string($html) ? $html : '';
    }

    private function getRuntimeDirectory(): string
    {
        return BP . 'var' . DS . 'runtime' . DS . 'widget-components';
    }
}
