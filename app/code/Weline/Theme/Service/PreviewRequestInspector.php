<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Http\Request;

final class PreviewRequestInspector
{
    public function __construct(
        private readonly Request $request,
    ) {
    }

    public function normalizePath(?string $path = null): string
    {
        $path ??= (string)$this->request->getUrlPath();
        $path = \strtolower(\trim(\str_replace('\\', '/', $path)));

        if ($path === '') {
            return '/';
        }

        return $path[0] === '/' ? $path : ('/' . $path);
    }

    public function isPreviewShellPath(?string $path = null): bool
    {
        $path = $this->normalizePath($path);

        if (\str_starts_with($path, '/theme/frontend/theme-preview/')) {
            return true;
        }

        if (\str_starts_with($path, '/theme/backend/theme-editor/')) {
            return true;
        }

        if (\str_starts_with($path, '/theme/backend/config/layout/preview')
            || \str_starts_with($path, '/theme/backend/index/preview')) {
            return true;
        }

        if (\str_contains($path, '/pagebuilder/backend/page/')) {
            return true;
        }

        if ($this->request->getParam('visual_editor', '') === '1') {
            return true;
        }

        return $this->request->getParam('editor_mode', '') === '1';
    }

    public function isPreviewStaticPath(?string $path = null): bool
    {
        $path = $this->normalizePath($path);

        return \str_starts_with($path, '/static/__preview/')
            || \str_starts_with($path, '/pub/static/__preview/');
    }

    public function hasExplicitPreviewCarrier(): bool
    {
        if ($this->hasPositiveIntParam([
            'preview_theme',
            'frontend_theme_id',
            'backend_theme_id',
            'theme_id',
            'weline_theme_id',
        ])) {
            return true;
        }

        if ($this->hasNonEmptyParam(['shell', 'editor_area', 'preview_mode'])) {
            return true;
        }

        return $this->hasExplicitPreviewTokenCarrier();
    }

    public function shouldUseStoredPreviewContext(): bool
    {
        return $this->isPreviewShellPath()
            || $this->isPreviewStaticPath()
            || $this->hasExplicitPreviewCarrier();
    }

    public function shouldAllowPreviewTokenCookie(): bool
    {
        return $this->isPreviewShellPath() || $this->isPreviewStaticPath();
    }

    public function hasExplicitPreviewTokenCarrier(): bool
    {
        $token = $this->request->getParam(PreviewTokenService::TOKEN_KEY);
        if (\is_scalar($token) && \trim((string)$token) !== '') {
            return true;
        }

        $header = $this->request->getHeader(PreviewTokenService::TOKEN_HEADER);
        if (\is_array($header)) {
            foreach ($header as $value) {
                if (\is_scalar($value) && \trim((string)$value) !== '') {
                    return true;
                }
            }

            return false;
        }

        return \is_scalar($header) && \trim((string)$header) !== '';
    }

    private function hasPositiveIntParam(array $keys): bool
    {
        foreach ($keys as $key) {
            if ((int)$this->request->getParam($key, 0) > 0) {
                return true;
            }
        }

        return false;
    }

    private function hasNonEmptyParam(array $keys): bool
    {
        foreach ($keys as $key) {
            $value = $this->request->getParam($key);
            if (\is_scalar($value) && \trim((string)$value) !== '') {
                return true;
            }
        }

        return false;
    }
}
