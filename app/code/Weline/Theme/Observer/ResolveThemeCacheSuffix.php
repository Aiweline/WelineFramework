<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\ThemeContextService;

class ResolveThemeCacheSuffix implements ObserverInterface
{
    public function __construct(
        private readonly ThemeContextService $themeContextService,
        private readonly PreviewTokenService $previewTokenService,
    ) {
    }

    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        if (!$data instanceof DataObject) {
            return;
        }

        $area = (string)$data->getData('area');
        $filename = (string)$data->getData('filename');

        $area = strtolower(trim($area)) === 'backend' ? 'backend' : 'frontend';
        $theme = $this->resolveExplicitRequestTheme($area)
            ?? $this->resolveThemeDataTheme($area)
            ?? $this->themeContextService->resolveTheme($area);
        $themeId = $theme && $theme->getId() ? (string)$theme->getId() : '';
        $themePath = $theme && $theme->getPath() !== ''
            ? (string)$theme->getPath()
            : (string)(Env::get('theme.path') ?? (Env::default_theme_DATA['path'] ?? ''));

        $suffixParts = [
            'theme_id:' . $themeId,
            'theme_path:' . $themePath,
        ];

        $requestSuffix = $this->resolveExplicitRequestSuffix($area);
        if ($requestSuffix !== '') {
            $suffixParts[] = $requestSuffix;
        }

        if ($this->previewTokenService->isPreviewMode()) {
            $token = (string)$this->previewTokenService->getTokenFromRequest();
            $suffixParts[] = 'preview_token:' . substr($token, 0, 16);
        }

        $suffixParts[] = 'file:' . md5($filename);

        $data->setData('suffix', implode('|', $suffixParts));
    }

    private function resolveThemeDataTheme(string $area): ?WelineTheme
    {
        try {
            $themeArea = \strtolower(\trim((string)(ThemeData::getCurrentArea() ?? '')));
            $theme = ThemeData::getCurrentTheme();
            if (($themeArea === '' || $themeArea === $area) && $theme instanceof WelineTheme && $theme->getId()) {
                return $theme;
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function resolveExplicitRequestTheme(string $area): ?WelineTheme
    {
        try {
            $request = ObjectManager::getInstance(Request::class);
        } catch (\Throwable) {
            return null;
        }
        if (!$request instanceof Request || !$this->shouldHonorExplicitThemeRequest($request)) {
            return null;
        }

        $requestArea = $this->resolveRequestArea($request, $area);
        $themeId = $this->resolveAreaThemeId($request, $area, $requestArea);
        if ($themeId <= 0) {
            return null;
        }

        try {
            /** @var WelineTheme $theme */
            $theme = ObjectManager::getInstance(WelineTheme::class);
            $theme->reset()->load($themeId);
            return $theme->getId() ? $theme : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveExplicitRequestSuffix(string $area): string
    {
        try {
            $request = ObjectManager::getInstance(Request::class);
        } catch (\Throwable) {
            return '';
        }
        if (!$request instanceof Request || !$this->shouldHonorExplicitThemeRequest($request)) {
            return '';
        }

        $requestArea = $this->resolveRequestArea($request, $area);
        $themeId = $this->resolveAreaThemeId($request, $area, $requestArea);
        if ($themeId <= 0) {
            return '';
        }

        return 'request_theme:' . $requestArea . ':' . $themeId;
    }

    private function resolveAreaThemeId(Request $request, string $area, string $requestArea): int
    {
        $themeId = 0;
        if ($area === 'backend') {
            $themeId = $this->readRequestInt($request, ['backend_theme_id']);
        } else {
            $themeId = $this->readRequestInt($request, ['frontend_theme_id', 'weline_theme_id']);
        }

        if ($themeId <= 0 && $requestArea === $area) {
            $themeId = $this->readRequestInt($request, ['theme_id', 'preview_theme_id']);
        }

        return $themeId;
    }

    /**
     * @param list<string> $keys
     */
    private function readRequestInt(Request $request, array $keys): int
    {
        foreach ($keys as $key) {
            $value = $this->readRequestValue($request, $key);
            if (!\is_scalar($value)) {
                continue;
            }
            $intValue = (int)$value;
            if ($intValue > 0) {
                return $intValue;
            }
        }

        return 0;
    }

    private function readRequestValue(Request $request, string $key): mixed
    {
        $value = null;
        try {
            $value = $request->getData($key);
        } catch (\Throwable) {
        }
        if ($value !== null && $value !== '') {
            return $value;
        }

        try {
            $value = $request->getParam($key, null);
        } catch (\Throwable) {
        }
        if ($value !== null && $value !== '') {
            return $value;
        }

        try {
            return $request->getGet($key, '');
        } catch (\Throwable) {
            return '';
        }
    }

    private function resolveRequestArea(Request $request, string $fallbackArea): string
    {
        $area = $this->readRequestValue($request, 'preview_area');
        if (!\is_scalar($area) || trim((string)$area) === '') {
            $area = $this->readRequestValue($request, 'editor_area');
        }
        if (!\is_scalar($area) || trim((string)$area) === '') {
            $area = $fallbackArea;
        }

        return strtolower(trim((string)$area)) === 'backend' ? 'backend' : 'frontend';
    }

    private function shouldHonorExplicitThemeRequest(Request $request): bool
    {
        try {
            $urlPath = strtolower(trim((string)$request->getUrlPath()));
            if ($urlPath !== '' && str_contains($urlPath, '/theme/')) {
                return true;
            }
        } catch (\Throwable) {
        }

        foreach ([
            'editor_mode',
            'preview_mode',
            'visual_editor',
            'preview_token',
            'preview_area',
            'editor_area',
        ] as $key) {
            $value = $this->readRequestValue($request, $key);
            if (\is_scalar($value) && trim((string)$value) !== '') {
                return true;
            }
        }

        return false;
    }
}
