<?php

declare(strict_types=1);

namespace Weline\MediaManager\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;

class Manager extends BackendController
{
    public function index()
    {
        $startPath = $this->request->getParam('startPath') ?? $this->request->getParam('path') ?? '';
        $connectorUrl = $this->_url->getBackendUrl('media/backend/connector');
        $this->assign('connector_url', $connectorUrl);
        $this->assign('start_path', $startPath);
        return $this->fetch('manager.phtml');
    }

    /**
     * 嵌入式管理器（iframe 调用）
     * @return string 渲染后的HTML内容
     */
    public function getIframe()
    {
        $connectorUrl = $this->_url->getBackendUrl('media/backend/connector');
        $params = $this->request->getParams();
        $this->assign('connector_url', $connectorUrl);
        $this->assign('is_iframe', true);
        $this->assign('target', $params['target'] ?? '');
        $this->assign('multi', $params['multi'] ?? '0');
        $this->assign('ext', $params['ext'] ?? '*');
        $this->assign('size', $params['size'] ?? '102400');
        $this->assign('setAttr', $params['setAttr'] ?? '');
        $this->assign('preview', $params['preview'] ?? '1');
        $this->assign('startPath', $params['startPath'] ?? $params['path'] ?? '');
        $this->assign('lockPath', $params['lockPath'] ?? '0');
        $this->assign('recommend_width', $params['recommend_width'] ?? '');
        $this->assign('recommend_height', $params['recommend_height'] ?? '');
        return $this->fetch('manager-iframe.phtml');
    }
}
