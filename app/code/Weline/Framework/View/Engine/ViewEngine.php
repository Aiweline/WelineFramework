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
        return $this->template->fetchHtml($logical, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function stream(string $logical, array $data = []): void
    {
        $identity = $this->template->shortEngineRouteIdentity($logical);
        TemplateRenderRouteTrace::armIfRequested();
        TemplateRenderRouteTrace::begin('fetch_stream', $identity);
        try {
            $this->template->addSourceModuleForEngine($logical);
            $this->template->streamCompiledFile($this->resolve($logical), $data);
        } finally {
            TemplateRenderRouteTrace::end('fetch_stream', $identity);
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
