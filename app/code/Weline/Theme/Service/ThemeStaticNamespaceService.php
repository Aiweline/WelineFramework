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
        $themePath = \trim(\str_replace('\\', '/', (string)$theme->getOriginPath()), '/');
        error_log('DEBUG resolveThemePath: themePath=' . $themePath . ' themeId=' . $theme->getId() . ' themeName=' . $theme->getName() . ' getPath=' . $theme->getPath());
        if ($themePath !== '') {
            return $themePath;
        }

        $configuredTheme = Env::get('theme')['path'] ?? Env::default_theme_DATA['path'] ?? '';
        error_log('DEBUG resolveThemePath fallback: configuredTheme=' . $configuredTheme);
        return \trim(\str_replace('\\', '/', (string)$configuredTheme), '/');
    }

    private function sanitizeSegment(string $segment): string
    {
        $segment = \preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $segment) ?? '';
        return \trim($segment, '_-');
    }
}
