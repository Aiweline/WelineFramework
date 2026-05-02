<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Session\Session;
use Weline\Theme\Model\WelineTheme;

final class ThemeStaticNamespaceService
{
    public function __construct(
        private readonly PreviewContextService $previewContextService,
        private readonly Session $session,
    ) {
    }

    public function usesPreviewNamespace(?array $context = null): bool
    {
        $context = $this->resolveContext($context);

        if (!$this->previewContextService->shouldUseStoredContext()) {
            return false;
        }

        return (int)($context['frontend_theme_id'] ?? 0) > 0
            || (int)($context['backend_theme_id'] ?? 0) > 0
            || \trim((string)($context['preview_token'] ?? '')) !== '';
    }

    public function resolvePublicThemePath(WelineTheme $theme, ?array $context = null): string
    {
        $themePath = $this->resolveThemePath($theme);
        if ($themePath === '') {
            return '';
        }

        if (!$this->usesPreviewNamespace($context)) {
            return $themePath;
        }

        return '__preview/' . $this->buildNamespaceKey($context) . '/' . $themePath;
    }

    public function getAssetQueryParams(?array $context = null): array
    {
        $context = $this->resolveContext($context);
        if (!$this->usesPreviewNamespace($context)) {
            return [];
        }

        $params = [
            'frontend_theme_id' => (int)($context['frontend_theme_id'] ?? 0),
            'backend_theme_id' => (int)($context['backend_theme_id'] ?? 0),
            'editor_area' => (string)($context['editor_area'] ?? PreviewContextService::AREA_FRONTEND),
            'shell' => (string)($context['shell'] ?? PreviewContextService::SHELL_PREVIEW),
            'preview_mode' => (string)($context['preview_mode'] ?? PreviewContextService::DEFAULT_PREVIEW_MODE),
            'status' => (string)($context['status'] ?? PreviewContextService::DEFAULT_STATUS),
            'scope' => (string)($context['scope'] ?? PreviewContextService::DEFAULT_SCOPE),
        ];

        $versionId = (int)($context['version_id'] ?? 0);
        if ($versionId > 0) {
            $params['version_id'] = $versionId;
        }

        $previewToken = \trim((string)($context['preview_token'] ?? ''));
        if ($previewToken !== '') {
            $params[PreviewTokenService::TOKEN_KEY] = $previewToken;
        }

        return \array_filter(
            $params,
            static fn(mixed $value): bool => $value !== null && $value !== '' && $value !== 0
        );
    }

    public function appendPreviewContextQuery(string $url, ?array $context = null): string
    {
        $params = $this->getAssetQueryParams($context);
        if ($url === '' || empty($params)) {
            return $url;
        }

        $fragment = '';
        $fragmentPos = \strpos($url, '#');
        if ($fragmentPos !== false) {
            $fragment = \substr($url, $fragmentPos);
            $url = \substr($url, 0, $fragmentPos);
        }

        $separator = \str_contains($url, '?') ? '&' : '?';

        return $url . $separator . \http_build_query($params) . $fragment;
    }

    private function resolveContext(?array $context = null): array
    {
        return $context === null
            ? $this->previewContextService->getCurrentContext()
            : $this->previewContextService->buildContext($context);
    }

    private function buildNamespaceKey(?array $context = null): string
    {
        $context = $this->resolveContext($context);
        $previewToken = \trim((string)($context['preview_token'] ?? ''));
        if ($previewToken !== '') {
            return 'token_' . $this->sanitizeSegment($previewToken);
        }

        $sessionId = $this->ensureSessionId();
        $fingerprint = [
            'frontend_theme_id' => (int)($context['frontend_theme_id'] ?? 0),
            'backend_theme_id' => (int)($context['backend_theme_id'] ?? 0),
            'editor_area' => (string)($context['editor_area'] ?? PreviewContextService::AREA_FRONTEND),
            'shell' => (string)($context['shell'] ?? PreviewContextService::SHELL_PREVIEW),
            'preview_mode' => (string)($context['preview_mode'] ?? PreviewContextService::DEFAULT_PREVIEW_MODE),
            'status' => (string)($context['status'] ?? PreviewContextService::DEFAULT_STATUS),
            'version_id' => (int)($context['version_id'] ?? 0),
            'scope' => (string)($context['scope'] ?? PreviewContextService::DEFAULT_SCOPE),
            'session_id' => $sessionId,
        ];

        return 'ctx_' . \substr(\hash('sha256', \json_encode($fingerprint, \JSON_UNESCAPED_SLASHES)), 0, 20);
    }

    private function ensureSessionId(): string
    {
        $sessionId = $this->session->getId();
        if ($sessionId !== '') {
            return $sessionId;
        }

        $this->session->start();
        return $this->session->getId();
    }

    private function resolveThemePath(WelineTheme $theme): string
    {
        $themePath = $this->normalizePublicThemePath((string)$theme->getOriginPath());
        if ($themePath !== '') {
            return $themePath;
        }

        $configuredTheme = Env::get('theme')['path'] ?? Env::default_theme_DATA['path'] ?? '';
        return $this->normalizePublicThemePath((string)$configuredTheme);
    }

    private function normalizePublicThemePath(string $themePath): string
    {
        $themePath = \rtrim(\str_replace('\\', '/', \trim($themePath)), '/');
        if ($themePath === '') {
            return '';
        }

        if (\preg_match('#^([^/:]+)_([^/:]+)::(.+)$#', $themePath, $matches)) {
            $moduleRelativePath = \trim(\str_replace('\\', '/', (string)$matches[3]), '/');
            if ($moduleRelativePath === '') {
                return '';
            }

            return $matches[1] . '/' . $matches[2] . '/' . $moduleRelativePath;
        }

        $designRoot = \rtrim(\str_replace('\\', '/', Env::path_THEME_DESIGN_DIR), '/');
        if ($this->isPathUnderRoot($themePath, $designRoot)) {
            return \trim(\substr($themePath, \strlen($designRoot)), '/');
        }

        $codeRoot = \rtrim(\str_replace('\\', '/', BP), '/') . '/app/code';
        if ($this->isPathUnderRoot($themePath, $codeRoot)) {
            $relativeCodePath = \trim(\substr($themePath, \strlen($codeRoot)), '/');
            if (\preg_match('#^([^/]+)/([^/]+)/view/theme(?:/|$)#', $relativeCodePath, $matches)) {
                return $matches[1] . '/' . $matches[2] . '/view/theme';
            }
        }

        if ($this->isAbsolutePath($themePath)) {
            return '';
        }

        return \trim($themePath, '/');
    }

    private function isPathUnderRoot(string $path, string $root): bool
    {
        if ($root === '') {
            return false;
        }

        return $path === $root || \str_starts_with($path, $root . '/');
    }

    private function isAbsolutePath(string $path): bool
    {
        return \preg_match('#^[A-Za-z]:/#', $path) === 1
            || \str_starts_with($path, '/')
            || \str_starts_with($path, '//');
    }

    private function sanitizeSegment(string $segment): string
    {
        $segment = \preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $segment) ?? '';
        return \trim($segment, '_-');
    }
}
