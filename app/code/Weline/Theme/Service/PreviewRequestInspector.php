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
        if ($path === null) {
            $rawRequestUri = $this->getRawRequestUri();
            $rawPath = $rawRequestUri !== '' ? \parse_url($rawRequestUri, \PHP_URL_PATH) : null;
            $path = \is_string($rawPath) && $rawPath !== ''
                ? $rawPath
                : (string)$this->request->getUrlPath();
        }

        $path = \strtolower(\trim(\str_replace('\\', '/', $path)));

        if ($path === '') {
            return '/';
        }

        return $path[0] === '/' ? $path : ('/' . $path);
    }

    public function isPreviewShellPath(?string $path = null): bool
    {
        $path = $this->normalizePath($path);

        if (\str_contains($path, '/theme/frontend/theme-preview/')) {
            return true;
        }

        if (\str_contains($path, '/theme/backend/theme-editor/')) {
            return true;
        }

        if (\str_contains($path, '/theme/backend/config/layout/preview')
            || \str_contains($path, '/theme/backend/index/preview')) {
            return true;
        }

        if (\str_contains($path, '/pagebuilder/backend/page/')) {
            return true;
        }

        $rawPreviewFlag = $this->hasRawPreviewFlag();
        if ($rawPreviewFlag !== null) {
            return $rawPreviewFlag;
        }

        if ($this->request->getParam('visual_editor', '') === '1') {
            return true;
        }

        return $this->request->getParam('editor_mode', '') === '1';
    }

    public function shouldKeepPreviewStateOnlyForCurrentRequest(?string $path = null): bool
    {
        $path = $this->normalizePath($path);

        if (\str_contains($path, '/theme/backend/theme-editor/layout-preview')
            || \str_contains($path, '/theme/backend/theme-editor/compile-layout')) {
            return true;
        }

        if (\str_contains($path, '/pagebuilder/backend/preview/')
            || \str_contains($path, '/pagebuilder/backend/ai-site-agent/workspace-preview')) {
            return true;
        }

        $rawPreviewFlag = $this->hasRawPreviewFlag();
        if ($rawPreviewFlag === true) {
            return true;
        }

        $rawShell = $this->getRawQueryValue('shell');
        if ($rawShell !== null) {
            return $rawShell === PreviewContextService::SHELL_THEME_EDITOR;
        }

        if ($rawPreviewFlag === false) {
            return false;
        }

        if ($this->request->getParam('editor_mode', '') === '1') {
            return true;
        }

        if ($this->request->getParam('visual_editor', '') === '1') {
            return true;
        }

        $shell = \trim((string)$this->request->getParam('shell', ''));
        if (\in_array($shell, [PreviewContextService::SHELL_THEME_EDITOR], true)) {
            return true;
        }

        return false;
    }

    public function isPreviewStaticPath(?string $path = null): bool
    {
        $path = $this->normalizePath($path);

        return \str_starts_with($path, '/static/__preview/')
            || \str_starts_with($path, '/pub/static/__preview/');
    }

    public function hasExplicitPreviewCarrier(): bool
    {
        $rawCarrier = $this->hasRawPreviewCarrier();
        if ($rawCarrier !== null) {
            return $rawCarrier;
        }

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
        if ($this->shouldKeepPreviewStateOnlyForCurrentRequest()) {
            return false;
        }

        // Do not auto-apply preview token cookie on theme-editor shell routes.
        // Editor entry URLs should stay deterministic and must not be polluted
        // by stale preview sessions unless token is explicitly passed in URL/header.
        return (!$this->isThemeEditorShellPath() && $this->isPreviewShellPath())
            || $this->isPreviewStaticPath();
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

    private function hasRawPreviewCarrier(): ?bool
    {
        $params = $this->getRawQueryParams();
        if ($params === null) {
            return null;
        }

        foreach ([
            'preview_theme',
            'frontend_theme_id',
            'backend_theme_id',
            'theme_id',
            'weline_theme_id',
        ] as $key) {
            if ((int)($params[$key] ?? 0) > 0) {
                return true;
            }
        }

        foreach (['shell', 'editor_area', 'preview_mode', PreviewTokenService::TOKEN_KEY] as $key) {
            $value = $params[$key] ?? null;
            if (\is_array($value)) {
                $value = \reset($value);
            }
            if (\is_scalar($value) && \trim((string)$value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function hasRawPreviewFlag(): ?bool
    {
        $params = $this->getRawQueryParams();
        if ($params === null) {
            return null;
        }

        foreach (['visual_editor', 'editor_mode'] as $key) {
            $value = $params[$key] ?? null;
            if (\is_array($value)) {
                $value = \reset($value);
            }
            if (\is_scalar($value) && \trim((string)$value) === '1') {
                return true;
            }
        }

        return false;
    }

    private function getRawQueryValue(string $key): ?string
    {
        $params = $this->getRawQueryParams();
        if ($params === null || !\array_key_exists($key, $params)) {
            return null;
        }

        $value = $params[$key];
        if (\is_array($value)) {
            $value = \reset($value);
        }

        return \is_scalar($value) ? \trim((string)$value) : null;
    }

    private function getRawQueryParams(): ?array
    {
        $rawRequestUri = $this->getRawRequestUri();
        if ($rawRequestUri === '') {
            return null;
        }

        $query = (string)\parse_url($rawRequestUri, \PHP_URL_QUERY);
        if ($query === '') {
            return null;
        }

        $params = [];
        \parse_str($query, $params);
        return \is_array($params) ? $params : [];
    }

    private function getRawRequestUri(): string
    {
        try {
            $uri = $this->request->getServer('REQUEST_URI');
            if (\is_scalar($uri) && \trim((string)$uri) !== '') {
                return (string)$uri;
            }
        } catch (\Throwable) {
        }

        try {
            $uri = \Weline\Framework\Env\WelineEnv::server('REQUEST_URI', '');
            if (\is_scalar($uri) && \trim((string)$uri) !== '') {
                return (string)$uri;
            }
        } catch (\Throwable) {
        }

        try {
            $uri = \w_env('request.uri', '');
            if (\is_scalar($uri) && \trim((string)$uri) !== '') {
                return (string)$uri;
            }
        } catch (\Throwable) {
        }

        try {
            $uri = \w_env('input.uri', '');
            if (\is_scalar($uri) && \trim((string)$uri) !== '') {
                return (string)$uri;
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private function isThemeEditorShellPath(): bool
    {
        $path = $this->normalizePath();

        return \str_starts_with($path, '/theme/backend/theme-editor');
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
