<?php

declare(strict_types=1);

namespace Weline\MediaManager\Controller\Backend;

use Weline\Backend\Api\View\BackendThemeConfigInterface;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\State;
use Weline\Framework\Ui\FormKey;
use Weline\MediaManager\Service\MediaAssetUploadService;

#[Acl('Weline_MediaManager::file_manager', '媒体管理器', 'image', '选择、上传并管理文件资源', 'Weline_Backend::media_group')]
class Manager extends BackendController
{
    public function __construct(
        private readonly BackendThemeConfigInterface $backendThemeConfig,
        private readonly FileAssetLibraryInterface $fileAssets,
        private readonly FormKey $formKey,
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
        $this->assign('title', __('媒体管理器'));
        $themeState = $this->resolveIframeThemeState();
        $this->assign('theme_preference', $themeState['preference']);
        $this->assign('theme_mode', $themeState['resolved']);
        $this->assign('locale_code', $this->resolvePickerLocale());
        $this->assign('require_image_usage', '0');
        $this->assignConnectorSecurity();
        $this->assign('size', (string)MediaAssetUploadService::MAX_ASSET_UPLOAD_BYTES);
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
        $this->suppressPageChromeForPicker();
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
        $this->assign('size', $params['size'] ?? (string)MediaAssetUploadService::MAX_ASSET_UPLOAD_BYTES);
        $this->assign('lock_path', $params['lockPath'] ?? '0');
        $themeState = $this->resolveIframeThemeState();
        $this->assign('theme_preference', $themeState['preference']);
        $this->assign('theme_mode', $themeState['resolved']);
        $this->assign('locale_code', $this->resolvePickerLocale());
        $this->assign('require_image_usage', filter_var(
            $params['usage'] ?? $params['requireImageUsage'] ?? false,
            FILTER_VALIDATE_BOOL,
        ) ? '1' : '0');
        $this->assignConnectorSecurity();
        return $this->fetch('manager.phtml');
    }

    private function assignConnectorSecurity(): void
    {
        $this->assign('connector_form_key', $this->formKey->getKey(
            $this->_url->getBackendUrl('media/backend/connector'),
        ));
    }

    private function resolvePickerLocale(): string
    {
        $requested = trim((string)$this->request->getParam('locale_code', $this->request->getParam('locale', '')));
        if ($requested !== '') {
            try {
                return $this->fileAssets->normalizeLocale($requested);
            } catch (\Throwable) {
            }
        }

        return $this->fileAssets->normalizeLocale((string)State::getLangLocal());
    }

    /**
     * 弹窗/iframe 选择器：隐藏 blank 布局的页面大标题（否则会露出模块名）。
     */
    private function suppressPageChromeForPicker(): void
    {
        $pickerTitle = (string)__('选择媒体');
        // 先写 title，避免后续 assign(meta) 时用模块名回填 controller_title。
        $this->assign('title', $pickerTitle);
        $meta = $this->getTemplate()->getData('meta');
        $meta = is_array($meta) ? $meta : [];
        $meta['showPageHeader'] = false;
        $meta['showMessages'] = false;
        $meta['title'] = $pickerTitle;
        $meta['controller_title'] = $pickerTitle;
        $this->assign('meta', $meta);
        $this->assign('layoutShowPageHeader', false);
        $this->assign('layoutShowMessages', false);
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
