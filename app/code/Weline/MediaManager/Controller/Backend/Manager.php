<?php

declare(strict_types=1);

namespace Weline\MediaManager\Controller\Backend;

use Weline\Backend\Api\View\BackendThemeConfigInterface;
use Weline\Framework\App\Controller\BackendController;

class Manager extends BackendController
{
    public function __construct(
        private readonly BackendThemeConfigInterface $backendThemeConfig,
    ) {
    }

    /**
     * 独立媒体管理器页面
     */
    public function index()
    {
        $startPath = $this->request->getParam('startPath') ?? $this->request->getParam('path') ?? '';
        $connectorUrl = $this->_url->getBackendUrl('media/backend/connector');
        $this->assign('connector_url', $connectorUrl);
        $this->assign('ai_draw_stream_url', $this->_url->getBackendUrl('media/backend/ai-draw/stream'));
        $this->assign('ai_draw_save_url', $this->_url->getBackendUrl('media/backend/ai-draw/save'));
        $this->assign('ai_draw_config_url', $this->_url->getBackendUrl('media/backend/ai-draw/config'));
        $this->assign('ai_draw_preview_url', $this->_url->getBackendUrl('media/backend/ai-draw/preview'));
        $this->assign('start_path', $startPath);
        $this->assign('is_iframe', '0');
        $themeState = $this->resolveIframeThemeState();
        $this->assign('theme_preference', $themeState['preference']);
        $this->assign('theme_mode', $themeState['resolved']);
        return $this->fetch('manager.phtml');
    }

    /**
     * 嵌入式管理器（iframe 调用）
     * 使用与 index 相同的模板，通过 is_iframe 参数区分模式
     * iframe 模式使用 blank 布局（无侧栏/顶栏，仅内容区）
     */
    public function getIframe()
    {
        $this->layoutType = 'default.blank';
        $params = $this->request->getParams();
        $connectorUrl = $this->_url->getBackendUrl('media/backend/connector');
        $startPath = $params['startPath'] ?? $params['path'] ?? '';
        $initialValue = trim((string) ($params['initialValue'] ?? ''));
        if ($initialValue !== '') {
            $firstPath = explode(',', $initialValue)[0];
            $firstPath = trim(str_replace('\\', '/', $firstPath));
            $firstPath = preg_replace('#^/pub/media/#', '', $firstPath);
            if ($firstPath !== '' && $startPath === '') {
                $dir = dirname($firstPath);
                if ($dir !== '.') {
                    $startPath = $dir . '/';
                }
            }
        }
        $this->assign('connector_url', $connectorUrl);
        $this->assign('ai_draw_stream_url', $this->_url->getBackendUrl('media/backend/ai-draw/stream'));
        $this->assign('ai_draw_save_url', $this->_url->getBackendUrl('media/backend/ai-draw/save'));
        $this->assign('ai_draw_config_url', $this->_url->getBackendUrl('media/backend/ai-draw/config'));
        $this->assign('ai_draw_preview_url', $this->_url->getBackendUrl('media/backend/ai-draw/preview'));
        $this->assign('start_path', $startPath);
        $this->assign('initial_value', $initialValue);
        $this->assign('is_iframe', '1');
        $this->assign('target', $params['target'] ?? '');
        $this->assign('multi', $params['multi'] ?? '0');
        $this->assign('ext', $params['ext'] ?? '*');
        $this->assign('size', $params['size'] ?? '102400');
        $this->assign('lock_path', $params['lockPath'] ?? '0');
        $themeState = $this->resolveIframeThemeState();
        $this->assign('theme_preference', $themeState['preference']);
        $this->assign('theme_mode', $themeState['resolved']);
        return $this->fetch('manager.phtml');
    }

    /** @return array{preference:string,resolved:string} */
    private function resolveIframeThemeState(): array
    {
        try {
            $preference = $this->backendThemeConfig->getOriginThemeConfig('theme-mode-switch');
            $preference = is_string($preference) && in_array($preference, ['system', 'light', 'dark'], true)
                ? $preference
                : 'system';

            return [
                'preference' => $preference,
                'resolved' => $preference === 'dark' ? 'dark' : 'light',
            ];
        } catch (\Throwable) {
            return ['preference' => 'system', 'resolved' => 'light'];
        }
    }
}
