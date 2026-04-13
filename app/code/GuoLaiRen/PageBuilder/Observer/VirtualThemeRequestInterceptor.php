<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Observer;

use GuoLaiRen\PageBuilder\Model\VirtualTheme;
use GuoLaiRen\PageBuilder\Service\VirtualThemeContextService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;

/**
 * 虚拟主题请求拦截器
 *
 * 监听路由处理前事件，检测虚拟主题请求并注入 PageBuilder 自管的虚拟主题上下文。
 * 该上下文只服务于 PageBuilder 预览/编辑链路，不向 Weline/Theme 伪造 preview_theme。
 *
 * 优先级：显式 virtual_theme_id > PageBuilder 路由下的已持久化上下文
 */
class VirtualThemeRequestInterceptor implements ObserverInterface
{
    public function __construct(
        private readonly Request $request,
        private readonly VirtualThemeContextService $virtualThemeContext,
        private readonly VirtualTheme $virtualTheme
    ) {}

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $virtualThemeId = $this->detectVirtualThemeId();
        if ($virtualThemeId <= 0) {
            return;
        }

        $theme = $this->loadVirtualTheme($virtualThemeId);
        if (!$theme || !$theme->getId()) {
            return;
        }

        $context = $this->buildVirtualThemeContext($theme);
        $this->virtualThemeContext->persistContext($context, false);
        $this->injectVirtualThemeToRequest($context);
    }

    /**
     * 检测虚拟主题 ID
     * 优先级：URL 参数 > Session（仅在 AI 站点代理路由下）
     */
    private function detectVirtualThemeId(): int
    {
        $virtualThemeId = (int)$this->request->getParam('virtual_theme_id', 0);
        if ($virtualThemeId > 0) {
            return $virtualThemeId;
        }

        if (!$this->shouldUseStoredContextForCurrentRoute()) {
            $this->virtualThemeContext->clearContext();
            return 0;
        }

        $storedContext = $this->virtualThemeContext->getCurrentContext(false);
        return (int)($storedContext['virtual_theme_id'] ?? 0);
    }

    /**
     * 加载虚拟主题
     */
    private function loadVirtualTheme(int $virtualThemeId): ?VirtualTheme
    {
        $theme = clone $this->virtualTheme;
        $theme->clearData()->clearQuery();
        $theme->load($virtualThemeId);

        return $theme->getId() ? $theme : null;
    }

    /**
     * 构建虚拟主题上下文
     *
     * @return array{virtual_theme_id:int,theme_name:string,theme_path:string,area:string,session_id:int,shell:string}
     */
    private function buildVirtualThemeContext(VirtualTheme $theme): array
    {
        return [
            'virtual_theme_id' => (int)$theme->getId(),
            'theme_name' => (string)$theme->getName(),
            'theme_path' => (string)$theme->getPath(),
            'area' => 'frontend',
            'session_id' => (int)$theme->getSessionId(),
            'shell' => 'pagebuilder',
        ];
    }

    /**
     * 注入虚拟主题到 Request
     *
     * 仅注入 PageBuilder 自己消费的请求参数，不向 Theme 模块伪装 preview_theme。
     */
    private function injectVirtualThemeToRequest(array $context): void
    {
        $virtualThemeId = (int)$context['virtual_theme_id'];

        $this->request->setGet('virtual_theme_id', $virtualThemeId);
        $this->request->setGet('is_virtual_theme', '1');
        $this->request->setGet('virtual_theme_path', (string)$context['theme_path']);
        $this->request->setGet('theme_component_area', (string)($context['area'] ?? 'frontend'));

        if (!$this->shouldInjectFrontendRenderState()) {
            return;
        }

        $this->request->setGet('editor_area', 'frontend');
        $this->request->setGet('shell', 'pagebuilder');
    }

    private function shouldUseStoredContextForCurrentRoute(): bool
    {
        $currentPath = \strtolower(\trim((string)$this->request->getUrlPath()));
        if ($currentPath === '') {
            return false;
        }

        foreach ([
            '/pagebuilder/',
            '/ai-site-agent/',
            '/site-builder-agent/',
        ] as $pathMarker) {
            if (\str_contains($currentPath, $pathMarker)) {
                return true;
            }
        }

        return false;
    }

    private function shouldInjectFrontendRenderState(): bool
    {
        $currentPath = \strtolower(\trim((string)$this->request->getUrlPath()));
        if ($currentPath === '') {
            return false;
        }

        foreach ([
            '/pagebuilder/backend/page/',
            '/pagebuilder/backend/preview/',
            '/pagebuilder/backend/visual/',
            '/pagebuilder/backend/ai-site-agent/workspace-preview',
        ] as $pathMarker) {
            if (\str_contains($currentPath, $pathMarker)) {
                return true;
            }
        }

        return (string)$this->request->getParam('visual_editor', '') === '1';
    }
}
