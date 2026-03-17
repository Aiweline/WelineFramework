<?php

declare(strict_types=1);

namespace Weline\MediaManager\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;

class Manager extends BackendController
{
    /**
     * 独立媒体管理器页面；当请求带 iframe=1 时按嵌入式 iframe 模式渲染（避免依赖未注册的 media/backend/manager/iframe 路由）
     */
    public function index()
    {
        $isIframe = $this->request->getParam('iframe') === '1' || $this->request->getParam('isIframe') === '1';
        if ($isIframe) {
            $this->layoutType = null;
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
            $this->assign('start_path', $startPath);
            $this->assign('initial_value', $initialValue);
            $this->assign('is_iframe', '1');
            $this->assign('target', $params['target'] ?? '');
            $this->assign('multi', $params['multi'] ?? '0');
            $this->assign('ext', $params['ext'] ?? '*');
            $this->assign('size', $params['size'] ?? '102400');
            $this->assign('lock_path', $params['lockPath'] ?? '0');
            return $this->fetch('manager.phtml');
        }
        $startPath = $this->request->getParam('startPath') ?? $this->request->getParam('path') ?? '';
        $connectorUrl = $this->_url->getBackendUrl('media/backend/connector');
        $this->assign('connector_url', $connectorUrl);
        $this->assign('start_path', $startPath);
        $this->assign('is_iframe', '0');
        return $this->fetch('manager.phtml');
    }

    /**
     * 嵌入式管理器（iframe 调用）
     * 使用与 index 相同的模板，通过 is_iframe 参数区分模式
     * iframe 模式不使用后端布局，直接渲染模板
     */
    public function getIframe()
    {
        $this->layoutType = null;  // iframe 模式禁用布局
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
        $this->assign('start_path', $startPath);
        $this->assign('initial_value', $initialValue);
        $this->assign('is_iframe', '1');
        $this->assign('target', $params['target'] ?? '');
        $this->assign('multi', $params['multi'] ?? '0');
        $this->assign('ext', $params['ext'] ?? '*');
        $this->assign('size', $params['size'] ?? '102400');
        $this->assign('lock_path', $params['lockPath'] ?? '0');
        return $this->fetch('manager.phtml');
    }
}
