<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\App\State;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
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
        $explicitRequest = $this->resolveExplicitRequestContext($area);
        $theme = $this->resolveExplicitRequestTheme($explicitRequest)
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

        if ($explicitRequest['theme_id'] > 0) {
            $suffixParts[] = 'request_theme:' . $explicitRequest['area'] . ':' . $explicitRequest['theme_id'];
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

    /** @param array{area: string, theme_id: int} $context */
    private function resolveExplicitRequestTheme(array $context): ?WelineTheme
    {
        $themeId = $context['theme_id'];
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

    /** @return array{area: string, theme_id: int} */
    private function resolveExplicitRequestContext(string $area): array
    {
        $fallback = ['area' => $area, 'theme_id' => 0];
        try {
            $request = ObjectManager::getInstance(Request::class);
        } catch (\Throwable) {
            return $fallback;
        }
        $values = [];
        foreach ([
            'editor_mode', 'preview_mode', 'visual_editor', 'preview_token', 'preview_area', 'editor_area',
            'backend_theme_id', 'frontend_theme_id', 'weline_theme_id', 'theme_id', 'preview_theme_id',
        ] as $key) {
            $value = $this->readRequestValue($request, $key);
            $values[$key] = \is_scalar($value) ? $value : null;
        }

        $explicitMode = false;
        foreach (['editor_mode', 'preview_mode', 'visual_editor', 'preview_token', 'preview_area', 'editor_area'] as $key) {
            if (\trim((string)$values[$key]) !== '') {
                $explicitMode = true;
                break;
            }
        }
        $requestArea = \trim((string)$values['preview_area']);
        if ($requestArea === '') {
            $requestArea = \trim((string)$values['editor_area']);
        }
        $requestArea = \strtolower($requestArea !== '' ? $requestArea : $area) === 'backend' ? 'backend' : 'frontend';
        $idKeys = $area === 'backend' ? ['backend_theme_id'] : ['frontend_theme_id', 'weline_theme_id'];
        if ($requestArea === $area) {
            $idKeys = \array_merge($idKeys, ['theme_id', 'preview_theme_id']);
        }
        $themeId = 0;
        foreach ($idKeys as $key) {
            if ((int)$values[$key] > 0) {
                $themeId = (int)$values[$key];
                break;
            }
        }
        $explicit = ['area' => $requestArea, 'theme_id' => $themeId];

        // 请求路径和显式预览参数决定主题选择；文件名、当前 ThemeData 与 Token 验证不进入此缓存。
        // getUrlPath 命中自身缓存前仍构造完整范围 hash，卡片循环只需按相同输入解释一次。
        $builder = static function () use ($request, $explicitMode, $explicit, $fallback): array {
            $path = \strtolower(\trim($request->getUrlPath()));
            return $explicitMode || ($path !== '' && \str_contains($path, '/theme/')) ? $explicit : $fallback;
        };
        try {
            $key = \serialize([
                $area, $request->getUri(), $values,
                RequestContext::scopeIdentity()?->canonicalKey(),
                RequestContext::getWelineUserLang(), RequestContext::getWelineUserCurrency(),
                State::getRequestLanguageOverride(),
            ]);
            return ObjectManager::getInstance(StorefrontScopeHotCache::class)->rememberForRequest(
                'theme.template_explicit_request',
                $key,
                $builder,
            );
        } catch (\Throwable) {
            // 缓存或缓存键不可用时仍执行原读取，不将正常 /theme/ 路由误判为普通请求。
            try {
                return $builder();
            } catch (\Throwable) {
            }
            // 路径暂不可读时沿用显式参数回退；remember 不写异常，下次调用仍可重试。
            return $explicitMode ? $explicit : $fallback;
        }
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

}
