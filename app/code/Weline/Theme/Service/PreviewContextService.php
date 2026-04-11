<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Request;
use Weline\Framework\Session\Session;
use Weline\Theme\Model\WelineTheme;

final class PreviewContextService
{
    public const SESSION_KEY = 'weline_preview_context';

    public const AREA_FRONTEND = 'frontend';
    public const AREA_BACKEND = 'backend';

    public const SHELL_PREVIEW = 'preview';
    public const SHELL_THEME_EDITOR = 'theme-editor';
    public const SHELL_PAGEBUILDER = 'pagebuilder';

    public const TARGET_TYPE_PATH = 'path';
    public const TARGET_TYPE_LAYOUT = 'layout';
    public const TARGET_TYPE_PAGE = 'page';

    public const DEFAULT_SCOPE = 'default';
    public const DEFAULT_STATUS = 'draft';
    public const DEFAULT_PREVIEW_MODE = 'live';

    public function __construct(
        private readonly Request $request,
        private readonly Session $session,
        private readonly PreviewTokenService $previewTokenService,
        private readonly WelineTheme $welineTheme,
        private readonly ?PreviewRequestInspector $previewRequestInspector = null,
    ) {
    }

    public function getDefaultContext(): array
    {
        return [
            'frontend_theme_id' => 0,
            'backend_theme_id' => 0,
            'editor_area' => self::AREA_FRONTEND,
            'shell' => self::SHELL_PREVIEW,
            'preview_mode' => self::DEFAULT_PREVIEW_MODE,
            'status' => self::DEFAULT_STATUS,
            'version_id' => null,
            'scope' => self::DEFAULT_SCOPE,
            'target_type' => self::TARGET_TYPE_PATH,
            'target_value' => '/',
            'preview_token' => '',
        ];
    }

    public function normalizeArea(?string $area, string $default = self::AREA_FRONTEND): string
    {
        $area = \strtolower(\trim((string)$area));
        return $area === self::AREA_BACKEND ? self::AREA_BACKEND : $default;
    }

    public function normalizeShell(?string $shell, string $default = self::SHELL_PREVIEW): string
    {
        $shell = \trim((string)$shell);
        return \in_array($shell, [self::SHELL_PREVIEW, self::SHELL_THEME_EDITOR, self::SHELL_PAGEBUILDER], true)
            ? $shell
            : $default;
    }

    public function normalizeTargetType(?string $targetType, string $default = self::TARGET_TYPE_PATH): string
    {
        $targetType = \trim((string)$targetType);
        return \in_array($targetType, [self::TARGET_TYPE_PATH, self::TARGET_TYPE_LAYOUT, self::TARGET_TYPE_PAGE], true)
            ? $targetType
            : $default;
    }

    public function getCurrentContext(bool $mergeRequest = true): array
    {
        $context = $this->getDefaultContext();
        $shouldUseStoredContext = $this->shouldUseStoredContext();

        if ($shouldUseStoredContext) {
            $storedContext = $this->session->getData(self::SESSION_KEY);
            if (\is_array($storedContext)) {
                $context = \array_replace($context, $storedContext);
            }

            $tokenData = $this->previewTokenService->getCurrentPreviewData();
            if (\is_array($tokenData)) {
                $context = \array_replace($context, $this->extractContextFromTokenData($tokenData));
            }
        }

        if ($mergeRequest) {
            $context = \array_replace($context, $this->extractContextFromRequest());
        }

        return $this->normalizeContext($context);
    }

    public function buildContext(array $context, bool $mergeCurrent = true): array
    {
        $base = $mergeCurrent ? $this->getCurrentContext() : $this->getDefaultContext();
        return $this->normalizeContext(\array_replace($base, $context));
    }

    public function shouldUseStoredContext(): bool
    {
        return $this->getPreviewRequestInspector()->shouldUseStoredPreviewContext();
    }

    public function persistContext(array $context, bool $syncRequest = true): array
    {
        $normalized = $this->normalizeContext($context);

        $this->session->setData(self::SESSION_KEY, $normalized);
        $this->syncLegacySession($normalized);

        if ($syncRequest) {
            $this->syncRequest($normalized);
        }

        return $normalized;
    }

    public function persistCurrentRequestContext(array $overrides = []): array
    {
        return $this->persistContext($this->buildContext($overrides));
    }

    public function clearContext(bool $clearLegacy = true): void
    {
        $this->session->unsetData(self::SESSION_KEY);

        if ($clearLegacy) {
            foreach ([
                'preview_theme_id',
                'preview_theme_area',
                'preview_frontend_theme_id',
                'preview_backend_theme_id',
                'preview_editor_area',
                'preview_shell',
            ] as $key) {
                $this->session->unsetData($key);
            }
        }
    }

    public function ensureThemeIds(array $context, bool $fallbackFrontend = true, bool $fallbackBackend = true): array
    {
        $context = $this->normalizeContext($context);

        if ($fallbackFrontend && (int)$context['frontend_theme_id'] <= 0) {
            $context['frontend_theme_id'] = $this->getActiveThemeId(self::AREA_FRONTEND);
        }

        if ($fallbackBackend) {
            $backendThemeId = (int)$context['backend_theme_id'];
            if ($backendThemeId <= 0 || !$this->themeSupportsArea($backendThemeId, self::AREA_BACKEND)) {
                $context['backend_theme_id'] = $this->getActiveThemeId(self::AREA_BACKEND);
            }
        }

        return $this->normalizeContext($context);
    }

    public function getThemeIdForArea(string $area, ?array $context = null, bool $resolveFallback = false): int
    {
        $context ??= $this->getCurrentContext();
        $context = $this->normalizeContext($context);
        $area = $this->normalizeArea($area);

        $key = $area === self::AREA_BACKEND ? 'backend_theme_id' : 'frontend_theme_id';
        $themeId = (int)($context[$key] ?? 0);

        if ($themeId <= 0 && $resolveFallback) {
            return $this->getActiveThemeId($area);
        }

        return $themeId;
    }

    public function withPreviewToken(array $context, ?string $token): array
    {
        $context['preview_token'] = \trim((string)$token);
        return $this->normalizeContext($context);
    }

    public function toQueryParams(array $context, bool $includeLegacy = true): array
    {
        $context = $this->normalizeContext($context);
        $params = [
            'frontend_theme_id' => (int)$context['frontend_theme_id'],
            'backend_theme_id' => (int)$context['backend_theme_id'],
            'editor_area' => $context['editor_area'],
            'shell' => $context['shell'],
            'preview_mode' => $context['preview_mode'],
            'status' => $context['status'],
            'scope' => $context['scope'],
            'target_type' => $context['target_type'],
            'target_value' => (string)$context['target_value'],
        ];

        if (!empty($context['version_id'])) {
            $params['version_id'] = (int)$context['version_id'];
        }

        if (!empty($context['preview_token'])) {
            $params[PreviewTokenService::TOKEN_KEY] = (string)$context['preview_token'];
        }

        if ($includeLegacy) {
            $legacyArea = $context['editor_area'];
            $legacyThemeId = $this->getThemeIdForArea($legacyArea, $context);
            if ($legacyThemeId > 0) {
                $params['preview_theme'] = $legacyThemeId;
                $params['preview_area'] = $legacyArea;
            }
        }

        return $params;
    }

    public function normalizeContext(array $context): array
    {
        $normalized = \array_replace($this->getDefaultContext(), $context);

        $normalized['frontend_theme_id'] = \max(0, (int)($normalized['frontend_theme_id'] ?? 0));
        $normalized['backend_theme_id'] = \max(0, (int)($normalized['backend_theme_id'] ?? 0));
        $normalized['editor_area'] = $this->normalizeArea((string)($normalized['editor_area'] ?? self::AREA_FRONTEND));
        $normalized['shell'] = $this->normalizeShell(
            (string)($normalized['shell'] ?? ''),
            $normalized['preview_token'] ? self::SHELL_PREVIEW : self::SHELL_THEME_EDITOR
        );

        $previewMode = \trim((string)($normalized['preview_mode'] ?? ''));
        $normalized['preview_mode'] = $previewMode !== '' ? $previewMode : self::DEFAULT_PREVIEW_MODE;

        $status = \trim((string)($normalized['status'] ?? ''));
        $normalized['status'] = \in_array($status, ['draft', 'published'], true) ? $status : self::DEFAULT_STATUS;

        $versionId = $normalized['version_id'] ?? null;
        $normalized['version_id'] = ($versionId !== null && (int)$versionId > 0) ? (int)$versionId : null;

        $scope = \trim((string)($normalized['scope'] ?? ''));
        $normalized['scope'] = $scope !== '' ? $scope : self::DEFAULT_SCOPE;

        $normalized['target_type'] = $this->normalizeTargetType((string)($normalized['target_type'] ?? ''));

        $targetValue = $normalized['target_value'] ?? '';
        if (\is_scalar($targetValue)) {
            $targetValue = \trim((string)$targetValue);
        } else {
            $targetValue = '';
        }
        if ($targetValue === '') {
            $targetValue = $normalized['target_type'] === self::TARGET_TYPE_PAGE
                ? '0'
                : ($this->extractRequestPath() ?: '/');
        }
        $normalized['target_value'] = $targetValue;

        $previewToken = \trim((string)($normalized['preview_token'] ?? ''));
        if ($previewToken === '') {
            $previewToken = (string)($this->previewTokenService->getTokenFromRequest() ?? '');
        }
        $normalized['preview_token'] = $previewToken;

        return $normalized;
    }

    private function extractContextFromTokenData(array $tokenData): array
    {
        $context = [];

        if (isset($tokenData['context']) && \is_array($tokenData['context'])) {
            $context = $tokenData['context'];
        } elseif (!empty($tokenData['theme_id'])) {
            $context = [
                'frontend_theme_id' => (int)$tokenData['theme_id'],
                'shell' => self::SHELL_PREVIEW,
                'preview_mode' => self::DEFAULT_PREVIEW_MODE,
                'status' => self::DEFAULT_STATUS,
                'target_type' => self::TARGET_TYPE_LAYOUT,
                'target_value' => (string)($tokenData['page_type'] ?? 'homepage'),
            ];
        }

        $context['preview_token'] = (string)($tokenData['token'] ?? ($context['preview_token'] ?? ''));
        if (!isset($context['version_id']) && !empty($tokenData['version_id'])) {
            $context['version_id'] = (int)$tokenData['version_id'];
        }

        return $context;
    }

    private function extractContextFromRequest(): array
    {
        $context = [];

        foreach ([
            'frontend_theme_id',
            'backend_theme_id',
            'editor_area',
            'shell',
            'preview_mode',
            'status',
            'version_id',
            'scope',
            'target_type',
            'target_value',
        ] as $key) {
            $value = $this->request->getParam($key);
            if ($value !== null && $value !== '') {
                $context[$key] = $value;
            }
        }

        $previewToken = $this->request->getParam(PreviewTokenService::TOKEN_KEY);
        $hasExplicitPreviewToken = \is_scalar($previewToken) && \trim((string)$previewToken) !== '';
        if ($hasExplicitPreviewToken) {
            $context['preview_token'] = (string)$previewToken;
        }

        $requestFrontendThemeId = \max(0, (int)$this->request->getParam('frontend_theme_id', 0));
        if ($requestFrontendThemeId > 0) {
            $context['frontend_theme_id'] = $requestFrontendThemeId;
        }

        $requestBackendThemeId = \max(0, (int)$this->request->getParam('backend_theme_id', 0));
        if ($requestBackendThemeId > 0) {
            $context['backend_theme_id'] = $requestBackendThemeId;
        }

        $legacyPreviewThemeId = (int)$this->request->getParam('preview_theme', 0);
        $legacyPreviewArea = $this->normalizeArea(
            (string)$this->request->getParam('preview_area', (string)($context['editor_area'] ?? self::AREA_FRONTEND))
        );
        if ($legacyPreviewThemeId > 0) {
            if ($legacyPreviewArea === self::AREA_BACKEND) {
                if ($requestBackendThemeId <= 0) {
                    $context['backend_theme_id'] = $legacyPreviewThemeId;
                }
            } elseif ($requestFrontendThemeId <= 0) {
                $context['frontend_theme_id'] = $legacyPreviewThemeId;
            }
            $context['editor_area'] = $context['editor_area'] ?? $legacyPreviewArea;
            $context['shell'] = $context['shell'] ?? self::SHELL_PREVIEW;
        }

        $requestThemeId = (int)$this->request->getParam('theme_id', 0);
        if ($requestThemeId > 0 && $requestFrontendThemeId <= 0 && $requestBackendThemeId <= 0) {
            $requestArea = $this->normalizeArea(
                (string)$this->request->getParam('editor_area', (string)$this->request->getParam('preview_area', self::AREA_FRONTEND))
            );
            if ($requestArea === self::AREA_BACKEND) {
                $context['backend_theme_id'] = $requestThemeId;
            } else {
                $context['frontend_theme_id'] = $requestThemeId;
            }
            $context['editor_area'] = $context['editor_area'] ?? $requestArea;
        }

        $welineThemeId = (int)$this->request->getParam('weline_theme_id', 0);
        if ($welineThemeId > 0 && $requestFrontendThemeId <= 0 && (int)($context['frontend_theme_id'] ?? 0) <= 0) {
            $context['frontend_theme_id'] = $welineThemeId;
        }

        // 最高优先级：原始 URL 查询参数（REQUEST_URI）显式选择必须覆盖会话/旧上下文。
        // 这可以避免 request parameter bag 被历史上下文回写后，污染当前预览直链。
        $rawFrontendThemeId = $this->getRawQueryInt('frontend_theme_id');
        if ($rawFrontendThemeId > 0) {
            $requestFrontendThemeId = $rawFrontendThemeId;
            $context['frontend_theme_id'] = $rawFrontendThemeId;
        }
        $rawBackendThemeId = $this->getRawQueryInt('backend_theme_id');
        if ($rawBackendThemeId > 0) {
            $requestBackendThemeId = $rawBackendThemeId;
            $context['backend_theme_id'] = $rawBackendThemeId;
        }
        $rawLegacyPreviewThemeId = $this->getRawQueryInt('preview_theme');
        if ($rawLegacyPreviewThemeId > 0) {
            $legacyPreviewThemeId = $rawLegacyPreviewThemeId;
            $rawPreviewArea = $this->normalizeArea(
                $this->getRawQueryString(
                    'preview_area',
                    (string)($context['editor_area'] ?? self::AREA_FRONTEND)
                )
            );
            if ($rawPreviewArea === self::AREA_BACKEND) {
                if ($requestBackendThemeId <= 0) {
                    $context['backend_theme_id'] = $rawLegacyPreviewThemeId;
                }
            } else {
                if ($requestFrontendThemeId <= 0) {
                    $context['frontend_theme_id'] = $rawLegacyPreviewThemeId;
                }
            }
            $context['editor_area'] = $context['editor_area'] ?? $rawPreviewArea;
            $context['shell'] = $context['shell'] ?? self::SHELL_PREVIEW;
        }

        $rawPreviewToken = \trim($this->getRawQueryString(PreviewTokenService::TOKEN_KEY, ''));
        if ($rawPreviewToken !== '') {
            $hasExplicitPreviewToken = true;
            $context['preview_token'] = $rawPreviewToken;
        }

        $hasExplicitThemeSelection = $requestFrontendThemeId > 0
            || $requestBackendThemeId > 0
            || $legacyPreviewThemeId > 0
            || $requestThemeId > 0
            || $welineThemeId > 0;
        if ($hasExplicitThemeSelection && !$hasExplicitPreviewToken) {
            // Fresh theme-selection requests must not inherit an older preview token,
            // otherwise the old token context can override the new theme choice.
            $context['preview_token'] = '';
            if (($this->request->getParam('version_id') ?? '') === '') {
                $context['version_id'] = null;
            }
        }

        if (empty($context['shell'])) {
            $context['shell'] = $this->detectShellFromRequest();
        }

        if (empty($context['target_type']) || empty($context['target_value'])) {
            $context = \array_replace($context, $this->detectTargetFromRequest());
        }

        return $context;
    }

    private function getRawQueryInt(string $key, int $default = 0): int
    {
        $value = $this->getRawQueryString($key, null);
        if ($value === null || $value === '') {
            return $default;
        }

        return (int)$value;
    }

    private function getRawQueryString(string $key, ?string $default = null): ?string
    {
        $params = $this->getRawQueryParams();
        if (!\array_key_exists($key, $params)) {
            return $default;
        }

        $value = $params[$key];
        if (\is_array($value)) {
            $value = \reset($value);
        }

        return \is_scalar($value) ? (string)$value : $default;
    }

    private function getRawQueryParams(): array
    {
        $requestUri = (string) (\w_env('request.uri', '') ?? '');
        $query = (string)\parse_url($requestUri, \PHP_URL_QUERY);
        if ($query === '') {
            return [];
        }

        $params = [];
        \parse_str($query, $params);
        return \is_array($params) ? $params : [];
    }

    private function detectShellFromRequest(): string
    {
        $uri = \strtolower((string) (\w_env('request.uri', '') ?? ''));
        if (\str_contains($uri, 'pagebuilder/backend/page/') || $this->request->getParam('visual_editor') === '1') {
            return self::SHELL_PAGEBUILDER;
        }
        if (\str_contains($uri, 'theme-editor')) {
            return self::SHELL_THEME_EDITOR;
        }
        if ($this->previewTokenService->getTokenFromRequest() || (int)$this->request->getParam('preview_theme', 0) > 0) {
            return self::SHELL_PREVIEW;
        }

        return self::SHELL_THEME_EDITOR;
    }

    private function detectTargetFromRequest(): array
    {
        $pageId = (int)$this->request->getParam('page_id', 0);
        if ($pageId > 0) {
            return [
                'target_type' => self::TARGET_TYPE_PAGE,
                'target_value' => (string)$pageId,
            ];
        }

        $layoutType = \trim((string)$this->request->getParam('layout_type', ''));
        $layoutOption = \trim((string)$this->request->getParam('layout_option', ''));
        if ($layoutType !== '') {
            return [
                'target_type' => self::TARGET_TYPE_LAYOUT,
                'target_value' => $layoutOption !== '' ? ($layoutType . '.' . $layoutOption) : $layoutType,
            ];
        }

        return [
            'target_type' => self::TARGET_TYPE_PATH,
            'target_value' => $this->extractRequestPath(),
        ];
    }

    private function extractRequestPath(): string
    {
        $requestUri = (string) (\w_env('request.uri', '/') ?? '/');
        $path = (string)\parse_url($requestUri, \PHP_URL_PATH);
        $path = $path !== '' ? $path : '/';
        return $path[0] === '/' ? $path : ('/' . $path);
    }

    private function syncLegacySession(array $context): void
    {
        $legacyArea = $context['editor_area'];
        $legacyThemeId = $this->getThemeIdForArea($legacyArea, $context);
        if ($legacyThemeId <= 0) {
            $legacyArea = $context['frontend_theme_id'] > 0 ? self::AREA_FRONTEND : self::AREA_BACKEND;
            $legacyThemeId = $this->getThemeIdForArea($legacyArea, $context);
        }

        if ($legacyThemeId > 0) {
            $this->session->setData('preview_theme_id', $legacyThemeId);
            $this->session->setData('preview_theme_area', $legacyArea);
        }

        $this->session->setData('preview_frontend_theme_id', (int)$context['frontend_theme_id']);
        $this->session->setData('preview_backend_theme_id', (int)$context['backend_theme_id']);
        $this->session->setData('preview_editor_area', (string)$context['editor_area']);
        $this->session->setData('preview_shell', (string)$context['shell']);
    }

    private function syncRequest(array $context): void
    {
        // Keep request state aligned with the normalized preview context without
        // re-injecting legacy preview_theme params into canonical preview URLs.
        foreach ($this->toQueryParams($context, false) as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $this->request->setGet($key, $value);
        }
    }

    private function getActiveThemeId(string $area): int
    {
        $theme = clone $this->welineTheme;
        $theme->clearData()->clearQuery()->getActiveTheme($area);
        return (int)($theme->getId() ?: 0);
    }

    private function themeSupportsArea(int $themeId, string $area): bool
    {
        if ($themeId <= 0) {
            return false;
        }

        $theme = clone $this->welineTheme;
        $theme->clearData()->clearQuery()->load($themeId);
        if (!$theme->getId()) {
            return false;
        }

        $originPath = \trim((string)$theme->getOriginPath(), '/\\');
        if ($originPath === '') {
            return false;
        }

        $basePath = \rtrim(Env::path_THEME_DESIGN_DIR, '/\\') . \DIRECTORY_SEPARATOR . \str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $originPath);
        $area = $this->normalizeArea($area);

        return \is_dir($basePath . \DIRECTORY_SEPARATOR . $area)
            || \is_dir($basePath . \DIRECTORY_SEPARATOR . 'view' . \DIRECTORY_SEPARATOR . 'theme' . \DIRECTORY_SEPARATOR . $area)
            || \is_dir($basePath . \DIRECTORY_SEPARATOR . 'theme' . \DIRECTORY_SEPARATOR . $area);
    }

    private function getPreviewRequestInspector(): PreviewRequestInspector
    {
        if ($this->previewRequestInspector) {
            return $this->previewRequestInspector;
        }

        /** @var PreviewRequestInspector $service */
        $service = \Weline\Framework\Manager\ObjectManager::getInstance(PreviewRequestInspector::class);
        return $service;
    }
}
