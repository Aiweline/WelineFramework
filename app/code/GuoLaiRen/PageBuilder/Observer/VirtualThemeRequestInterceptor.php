<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Observer;

use GuoLaiRen\PageBuilder\Model\VirtualTheme;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Session;

/**
 * 虚拟主题请求拦截器
 *
 * 监听路由处理前事件，检测虚拟主题请求并注入虚拟主题上下文到 Request，
 * 让 Theme 模块的 PreviewContextService 能够识别并加载虚拟主题。
 *
 * 优先级：虚拟主题请求 > 普通主题预览
 */
class VirtualThemeRequestInterceptor implements ObserverInterface
{
    public const SESSION_KEY_VIRTUAL_THEME_CONTEXT = 'pagebuilder_virtual_theme_context';

    private Request $request;
    private Session $session;
    private VirtualTheme $virtualTheme;

    public function __construct(
        Request $request,
        Session $session,
        VirtualTheme $virtualTheme
    ) {
        $this->request = $request;
        $this->session = $session;
        $this->virtualTheme = $virtualTheme;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        // 1. 检测虚拟主题请求
        $virtualThemeId = $this->detectVirtualThemeId();
        if ($virtualThemeId <= 0) {
            return;
        }

        // 2. 加载虚拟主题
        $theme = $this->loadVirtualTheme($virtualThemeId);
        if (!$theme || !$theme->getId()) {
            return;
        }

        // 3. 构建虚拟主题上下文
        $context = $this->buildVirtualThemeContext($theme);

        // 4. 注入到 Request，伪装成普通主题预览
        $this->injectVirtualThemeToRequest($context);

        // 5. 持久化到 Session
        $this->persistVirtualThemeContext($context);
    }

    /**
     * 检测虚拟主题 ID
     * 优先级：URL 参数 > Session
     */
    private function detectVirtualThemeId(): int
    {
        // 优先从 URL 参数读取
        $virtualThemeId = (int)$this->request->getParam('virtual_theme_id', 0);
        if ($virtualThemeId > 0) {
            return $virtualThemeId;
        }

        // 回退到 Session
        $sessionContext = $this->session->getData(self::SESSION_KEY_VIRTUAL_THEME_CONTEXT);
        if (\is_array($sessionContext)) {
            return (int)($sessionContext['virtual_theme_id'] ?? 0);
        }

        return 0;
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
     * @return array{virtual_theme_id:int,theme_name:string,theme_path:string,area:string,session_id:int}
     */
    private function buildVirtualThemeContext(VirtualTheme $theme): array
    {
        return [
            'virtual_theme_id' => (int)$theme->getId(),
            'theme_name' => (string)$theme->getName(),
            'theme_path' => (string)$theme->getPath(),
            'area' => 'frontend', // 虚拟主题目前仅支持前台
            'session_id' => (int)$theme->getSessionId(),
        ];
    }

    /**
     * 注入虚拟主题到 Request
     *
     * 策略：将虚拟主题伪装成普通主题预览，设置 preview_theme, preview_area 等参数，
     * 让 Theme 模块的 PreviewContextService 能够识别。
     *
     * 注意：虚拟主题 ID 是 PageBuilder 自有表的 ID，不是 Weline\Theme 的 theme_id，
     * 因此需要特殊标记，避免 Theme 模块尝试加载不存在的主题。
     */
    private function injectVirtualThemeToRequest(array $context): void
    {
        $virtualThemeId = (int)$context['virtual_theme_id'];

        // 设置虚拟主题标记（供其他模块识别）
        $this->request->setGet('virtual_theme_id', $virtualThemeId);
        $this->request->setGet('is_virtual_theme', '1');

        // 伪装成普通主题预览（让 PreviewContextService 识别）
        // 注意：这里的 preview_theme 实际上是虚拟主题 ID，不是真实的 Weline\Theme ID
        // 需要在 Theme 模块中添加虚拟主题支持逻辑
        $this->request->setGet('preview_theme', $virtualThemeId);
        $this->request->setGet('preview_area', 'frontend');
        $this->request->setGet('frontend_theme_id', $virtualThemeId);
        $this->request->setGet('editor_area', 'frontend');
        $this->request->setGet('shell', 'pagebuilder');

        // 设置虚拟主题路径（供模板加载器使用）
        $this->request->setGet('virtual_theme_path', (string)$context['theme_path']);
    }

    /**
     * 持久化虚拟主题上下文到 Session
     */
    private function persistVirtualThemeContext(array $context): void
    {
        $this->session->setData(self::SESSION_KEY_VIRTUAL_THEME_CONTEXT, $context);
    }
}
