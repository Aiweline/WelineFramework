<?php
declare(strict_types=1);

namespace Weline\Framework\View\Engine;

use Weline\Framework\Runtime\TemplateRenderRouteTrace;
use Weline\Framework\View\Template;

/**
 * View runtime orchestrator: resolve + render mode (stream / capture / include).
 * Consumes Taglib-compiled com_*.phtml only; does not reimplement Taglib.
 */
final class ViewEngine implements ViewEngineInterface
{
    private readonly TemplateResolver $resolver;

    public function __construct(private readonly Template $template)
    {
        $this->resolver = new TemplateResolver($template);
    }

    public static function for(Template $template): self
    {
        return new self($template);
    }

    public function resolve(string $logical): string
    {
        return $this->resolver->resolve($logical);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function include(string $logicalOrCompiled, array $data = []): void
    {
        $path = $this->resolver->resolveCompiled($logicalOrCompiled);
        $identity = $this->template->shortEngineRouteIdentity($logicalOrCompiled);
        TemplateRenderRouteTrace::armIfRequested();
        TemplateRenderRouteTrace::begin('engine_include', $identity);
        try {
            $this->template->streamCompiledFile($path, $data);
        } finally {
            TemplateRenderRouteTrace::end('engine_include', $identity);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function capture(string $logical, array $data = []): string
    {
        return $this->withGlobalRegions($logical, $this->template->fetchHtml($logical, $data));
    }

    /**
     * V2 is the engine the storefront actually renders through, so the legacy Template
     * hooks never run for these pages. Global regions are appended here instead: additive,
     * idempotent, and limited to page layouts that produce a full document.
     */
    private function withGlobalRegions(string $logical, string $html): string
    {
        if (!str_contains($logical, 'layouts/') || !str_contains($html, '<head') || !str_contains($html, '</body>')) {
            return $html;
        }
        try {
            return \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Runtime\GlobalRegionInjectorInterface::class)
                ->ensure($html, 'frontend');
        } catch (\Throwable) {
            return $html;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function stream(string $logical, array $data = []): void
    {
        $identity = $this->template->shortEngineRouteIdentity($logical);
        TemplateRenderRouteTrace::armIfRequested();
        TemplateRenderRouteTrace::begin('fetch_stream', $identity);
        // Page layouts are streamed straight to the output buffer, so they are captured
        // here to append the global regions. Only full documents are touched.
        $capture = str_contains($logical, 'layouts/');
        if ($capture) {
            ob_start();
        }
        try {
            $this->template->addSourceModuleForEngine($logical);
            $this->template->streamCompiledFile($this->resolve($logical), $data);
        } catch (\Throwable $error) {
            if ($capture) {
                echo (string)ob_get_clean();
            }
            throw $error;
        } finally {
            TemplateRenderRouteTrace::end('fetch_stream', $identity);
        }
        if ($capture) {
            echo $this->withGlobalRegions($logical, (string)ob_get_clean());
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function fetch(string $logical, array $data = []): string
    {
        if (CaptureSession::hasActive()) {
            $this->stream($logical, $data);

            return '';
        }

        return $this->capture($logical, $data);
    }

    public function resolver(): TemplateResolver
    {
        return $this->resolver;
    }
}
