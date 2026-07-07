<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Helper\Interface\ThemePathResolverInterface;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemeVirtualLayoutService;

/**
 * 模板文件获取观察者
 * 
 * 职责：在模板文件获取时解析主题文件路径
 * 遵循：单一职责原则 (SRP)、依赖倒置原则 (DIP)
 */
class TemplateFetchFile implements ObserverInterface
{
    private const FORCE_MODULE_THEME_SOURCE_KEY = '__weline_force_module_theme_source';

    /**
     * @var WelineTheme
     */
    private WelineTheme $welineTheme;
    
    /**
     * @var ThemePathResolverInterface
     */
    private ThemePathResolverInterface $themePathResolver;

    private ThemeContextService $themeContext;

    /**
     * 依赖注入：遵循依赖倒置原则 (DIP)
     * 
     * @param WelineTheme $welineTheme
     * @param ThemePathResolverInterface $themePathResolver
     */
    public function __construct(
        WelineTheme $welineTheme,
        ThemePathResolverInterface $themePathResolver,
        ThemeContextService $themeContext,
    )
    {
        $this->welineTheme = $welineTheme;
        $this->themePathResolver = $themePathResolver;
        $this->themeContext = $themeContext;
    }

    public function execute(Event &$event): void
    {
        /**
         * @var $fileData DataObject
         */
        $fileData = $event->getData('data');
        if (!$fileData instanceof DataObject) {
            return;
        }

        $module_file_path = $fileData->getData('filename');
        if (empty($module_file_path)) {
            return;
        }

        if ($this->shouldUseModuleThemeSource($fileData)) {
            return;
        }

        try {
            $runtimePath = ObjectManager::getInstance(ThemeVirtualLayoutService::class)
                ->mapRuntimeViewPath((string)$module_file_path);
            if ($runtimePath !== null) {
                $fileData->setData('filename', $runtimePath);
                return;
            }
        } catch (\Throwable) {
        }

        // 如果是编译文件路径（包含 com_ 前缀或已经是绝对路径且不在 app/code 或 app/design 下），不处理
        // 编译文件路径应该保持原样，不应该被主题系统处理
        if (strpos(basename($module_file_path), 'com_') === 0) {
            // 这是编译文件，不处理
            return;
        }
        
        // 如果路径已经是绝对路径，检查是否在编译目录下
        if (strpos($module_file_path, DS) === 0 || (strlen($module_file_path) > 2 && $module_file_path[1] === ':')) {
            // 绝对路径，检查是否在编译目录或模板编译目录下
            if (strpos($module_file_path, 'tpl' . DS) !== false || 
                strpos($module_file_path, 'template_compile' . DS) !== false ||
                strpos($module_file_path, 'generated' . DS . 'complicate' . DS) !== false) {
                // 这是编译目录下的文件，不处理
                return;
            }
        }

        # 开始分析主题路径（预览 / Session / 激活主题由 ThemeContextService 统一解析）
        $area = $this->resolveAreaFromPath($module_file_path);
        // 如果无法从路径检测到区域，使用预览上下文来确定区域
        // 这样即使模板路径不包含 theme/frontend 等标识，也能正确使用预览主题
        if ($area === null) {
            $area = $this->themeContext->getPreviewArea() ?? 'frontend';
        }
        $theme = $this->resolveExplicitRequestTheme($area)
            ?? $this->resolveTemplateScopedTheme($fileData, $area)
            ?? $this->themeContext->resolveTheme($area);
        if ($theme === null || !$theme->getId()) {
            try {
                $theme = $this->welineTheme->getActiveTheme($area);
            } catch (\Exception $exception) {
                throw new Exception(__('主题异常：') . $exception->getMessage());
            }
        }

        # 主题不存在且非开发环境
        if (PROD && (!$theme || !$theme->getId())) {
            $theme = $this->welineTheme->setData(Env::default_theme_DATA);
        }
        
        // 使用主题路径解析器解析文件路径（支持多级继承）
        $theme_file_path = $this->themePathResolver->resolveThemeFile($module_file_path, $theme);
        $theme_file_path = str_replace('\\', DS, $theme_file_path);
        $fileData->setData('filename', $theme_file_path);
    }

    private function resolveTemplateScopedTheme(DataObject $fileData, string $area): ?WelineTheme
    {
        $template = $fileData->getData('object');
        if ($template instanceof Template) {
            $themeData = $template->getData('theme');
            if (\is_array($themeData)) {
                $themeArea = \strtolower(\trim((string)($themeData['area'] ?? '')));
                $theme = $themeData['theme'] ?? null;
                if (($themeArea === '' || $themeArea === $area) && $theme instanceof WelineTheme && $theme->getId()) {
                    return $theme;
                }
            }
        }

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

    private function shouldUseModuleThemeSource(DataObject $fileData): bool
    {
        $template = $fileData->getData('object');
        return $template instanceof Template
            && (bool)$template->getData(self::FORCE_MODULE_THEME_SOURCE_KEY);
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

    private function readRequestInt(Request $request, array $keys): int
    {
        foreach ($keys as $key) {
            $value = $this->readRequestValue($request, (string)$key);
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

    /**
     * 从模板路径解析区域（frontend/backend）
     */
    private function resolveAreaFromPath(string $module_file_path): ?string
    {
        $pathNorm = str_replace('\\', '/', $module_file_path);
        if (str_contains($pathNorm, 'theme/frontend')) {
            return 'frontend';
        }
        if (str_contains($pathNorm, 'theme/backend')) {
            return 'backend';
        }
        return null;
    }
}
